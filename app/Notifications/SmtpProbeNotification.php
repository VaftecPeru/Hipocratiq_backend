<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SmtpProbeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $traceId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Prueba SMTP HIPOCRATIQ')
            ->greeting('Validación de correo transaccional')
            ->line('El servidor HIPOCRATIQ logró entregar este mensaje mediante el transporte SMTP configurado.')
            ->line('Trace ID: '.$this->traceId)
            ->line('Si recibiste este mensaje, la conectividad SMTP de salida está operativa.');
    }
}
