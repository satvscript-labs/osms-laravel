<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Services\WhatsApp\WhatsAppScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WA-01 — the send job re-checks the runtime kill-switch, so a message never goes
 * out from a store that stopped being a ready Automated store between scheduling
 * and sending.
 * WA-02 — non-auth failures retry instead of dying silently; auth failures stop.
 * WA-03 — template body-param order comes from one published spec.
 */
class Phase51WhatsAppSendHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Send Optical', 'tax_id' => 'G', 'address' => 'Agra']);
    }

    private function readyConfig(): WhatsAppConfig
    {
        return WhatsAppConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'mode' => 'automated',
            'enabled' => true, 'verified_at' => now(),
            'phone_number_id' => '123', 'access_token' => 'tok',
        ]);
    }

    private function scheduledMessage(): WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'event' => 'order_ready', 'to_phone' => '+919876500000',
            'status' => 'scheduled', 'dedupe_key' => 'x:order_ready',
            'payload' => ['body_params' => ['A', 'B']],
        ]);
    }

    // ---- WA-01 ----

    public function test_send_is_cancelled_when_the_kill_switch_is_off_at_send_time(): void
    {
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        $this->readyConfig();
        $message = $this->scheduledMessage();

        // The freeze lands between scheduling and the sweep.
        config(['whatsapp.automated_enabled' => false]);

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppGateway::class));

        $message->refresh();
        $this->assertSame('cancelled', $message->status);
        $this->assertNull($message->dedupe_key, 'The dedupe slot must be freed for a later attempt.');
        $this->assertNull($message->sent_at);
    }

    public function test_a_ready_store_still_sends(): void
    {
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        $this->readyConfig();
        $message = $this->scheduledMessage();

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppGateway::class));

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->sent_at);
    }

    // ---- WA-02 ----

    public function test_an_auth_failure_stops_immediately_and_flags_the_store(): void
    {
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        $config = $this->readyConfig();
        $message = $this->scheduledMessage();

        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway {
            public function sendTemplate(WhatsAppConfig $config, string $toE164, string $templateName, string $languageCode, array $variables): string
            {
                throw WhatsAppException::auth('Token expired');
            }
        });

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppGateway::class));

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertTrue($config->fresh()->needs_attention, 'An auth failure must flag the store.');
    }

    public function test_a_transient_failure_is_rethrown_so_the_queue_retries(): void
    {
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        $this->readyConfig();
        $message = $this->scheduledMessage();

        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway {
            public function sendTemplate(WhatsAppConfig $config, string $toE164, string $templateName, string $languageCode, array $variables): string
            {
                throw new WhatsAppException('Rate limited');
            }
        });

        $this->expectException(WhatsAppException::class);
        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppGateway::class));
    }

    // ---- WA-03 ----

    public function test_template_param_spec_is_published_and_ordered(): void
    {
        $this->assertSame(
            ['customer name', 'store name', 'order number', 'order total', 'balance due'],
            WhatsAppScheduler::paramSpec('order_placed')
        );
        $this->assertCount(4, WhatsAppScheduler::paramSpec('order_ready'));
        $this->assertCount(3, WhatsAppScheduler::paramSpec('order_delivered'));
        $this->assertCount(2, WhatsAppScheduler::paramSpec('birthday'));
    }

    public function test_scheduled_body_params_match_the_published_spec(): void
    {
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        $this->readyConfig();

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Asha', 'phone' => '+91 9876500001',
        ]);
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => 'ready_for_pickup', 'fulfillment_type' => 'special',
            'subtotal' => 1000, 'total_amount' => 1000, 'advance_paid' => 200,
        ]);

        $row = app(WhatsAppScheduler::class)->handle($order->load('customer', 'tenant'), 'order_ready');

        $this->assertNotNull($row);
        $this->assertCount(count(WhatsAppScheduler::paramSpec('order_ready')), $row->payload['body_params']);
        $this->assertSame('Asha', $row->payload['body_params'][0]);   // customer name first
        $this->assertSame('Send Optical', $row->payload['body_params'][1]); // store name second
    }
}
