<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Aviso dentro del sistema: te asignaron un ticket o una actividad. Solo lleva escalares (serializa limpio en
 * la cola) y se guarda tras el commit para no avisar de una asignacion que luego se revierte.
 */
final class WorkItemAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'work_item_assigned';

    public function __construct(
        public readonly string $kind,
        public readonly int $itemId,
        public readonly string $folio,
        public readonly string $title,
        public readonly string $role,
        public readonly string $assignedByName,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'kind' => $this->kind,
            'id' => $this->itemId,
            'folio' => $this->folio,
            'title' => $this->title,
            'role' => $this->role,
            'assigned_by' => $this->assignedByName,
        ];
    }
}
