<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportTicket $ticket) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ticket {$this->ticket->ticket_id} recibido")
            ->greeting('Solicitud de soporte registrada')
            ->line("Tu solicitud {$this->ticket->ticket_id} fue registrada correctamente.")
            ->line("Clínica: {$this->ticket->clinic?->name}")
            ->line("Asunto: {$this->ticket->subject}")
            ->line('Conserva el código del ticket para cualquier seguimiento.');
    }
}
