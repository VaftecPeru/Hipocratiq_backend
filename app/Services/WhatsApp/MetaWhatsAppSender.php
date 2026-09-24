<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MetaWhatsAppSender implements WhatsAppSender
{
    public function configured(): bool
    {
        return filled(config('integrations.whatsapp.access_token'))
            && filled(config('integrations.whatsapp.phone_number_id'))
            && filled(config('integrations.whatsapp.api_version'));
    }

    public function sendTemplate(string $to, string $template, string $language, array $bodyParameters): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Cloud API no está configurado.');
        }

        $baseUrl = rtrim((string) config('integrations.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('integrations.whatsapp.api_version'), '/');
        $phoneNumberId = (string) config('integrations.whatsapp.phone_number_id');

        $response = Http::withToken((string) config('integrations.whatsapp.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('integrations.whatsapp.timeout_seconds', 15))
            ->post($baseUrl.'/'.$version.'/'.$phoneNumberId.'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $language],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $value): array => ['type' => 'text', 'text' => $value],
                            $bodyParameters,
                        ),
                    ]],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp Cloud API respondió con estado '.$response->status().'.');
        }

        $messageId = $response->json('messages.0.id');

        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('WhatsApp Cloud API no devolvió un identificador de mensaje.');
        }

        return $messageId;
    }
}
