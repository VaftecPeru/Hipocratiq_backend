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
        $driver = (string) config('mail.default');
        $fromAddress = trim((string) config('mail.from.address'));

        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // The AWS default credential chain can use an EC2 task/instance role.
        // Static configuration cannot prove IAM permission or email delivery.
        if (in_array($driver, ['ses', 'ses-v2'], true)) {
            return trim((string) config('services.ses.region')) !== '';
        }

        if ($driver !== 'smtp') {
            return false;
        }

        $host = trim((string) config('mail.mailers.smtp.host'));
        $port = (int) config('mail.mailers.smtp.port');
        $authMode = (string) config('mail.infrastructure.auth_mode', 'credentials');

        if ($host === '' || $port <= 0) {
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
        $driver = (string) config('mail.default', 'log');
        $isSes = in_array($driver, ['ses', 'ses-v2'], true);
        $authMode = (string) config('mail.infrastructure.auth_mode', 'credentials');

        return [
            'configured' => $configured,
            'driver' => $driver,
            'host' => $isSes ? null : $this->safeHost(),
            'port' => $isSes ? null : (int) config('mail.mailers.smtp.port', 0),
            'auth_mode' => $isSes ? 'aws_credential_chain' : $authMode,
            'region' => $isSes ? (string) config('services.ses.region', '') : null,
            'from_address' => (string) config('mail.from.address', ''),
            'ehlo_domain' => $isSes ? null : (string) config('mail.mailers.smtp.local_domain', ''),
            'timeout_seconds' => $isSes ? null : (int) config('mail.mailers.smtp.timeout', 15),
            'message' => $isSes
                ? ($configured
                    ? 'Amazon SES seleccionado. El envío y la recepción reales requieren verificación independiente.'
                    : 'Amazon SES sin región o remitente válido.')
                : ($configured
                    ? ($authMode === 'ip_relay'
                        ? 'SMTP configurado para relay autorizado por IP.'
                        : 'SMTP configurado con credenciales protegidas por entorno.')
                    : 'SMTP no está completamente configurado.'),
        ];
    }

    public function probe(?string $recipient = null): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('El transporte de correo no está completamente configurado.');
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
