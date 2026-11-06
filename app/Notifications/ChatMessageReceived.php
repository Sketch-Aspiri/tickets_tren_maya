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
 * Aviso solo por Web Push: mensaje nuevo en un chat. No incluye el texto del mensaje (privacidad en pantalla
 * bloqueada); `$channelName` es el nombre del equipo, o null en un chat 1 a 1.
 */
final class ChatMessageReceived extends Notification implements ShouldQueue
{
    use BuildsWebPush;
    use Queueable;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $senderName,
        public readonly ?string $channelName,
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
        $body = $this->channelName === null
            ? __('push.chat.body_direct', ['by' => $this->senderName])
            : __('push.chat.body_team', ['by' => $this->senderName, 'team' => $this->channelName]);

        return $this->webPush(__('push.chat.title'), $body, route('chat.index', [], false), 'chat-'.$this->conversationId);
    }
}
