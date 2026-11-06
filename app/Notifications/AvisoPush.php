<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso de ejemplo por Web Push (llega al celular aunque la app esté cerrada). Solo lleva escalares para
 * serializar limpio en la cola; la URL debe ser relativa al sitio (el service worker ignora otros orígenes).
 */
final class AvisoPush extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $titulo,
        public readonly string $cuerpo,
        public readonly string $url = '/',
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->titulo)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->body($this->cuerpo)
            ->data(['url' => $this->url]);
    }
}
