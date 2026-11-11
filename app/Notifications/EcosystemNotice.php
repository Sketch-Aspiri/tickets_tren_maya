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
 * Aviso que manda el Dashboard por el puente (decision 91): aparece en «Notificaciones» y llega al celular por Web
 * Push. Los textos los redacta el Dashboard y son cortos (titulo <= 60, cuerpo <= 120) y sin datos sensibles
 * (folio, estacion y accion): se ven en la pantalla bloqueada. La URL ya viene normalizada por EcosystemUrl.
 * Solo escalares.
 */
final class EcosystemNotice extends Notification implements ShouldQueue
{
    use BuildsWebPush;
    use Queueable;

    public const TYPE = 'ecosystem_notice';

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $source,
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
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'source' => $this->source,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPush($this->title, $this->body, $this->url, 'eco-'.substr(sha1($this->url), 0, 12));
    }
}
