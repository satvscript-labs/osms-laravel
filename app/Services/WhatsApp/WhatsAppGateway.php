<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConfig;

/**
 * FT-WhatsApp — the send abstraction (Automated mode).
 *
 * A single method sends a pre-approved template message. Concrete drivers
 * (Meta Cloud API, or the Log driver for local/tests) are bound by
 * `config('whatsapp.driver')` in AppServiceProvider.
 */
interface WhatsAppGateway
{
    /**
     * Send a template message.
     *
     * @param  array<int,string>  $variables  ordered body parameters ({{1}}, {{2}}, …)
     * @return string  the provider's message id
     *
     * @throws WhatsAppException on a provider error
     */
    public function sendTemplate(
        WhatsAppConfig $config,
        string $toE164,
        string $templateName,
        string $languageCode,
        array $variables,
    ): string;
}
