<?php

namespace App\Console\Commands;

use App\Services\Auth\AwsOtpReadiness;
use Illuminate\Console\Command;

class CheckAwsOtp extends Command
{
    protected $signature = 'otp:aws-check';

    protected $description = 'Valida que HIPOCRATIQ esté listo para activar OTP con AWS End User Messaging Notify.';

    public function handle(AwsOtpReadiness $readiness): int
    {
        $status = $readiness->status();

        $this->table(
            ['Control', 'Estado'],
            collect($status['checks'])
                ->map(fn (bool $passed, string $check): array => [
                    $check,
                    $passed ? 'OK' : 'FALLA',
                ])
                ->values()
                ->all(),
        );

        $this->table(
            ['Parámetro', 'Valor'],
            [
                ['Provider activo', (string) ($status['details']['provider'] ?? '')],
                ['Región', (string) ($status['details']['region'] ?? '')],
                ['Configuration set', (string) ($status['details']['configuration_set'] ?? 'opcional/no definido')],
                ['TTL', (string) $status['details']['ttl_minutes'].' min'],
                ['Máximo de intentos', (string) $status['details']['max_attempts']],
            ],
        );

        if ($status['ready'] !== true) {
            $this->error('OTP AWS no está listo. Corrige los controles en FALLA antes de continuar.');

            return self::FAILURE;
        }

        if ($status['active'] !== true) {
            $this->warn('La configuración está lista, pero OTP_PHONE_PROVIDER todavía no es aws_notify.');

            return self::FAILURE;
        }

        $this->info('OTP AWS listo para ejecutar la prueba UAT de envío y validación.');

        return self::SUCCESS;
    }
}
