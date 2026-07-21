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

    /** WA-02 — transient provider failures get retried instead of dying silently. */
    public int $tries = 3;

    public function __construct(public string $messageId)
    {
    }

    /** WA-02 — spaced retries (seconds); the cron drains the queue each minute. */
    public function backoff(): array
    {
        return [60, 300];
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
            $this->stopWith($message, 'No WhatsApp configuration for this store.');
            return;
        }

        // Load the store name for drivers that surface it (e.g. the log driver).
        $config->setRelation('tenant', $config->tenant);

        // WA-01 — re-check the runtime kill-switch at SEND time, not just at schedule
        // time. The Automated freeze (or a store disconnecting / being flagged for
        // attention) can land between scheduling and this run; a message must never
        // go out from a store that is no longer in a genuinely ready Automated state.
        if (! $config->isReady()) {
            $this->stopWith($message, 'Automated messaging is not active for this store — not sent.', 'cancelled');
            return;
        }

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

            // A credential failure isn't transient — flag the store so future
            // events fall back to the manual pill instead of failing silently.
            if ($e->isAuthError()) {
                $config->update(['needs_attention' => true]);
                $this->stopWith($message, $e->getMessage());

                return;
            }

            // WA-02 — anything else may be transient (rate limit, provider blip).
            // Let the queue retry; only record a permanent failure on the last try.
            if ($this->attempts() >= $this->tries) {
                $this->stopWith($message, $e->getMessage());

                return;
            }

            throw $e; // re-queue with backoff
        }
    }

    /**
     * Close a message out without sending. Clearing `dedupe_key` frees the
     * (order, event) slot so a legitimate later attempt can be scheduled (DATA-01).
     */
    private function stopWith(WhatsAppMessage $message, string $error, string $status = 'failed'): void
    {
        $message->update([
            'status' => $status,
            'error' => $error,
            'dedupe_key' => null,
        ]);
    }
}
