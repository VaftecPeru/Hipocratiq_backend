<?php

namespace App\Services\Mail;

use App\Notifications\SmtpProbeNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class MailInfrastructureService
{
    public function configured(): bool
    {
        if ((string) config('mail.default') !== 'smtp') {
            return false;
        }

        $host = trim((string) config('mail.mailers.smtp.host'));
        $port = (int) config('mail.mailers.smtp.port');
        $fromAddress = trim((string) config('mail.from.address'));
        $authMode = (string) config('mail.infrastructure.auth_mode', 'credentials');

        if ($host === '' || $port <= 0 || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return match ($authMode) {
            'credentials' => filled(config('mail.mailers.smtp.username'))
                && filled(config('mail.mailers.smtp.password')),
            'ip_relay' => true,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $configured = $this->configured();
        $authMode = (string) config('mail.infrastructure.auth_mode', 'credentials');

        return [
            'configured' => $configured,
            'driver' => (string) config('mail.default', 'log'),
            'host' => $this->safeHost(),
            'port' => (int) config('mail.mailers.smtp.port', 0),
            'auth_mode' => $authMode,
            'from_address' => (string) config('mail.from.address', ''),
            'ehlo_domain' => (string) config('mail.mailers.smtp.local_domain', ''),
            'timeout_seconds' => (int) config('mail.mailers.smtp.timeout', 15),
            'message' => $configured
                ? ($authMode === 'ip_relay'
                    ? 'SMTP configurado para relay autorizado por IP.'
                    : 'SMTP configurado con credenciales protegidas por entorno.')
                : 'SMTP no está completamente configurado.',
        ];
    }

    public function probe(?string $recipient = null): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('SMTP no está completamente configurado.');
        }

        $recipient ??= config('mail.infrastructure.probe_recipient');

        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Configura un destinatario de prueba válido.');
        }

        $traceId = (string) Str::uuid();

        Notification::route('mail', $recipient)->notifyNow(
            new SmtpProbeNotification($traceId),
        );

        return $traceId;
    }

    private function safeHost(): ?string
    {
        $host = trim((string) config('mail.mailers.smtp.host'));

        return $host === '' ? null : $host;
    }
}
