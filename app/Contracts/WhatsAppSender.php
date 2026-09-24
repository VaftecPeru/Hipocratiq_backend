<?php

namespace App\Contracts;

interface WhatsAppSender
{
    public function configured(): bool;

    /** @param list<string> $bodyParameters */
    public function sendTemplate(string $to, string $template, string $language, array $bodyParameters): string;
}
