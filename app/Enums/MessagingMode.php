<?php

namespace App\Enums;

/**
 * The resolved WhatsApp messaging mode for a tenant + event.
 *
 * `Off`/`Manual`/`Automated` map to the stored `whatsapp_configs.mode`;
 * `ManualFallback` is a *derived* state — the tenant chose Automated but the
 * Cloud API isn't ready (no/unverified creds, or flagged for attention), so we
 * degrade to the manual pill rather than silently dropping the message.
 */
enum MessagingMode: string
{
    case Off = 'off';
    case Manual = 'manual';
    case Automated = 'automated';
    case ManualFallback = 'manual_fallback';

    /** Manual + fallback both render the human "Send on WhatsApp" pill. */
    public function showsManualPill(): bool
    {
        return $this === self::Manual || $this === self::ManualFallback;
    }

    /** Only true Automated schedules a server-side send. */
    public function isAutomated(): bool
    {
        return $this === self::Automated;
    }

    /** Fallback additionally shows a "finish WhatsApp setup" hint. */
    public function needsSetupHint(): bool
    {
        return $this === self::ManualFallback;
    }
}
