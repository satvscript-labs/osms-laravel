<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Support\Phone;

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
        $exists = WhatsAppMessage::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('event', $event)
            ->whereIn('status', ['scheduled', 'sent'])
            ->exists();
        if ($exists) {
            return null;
        }

        return WhatsAppMessage::create([
            'tenant_id' => $order->tenant_id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'event' => $event,
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
    }

    /**
     * Ordered template body parameters per event. A store's approved template must
     * be authored to this parameter order (documented in the tech artifact §12).
     *
     * @return array<int,string>
     */
    private function bodyParams(WhatsAppConfig $config, Order $order, string $event): array
    {
        $name = $order->customer?->name ?? 'there';
        $store = $order->tenant?->store_name ?? 'our store';
        $num = strtoupper(substr((string) $order->id, 0, 8));
        $total = '₹ ' . number_format((float) $order->total_amount, 2);
        $balance = '₹ ' . number_format((float) $order->balance_due, 2);

        return match ($event) {
            'order_placed' => [$name, $store, $num, $total, $balance],
            'order_ready' => [$name, $store, $num, $balance],
            'order_delivered' => [$name, $store, $num],
            'birthday' => [$name, $store],
            default => [$name],
        };
    }
}
