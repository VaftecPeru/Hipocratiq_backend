<?php

namespace App\Console\Commands;

use App\Services\Mail\MailInfrastructureService;
use Illuminate\Console\Command;
use Throwable;

class ProbeSmtp extends Command
{
    protected $signature = 'mail:probe {recipient? : Correo destinatario para la prueba SMTP}';

    protected $description = 'Envía un correo de prueba y valida la configuración SMTP de HIPOCRATIQ.';

    public function handle(MailInfrastructureService $mail): int
    {
        $status = $mail->status();

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Driver', (string) ($status['driver'] ?? '')],
                ['Host', (string) ($status['host'] ?? '')],
                ['Puerto', (string) ($status['port'] ?? '')],
                ['Autenticación', (string) ($status['auth_mode'] ?? '')],
                ['From', (string) ($status['from_address'] ?? '')],
                ['EHLO', (string) ($status['ehlo_domain'] ?? '')],
            ],
        );

        try {
            $traceId = $mail->probe($this->argument('recipient'));

            $this->info('SMTP operativo. Trace ID: '.$traceId);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Prueba SMTP fallida: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
