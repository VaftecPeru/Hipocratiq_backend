<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminUserMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $messageSubject,
        public readonly string $messageBody,
        public readonly User $sender,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->messageSubject)
            ->greeting("Hola {$notifiable->name}")
            ->line($this->messageBody)
            ->line("Mensaje enviado por {$this->sender->name}, equipo de soporte de Hipocratio.");
    }
}
