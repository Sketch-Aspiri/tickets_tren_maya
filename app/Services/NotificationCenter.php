<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\WorkItemAssigned;
use App\Notifications\WorkItemCommented;
use App\Notifications\WorkItemRejected;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Centro de notificaciones dentro del sistema: mensajes de chat sin leer (se derivan de `chat_reads`, no se
 * guardan como notificaciones para no inundar la tabla) mas los avisos guardados en `notifications`
 * (asignaciones, cuentas pendientes, correos entrantes).
 */
final class NotificationCenter
{
    public function __construct(private readonly ChatService $chat) {}

    /**
     * @return array{chat: int, alerts: int, total: int}
     */
    public function summary(User $user): array
    {
        $chat = $this->chat->unreadTotal($user);
        $alerts = $user->unreadNotifications()->count();

        return ['chat' => $chat, 'alerts' => $alerts, 'total' => $chat + $alerts];
    }

    /**
     * Destino al abrir un aviso. El acceso final lo decide la Policy del destino (404 si ya no lo puede ver).
     */
    public function targetUrl(DatabaseNotification $notification): string
    {
        $data = (array) $notification->data;

        return match ($data['type'] ?? null) {
            WorkItemAssigned::TYPE => ($data['kind'] ?? null) === 'activity'
                ? route('activities.show', (int) $data['id'])
                : route('tickets.show', (int) $data['id']),
            WorkItemCommented::TYPE, WorkItemRejected::TYPE => (($data['kind'] ?? null) === 'activity'
                ? route('activities.show', (int) $data['id'])
                : route('tickets.show', (int) $data['id'])),
            'user_pending_approval' => route('users.show', (int) $data['user_id']),
            'incoming_email_pending' => route('incoming-emails.index'),
            default => route('notifications.index'),
        };
    }
}
