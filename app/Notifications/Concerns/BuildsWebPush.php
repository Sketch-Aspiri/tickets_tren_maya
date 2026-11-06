<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use NotificationChannels\WebPush\WebPushMessage;

/**
 * Mensaje Web Push común. La URL siempre es relativa al sitio (el service worker ignora otros orígenes) y los
 * textos nunca llevan contenido de usuario libre (comentarios, mensajes): aparecen en la pantalla bloqueada.
 */
trait BuildsWebPush
{
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
