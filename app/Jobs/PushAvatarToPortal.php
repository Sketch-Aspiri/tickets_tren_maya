<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\AvatarService;
use App\Services\Ecosystem\BridgeSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Avisa al portal de la foto vigente (o su retiro) para que la reparta a los demas sistemas. Siempre manda lo ULTIMO
 * guardado, asi que un reintento tardio nunca pisa una foto mas nueva. Si el portal no conoce a la persona (404) no
 * se reintenta. Nunca registra secretos ni la imagen.
 */
class PushAvatarToPortal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 180, 900];
    }

    public function __construct(public readonly int $userId)
    {
        $this->afterCommit();
    }

    public function handle(BridgeSignature $signature, AvatarService $avatars): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $signature->portalConfigured() || str_ends_with((string) $user->email, '.invalid')) {
            return;
        }

        $bytes = $avatars->bytes($user);
        $body = ['email' => mb_strtolower((string) $user->email)] + ($bytes === null ? [] : ['imagen' => base64_encode($bytes)]);
        $method = $bytes === null ? 'DELETE' : 'PUT';
        $path = '/interno/subsistemas/avatar';

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $response = Http::acceptJson()->timeout(15)->connectTimeout(3)->withoutRedirecting()
                ->withHeaders($signature->portalHeaders($method, $path, [], $json))
                ->withBody($json, 'application/json')
                ->send($method, (string) config('ecosistema.puente.portal.url').$path);
        } catch (JsonException) {
            return;
        } catch (Throwable) {
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);

            return;
        }

        if ($response->serverError()) {
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
        }
    }
}
