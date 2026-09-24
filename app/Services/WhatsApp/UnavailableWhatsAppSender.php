<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppSender;
use RuntimeException;

final class UnavailableWhatsAppSender implements WhatsAppSender
{
    public function configured(): bool
    {
        return false;
    }

    public function sendTemplate(string $to, string $template, string $language, array $bodyParameters): string
    {
        throw new RuntimeException('WhatsApp no está configurado.');
    }
}
