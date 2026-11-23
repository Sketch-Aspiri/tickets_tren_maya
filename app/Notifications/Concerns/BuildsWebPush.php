<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Notifications\Channels\PortalChannel;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Mensaje Web Push común. La URL siempre es relativa al sitio (el service worker ignora otros orígenes) y los
 * textos nunca llevan contenido de usuario libre (comentarios, mensajes): aparecen en la pantalla bloqueada.
 */
trait BuildsWebPush
{
    /**
     * Canal del push: el de este sitio, o el PORTAL cuando ECOSISTEMA_PUSH_DESTINO=portal (el portal entrega el mismo
     * mensaje en su PWA). La campana (`database`) no cambia.
     *
     * @return class-string
     */
    protected function pushChannel(): string
    {
        return config('ecosistema.push.destino') === 'portal' ? PortalChannel::class : WebPushChannel::class;
    }

    protected function webPush(string $title, string $body, string $url, string $tag): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->body($body)
            ->tag($tag)
            ->data(['url' => $url]);
    }

    protected function itemUrl(string $kind, int $id): string
    {
        return $kind === 'activity'
            ? route('activities.show', $id, false)
            : route('tickets.show', $id, false);
    }
}
