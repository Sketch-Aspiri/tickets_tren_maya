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
 * Aviso solo por Web Push: cambió el estado de un ticket o actividad en la que participas (el rechazo en revisión
 * tiene su propio aviso, WorkItemRejected). Solo escalares; se envía tras el commit.
 */
final class WorkItemStatusChanged extends Notification implements ShouldQueue
{
    use BuildsWebPush;
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly int $itemId,
        public readonly string $folio,
        public readonly string $statusLabel,
        public readonly string $actorName,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPush(
            __('push.status.title'),
            __('push.status.body', ['folio' => $this->folio, 'status' => $this->statusLabel, 'by' => $this->actorName]),
            $this->itemUrl($this->kind, $this->itemId),
            'status-'.$this->kind.'-'.$this->itemId,
        );
    }
}
