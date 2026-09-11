<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $ttlMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Código de verificación Hipocratiq')
            ->greeting('Hola, '.$notifiable->name)
            ->line('Tu código de verificación es:')
            ->line($this->code)
            ->line("Este código expira en {$this->ttlMinutes} minutos.")
            ->line('Si no solicitaste este código, puedes ignorar este correo.');
    }
}
