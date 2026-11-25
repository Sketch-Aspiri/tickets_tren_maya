<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\BuildsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso al jefe de zona: hay una cuenta nueva pendiente de aprobacion.
 * Solo lleva escalares (no el modelo) para serializar limpio en la cola.
 */
final class NewUserPendingApproval extends Notification implements ShouldQueue
{
    use BuildsWebPush;
    use Queueable;

    public function __construct(
        public readonly int $pendingUserId,
        public readonly string $pendingUserName,
        public readonly string $pendingUserEmail,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail', $this->pushChannel()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.new_user_pending.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.new_user_pending.line', [
                'name' => $this->pendingUserName,
                'email' => $this->pendingUserEmail,
            ]))
            ->action(__('notifications.new_user_pending.action'), route('users.show', $this->pendingUserId));
    }

    /** Sin nombre ni correo: se lee en la pantalla bloqueada y lo escribió quien se registró. */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPush(
            __('push.user_pending.title'),
            __('push.user_pending.body'),
            route('users.show', $this->pendingUserId, false),
            'user-pending-'.$this->pendingUserId,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'user_pending_approval',
            'user_id' => $this->pendingUserId,
            'name' => $this->pendingUserName,
            'email' => $this->pendingUserEmail,
        ];
    }
}
