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
 * Aviso dentro del sistema: un ticket o una actividad tuya fue rechazada en la revisión y regresó a En proceso. El motivo
 * (comentario obligatorio) queda en el historial del registro; aquí solo viajan escalares.
 */
final class WorkItemRejected extends Notification implements ShouldQueue
{
    use BuildsWebPush;
    use Queueable;

    public const TYPE = 'work_item_rejected';

    public function __construct(
        public readonly string $kind,
        public readonly int $itemId,
        public readonly string $folio,
        public readonly string $title,
        public readonly string $reviewerName,
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
            'by' => $this->reviewerName,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPush(
            __('push.rejected.title'),
            __('push.rejected.body', ['folio' => $this->folio, 'by' => $this->reviewerName]),
            $this->itemUrl($this->kind, $this->itemId),
            'status-'.$this->kind.'-'.$this->itemId,
        );
    }
}
