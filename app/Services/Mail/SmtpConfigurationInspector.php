<?php

namespace App\Services\Mail;

final class SmtpConfigurationInspector
{
    /** @return array<string, mixed> */
    public function status(): array
    {
        $mailer = (string) config('mail.default', 'log');
        $smtp = (array) config('mail.mailers.smtp', []);
        $fromAddress = (string) config('mail.from.address', '');

        $configured = $mailer === 'smtp'
            && filled($smtp['host'] ?? null)
            && filled($smtp['port'] ?? null)
            && filled($smtp['username'] ?? null)
            && filled($smtp['password'] ?? null)
            && filled($fromAddress);

        return [
            'configured' => $configured,
            'mailer' => $mailer,
            'scheme' => $smtp['scheme'] ?? null,
            'host' => $smtp['host'] ?? null,
            'port' => isset($smtp['port']) ? (int) $smtp['port'] : null,
            'username' => $smtp['username'] ?? null,
            'from_address' => $fromAddress !== '' ? $fromAddress : null,
            'ehlo_domain' => $smtp['local_domain'] ?? null,
            'timeout_seconds' => isset($smtp['timeout']) ? (int) $smtp['timeout'] : null,
        ];
    }

    public function configured(): bool
    {
        return (bool) ($this->status()['configured'] ?? false);
    }
}
