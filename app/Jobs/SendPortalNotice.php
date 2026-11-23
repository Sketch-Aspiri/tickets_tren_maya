<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ecosystem\BridgeSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Manda un aviso al PORTAL por el puente firmado (POST /interno/subsistemas/notificaciones): el portal lo guarda en su
 * centro de notificaciones y lo entrega por Web Push (PWA del portal). Va en cola con reintentos para no retrasar la
 * acción que lo originó; `ref` viaja igual en cada intento y el portal no avisa dos veces si el primer intento sí llegó
 * pero la respuesta no volvió. Nunca registra secretos ni el cuerpo.
 */
class SendPortalNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /**
     * @param  list<string>  $emails
     */
    public function __construct(
        public readonly array $emails,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $ref,
        public readonly ?string $module = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(BridgeSignature $signature): void
    {
        if (! $signature->portalConfigured()) {
            return;
        }

        $path = '/interno/subsistemas/notificaciones';

        try {
            $json = json_encode(array_filter([
                'correos' => $this->emails,
                'titulo' => $this->title,
                'cuerpo' => $this->body,
                'url' => $this->url,
                'ref' => $this->ref,
                'modulo' => $this->module,
            ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR);

            $response = Http::acceptJson()->timeout(10)->connectTimeout(3)->withoutRedirecting()
                ->withHeaders($signature->portalHeaders('POST', $path, [], $json))
                ->withBody($json, 'application/json')
                ->post((string) config('ecosistema.puente.portal.url').$path);
        } catch (JsonException) {
            return;
        } catch (Throwable) {
            // Mensaje genérico: nada de secretos ni de la respuesta.
            throw new RuntimeException('El portal no recibió el aviso.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('El portal no aceptó el aviso (HTTP '.$response->status().').');
        }
    }
}
