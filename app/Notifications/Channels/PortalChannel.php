<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Jobs\SendPortalNotice;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Canal que sustituye a WebPushChannel cuando ECOSISTEMA_PUSH_DESTINO=portal: en vez de mandar el push desde este
 * sitio, pasa el mismo mensaje (título, cuerpo y URL) al PORTAL por el puente firmado y es el PWA del portal quien lo
 * entrega. Los textos ya son cortos y sin contenido libre (se ven en la pantalla bloqueada); aquí solo se limpian y
 * recortan a los límites del portal. Un fallo nunca debe romper la acción que originó el aviso.
 */
final class PortalChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $email = mb_strtolower((string) $notifiable->email);

        if ($email === '' || str_ends_with($email, '.invalid')) {
            return;
        }

        try {
            $message = $notification->toWebPush($notifiable, $notification)->toArray();
            $title = $this->clean((string) ($message['title'] ?? ''), 60);
            $body = $this->clean((string) ($message['body'] ?? ''), 120);

            if ($title === '') {
                return;
            }

            SendPortalNotice::dispatch(
                [$email],
                $title,
                $body !== '' ? $body : $title,
                $this->absoluteUrl((string) ($message['data']['url'] ?? '/')),
                (string) Str::uuid(),
                'tickets',
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Sin caracteres de control ni de formato Unicode (dirección del texto, ancho cero, separadores), recortado. */
    private function clean(string $text, int $max): string
    {
        return Str::limit(trim((string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $text)), $max, '');
    }

    /** El portal recibe URL absolutas de los sistemas: las rutas de este sitio se completan con su dirección pública. */
    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, '/')
            ? rtrim((string) config('ecosistema.urls.tickets'), '/').$url
            : $url;
    }
}
