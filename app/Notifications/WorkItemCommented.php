<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Aviso dentro del sistema: alguien comentó un ticket o una actividad en la que participas. Solo escalares; se guarda
 * tras el commit. No incluye el texto del comentario (contenido no confiable): se lee en el registro.
 */
final class WorkItemCommented extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'work_item_commented';

    public function __construct(
        public readonly string $kind,
        public readonly int $itemId,
        public readonly string $folio,
        public readonly string $title,
        public readonly string $authorName,
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
            'by' => $this->authorName,
        ];
    }
}
