<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * FT-WhatsApp — the single funnel every order event flows through.
 *
 * `handle()` is idempotent and mode-aware: it schedules a Cloud API send only
 * when the store is in a *ready* Automated mode; for Off / Manual / fallback it
 * no-ops (the view renders the manual pill instead). Call it AFTER the order's
 * DB transaction commits so a rolled-back write never schedules a message.
 */
class WhatsAppScheduler
{
    /**
     * Schedule an automated message for an order event, or no-op.
     * Returns the created row, or null when nothing was scheduled.
     */
    public function handle(Order $order, string $event): ?WhatsAppMessage
    {
        $order->loadMissing('customer', 'tenant');

        $config = $order->tenant?->whatsappConfig;
        if (! $config) {
            return null; // no config → Manual default → nothing to schedule
        }

        // Only a genuinely-ready Automated store schedules a server send. Off,
        // Manual, and ManualFallback are handled by the view's manual pill.
        if (! $config->modeFor($event)->isAutomated()) {
            return null;
        }

        $to = Phone::e164($order->customer?->phone, $config->default_country_code);
        if ($to === null) {
            return null; // no usable number
        }

        // Idempotency: never a second live row for the same order+event. A `sent`
        // row is a permanent tombstone; a `cancelled` one (reverted) does not block.
        // The app-level check is the fast path; the DB unique index on `dedupe_key`
        // (DATA-01) is the race-proof backstop below.
        $exists = WhatsAppMessage::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('event', $event)
            ->whereIn('status', ['scheduled', 'sent'])
            ->exists();
        if ($exists) {
            return null;
        }

        try {
            return WhatsAppMessage::create([
                'tenant_id' => $order->tenant_id,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'event' => $event,
                // Set only for live rows; cleared when the row is cancelled/failed so
                // a legitimate re-send is allowed. The unique index makes the
                // check-then-insert race impossible.
                'dedupe_key' => $order->id . ':' . $event,
                'channel' => 'cloud_api',
                'to_phone' => $to,
                'template_name' => $config->templateName($event),
                'payload' => [
                    'body_params' => $this->bodyParams($config, $order, $event),
                    'text' => $config->renderOrderMessage($order, $event),
                ],
                'status' => 'scheduled',
                'scheduled_for' => now()->addSeconds((int) config('whatsapp.send_grace_seconds', 60)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the race and already scheduled this one.
            return null;
        }
    }

    /**
     * WA-03 — the SINGLE source of truth for template body-parameter order. A store's
     * approved Meta template must declare its {{1}}, {{2}}… variables in exactly this
     * order for the event, or the send fails at Meta with a mismatched-parameter error.
     *
     * Keep this in step with the tech artifact and the settings help text: it is what
     * `bodyParams()` builds from and what `paramSpec()` publishes to the UI/docs.
     */
    private const BODY_PARAM_SPEC = [
        'order_placed'    => ['customer name', 'store name', 'order number', 'order total', 'balance due'],
        'order_ready'     => ['customer name', 'store name', 'order number', 'balance due'],
        'order_delivered' => ['customer name', 'store name', 'order number'],
        'birthday'        => ['customer name', 'store name'],
    ];

    /**
     * The ordered, human-readable parameter list a tenant's template must match for
     * an event (for the settings screen and the integration docs).
     *
     * @return array<int,string>
     */
    public static function paramSpec(string $event): array
    {
        return self::BODY_PARAM_SPEC[$event] ?? ['customer name'];
    }

    /**
     * Ordered template body parameters per event, built to match BODY_PARAM_SPEC.
     *
     * @return array<int,string>
     */
    private function bodyParams(WhatsAppConfig $config, Order $order, string $event): array
    {
        $values = [
            'customer name' => $order->customer?->name ?? 'there',
            'store name'    => $order->tenant?->store_name ?? 'our store',
            'order number'  => strtoupper(substr((string) $order->id, 0, 8)),
            'order total'   => '₹ ' . number_format((float) $order->total_amount, 2),
            'balance due'   => '₹ ' . number_format((float) $order->balance_due, 2),
        ];

        return array_map(
            fn (string $label) => (string) ($values[$label] ?? ''),
            self::paramSpec($event),
        );
    }
}
