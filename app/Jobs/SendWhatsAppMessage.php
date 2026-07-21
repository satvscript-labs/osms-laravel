<?php

namespace App\Jobs;

use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * FT-WhatsApp — send one scheduled message via the Cloud API gateway.
 *
 * Dispatched by the `whatsapp:dispatch-due` sweep. Guards on the row still being
 * `scheduled` so a revert/cancel (or an already-sent row) is a safe no-op — this
 * is what makes "never send twice" and the undo window hold even after dispatch.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $messageId)
    {
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        $message = WhatsAppMessage::withoutGlobalScopes()->find($this->messageId);

        // Reverted, cancelled, or already sent between sweep and run → do nothing.
        if (! $message || $message->status !== 'scheduled') {
            return;
        }

        $config = WhatsAppConfig::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->first();

        if (! $config) {
            $message->update(['status' => 'failed', 'error' => 'No WhatsApp configuration for this store.']);
            return;
        }

        // Load the store name for drivers that surface it (e.g. the log driver).
        $config->setRelation('tenant', $config->tenant);

        try {
            $providerId = $gateway->sendTemplate(
                $config,
                $message->to_phone,
                (string) $message->template_name,
                $config->tpl_lang ?? 'en',
                $message->payload['body_params'] ?? [],
            );

            $message->update([
                'status' => 'sent',
                'sent_at' => now(),
                'provider_message_id' => $providerId,
            ]);
        } catch (WhatsAppException $e) {
            $message->increment('attempts');
            // Clear dedupe_key on failure so a later retry can be scheduled
            // (matches the app-level guard, which only blocks on scheduled/sent).
            $message->update(['status' => 'failed', 'error' => $e->getMessage(), 'dedupe_key' => null]);

            // A credential failure isn't transient — flag the store so future
            // events fall back to the manual pill instead of failing silently.
            if ($e->isAuthError()) {
                $config->update(['needs_attention' => true]);
            }
        }
    }
}
