<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a jefe de zona y administrador: hay correos nuevos en la bandeja "Correos entrantes" en espera
 * de revisión humana. Se dispara UNA vez por corrida de `emails:ingest` (nunca por correo individual),
 * solo cuando esa corrida creó al menos un `IncomingEmail`. El coordinador, aunque sí puede actuar sobre
 * la bandeja completa, no recibe este aviso: no hay equipo conocido todavía al momento de la ingesta.
 * Solo lleva escalares (no el modelo) para serializar limpio en la cola.
 */
final class NewIncomingEmailToReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $pendingCount) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.new_incoming_email.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.new_incoming_email.line', ['count' => $this->pendingCount]))
            ->action(__('notifications.new_incoming_email.action'), route('incoming-emails.index'));
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'incoming_email_pending',
            'count' => $this->pendingCount,
        ];
    }
}
