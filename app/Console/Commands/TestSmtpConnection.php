<?php

namespace App\Console\Commands;

use App\Services\Mail\SmtpConfigurationInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestSmtpConnection extends Command
{
    protected $signature = 'mail:test-smtp {recipient? : Dirección de destino; por defecto usa MAIL_FROM_ADDRESS}';

    protected $description = 'Envía un correo real de prueba usando la configuración SMTP activa.';

    public function handle(SmtpConfigurationInspector $inspector): int
    {
        $status = $inspector->status();

        if (! $status['configured']) {
            $this->error('SMTP no está configurado completamente.');
            $this->line('Mailer: '.($status['mailer'] ?? '—'));
            $this->line('Host: '.($status['host'] ?? '—'));
            $this->line('Puerto: '.($status['port'] ?? '—'));
            $this->line('Usuario: '.($status['username'] ?? '—'));
            $this->line('Remitente: '.($status['from_address'] ?? '—'));

            return self::FAILURE;
        }

        $recipient = $this->argument('recipient') ?: $status['from_address'];

        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('La dirección de destino no es válida.');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'Prueba SMTP HIPOCRATIQ enviada desde el VPS. Si recibiste este mensaje, la conexión SMTP está operativa.',
                function ($message) use ($recipient): void {
                    $message->to($recipient)
                        ->subject('HIPOCRATIQ · Prueba SMTP');
                },
            );
        } catch (Throwable $exception) {
            $this->error('Falló el envío SMTP: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Correo SMTP enviado correctamente a '.$recipient.'.');
        $this->line('Host: '.$status['host'].':'.$status['port']);
        $this->line('Usuario SMTP: '.$status['username']);
        $this->line('Remitente: '.$status['from_address']);

        return self::SUCCESS;
    }
}
