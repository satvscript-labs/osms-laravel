<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;

/**
 * FT-WhatsApp — sweep due scheduled messages onto the queue.
 *
 * Runs every minute via the scheduler cron (there is no persistent worker on
 * shared hosting). Any message whose grace window has elapsed and is still
 * `scheduled` (i.e. not reverted) is dispatched to SendWhatsAppMessage.
 */
class DispatchDueWhatsApp extends Command
{
    protected $signature = 'whatsapp:dispatch-due';

    protected $description = 'Dispatch WhatsApp messages whose scheduled send time has arrived';

    public function handle(): int
    {
        // Runs without an authenticated user — TenantScope no-ops, so this sees
        // every store's due rows (a global sweep, as intended).
        $due = WhatsAppMessage::withoutGlobalScopes()->due()->get();

        foreach ($due as $message) {
            SendWhatsAppMessage::dispatch($message->id);
        }

        $this->info("Dispatched {$due->count()} WhatsApp message(s).");

        return self::SUCCESS;
    }
}
