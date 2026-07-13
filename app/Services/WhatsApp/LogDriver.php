<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default driver for local development and tests: it never touches the
 * network. It writes the message it *would* send to the log (so you can watch
 * the automated flow end-to-end without a Meta account) and returns a fake
 * provider message id. Set WHATSAPP_DRIVER=meta to use the real Cloud API.
 */
class LogDriver implements WhatsAppGateway
{
    public function sendTemplate(
        WhatsAppConfig $config,
        string $toE164,
        string $templateName,
        string $languageCode,
        array $variables,
    ): string {
        Log::info('[WhatsApp:log] template message', [
            'store' => $config->tenant?->store_name,
            'to' => $toE164,
            'template' => $templateName,
            'lang' => $languageCode,
            'variables' => $variables,
        ]);

        return 'log-' . Str::uuid();
    }
}
