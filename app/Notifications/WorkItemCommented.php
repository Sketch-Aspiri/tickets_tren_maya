<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\BuildsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso dentro del sistema: alguien comentó un ticket o una actividad en la que participas. Solo escalares; se guarda
 * tras el commit. No incluye el texto del comentario (contenido no confiable): se lee en el registro.
 */
final class WorkItemCommented extends Notification implements ShouldQueue
{
    use BuildsWebPush;
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
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
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

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPush(
            __('push.commented.title'),
            __('push.commented.body', ['folio' => $this->folio, 'by' => $this->authorName]),
            $this->itemUrl($this->kind, $this->itemId),
            'comment-'.$this->kind.'-'.$this->itemId,
        );
    }
}
