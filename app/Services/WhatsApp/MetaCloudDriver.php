<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Http;

/**
 * The Meta WhatsApp Business Cloud API driver (Automated mode, production).
 *
 * Inert on local (WHATSAPP_DRIVER defaults to `log`); this is the real send path
 * used once a store connects its own number. It posts a template message to the
 * store's phone number id with the store's own bearer token — so Meta bills the
 * tenant directly (Option A: BYO credentials).
 */
class MetaCloudDriver implements WhatsAppGateway
{
    public function sendTemplate(
        WhatsAppConfig $config,
        string $toE164,
        string $templateName,
        string $languageCode,
        array $variables,
    ): string {
        if (blank($config->phone_number_id) || blank($config->access_token)) {
            throw WhatsAppException::auth('WhatsApp credentials are missing.');
        }

        $version = config('whatsapp.graph_version', 'v21.0');
        $url = "https://graph.facebook.com/{$version}/{$config->phone_number_id}/messages";

        // Cloud API wants digits without the leading "+".
        $to = ltrim($toE164, '+');

        $components = [];
        if (! empty($variables)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($variables),
                ),
            ];
        }

        $response = Http::withToken($config->access_token)
            ->acceptJson()
            ->retry(2, 200, throw: false) // transient only; 4xx handled below
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $languageCode],
                    'components' => $components,
                ],
            ]);

        if ($response->successful()) {
            return (string) ($response->json('messages.0.id') ?? 'sent');
        }

        // 401/403 → credential/permission problem the store must fix.
        $message = (string) ($response->json('error.message') ?? 'WhatsApp send failed.');
        if (in_array($response->status(), [401, 403], true)) {
            throw WhatsAppException::auth($message);
        }

        throw new WhatsAppException($message);
    }
}
