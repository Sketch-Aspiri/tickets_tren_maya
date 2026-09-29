<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Sube un archivo a OneDrive/SharePoint con Microsoft Graph usando credenciales de aplicacion (client credentials).
 * Las URLs son fijas en el codigo; tenant, drive y ruta vienen de la configuracion del servidor y se validan
 * con listas de caracteres permitidos antes de usarlos (nada llega de usuarios).
 */
final class MicrosoftGraphClient implements CloudFileUploader
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const UPLOAD_URL = 'https://graph.microsoft.com/v1.0/drives/%s/root:/%s:/content';

    private const TOKEN_CACHE_KEY = 'cloud-sync:graph-token';

    private const RETRIES = 2;

    public function upload(string $contents, string $contentType): void
    {
        $config = $this->validatedConfig();
        // `drive_id` ya paso la lista blanca [A-Za-z0-9!_-]: va literal (los ids de SharePoint empiezan con `b!`).
        $url = sprintf(self::UPLOAD_URL, $config['drive_id'], $this->encodePath($config['path']));

        $response = $this->send(fn () => Http::withToken($this->token($config))
            ->timeout((int) config('tickets.cloud_sync.timeout_seconds'))
            ->withBody($contents, $contentType)
            ->put($url));

        if ($response->status() === 401) {
            // Token revocado o vencido antes de tiempo: se descarta y se reintenta una sola vez.
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->send(fn () => Http::withToken($this->token($config))
                ->timeout((int) config('tickets.cloud_sync.timeout_seconds'))
                ->withBody($contents, $contentType)
                ->put($url));
        }

        if (! $response->successful()) {
            throw new CloudSyncException('Microsoft Graph rechazo la subida (HTTP '.$response->status().').');
        }
    }

    /**
     * @param  array{tenant_id: string, client_id: string, client_secret: string}  $config
     */
    private function token(array $config): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->send(fn () => Http::asForm()
            ->timeout((int) config('tickets.cloud_sync.timeout_seconds'))
            ->post(sprintf(self::TOKEN_URL, rawurlencode($config['tenant_id'])), [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default',
            ]));

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new CloudSyncException('No se pudo obtener el token de Microsoft (HTTP '.$response->status().').');
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Reintenta ante 429 y 5xx (respetando `Retry-After`, acotado) y ante fallas de red.
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException) {
                if ($attempt >= self::RETRIES) {
                    throw new CloudSyncException('No hubo conexion con Microsoft Graph.');
                }

                $this->pause($attempt, null);

                continue;
            }

            $retryable = $response->status() === 429 || $response->serverError();

            if (! $retryable || $attempt >= self::RETRIES) {
                return $response;
            }

            $this->pause($attempt, $response->header('Retry-After'));
        }
    }

    private function pause(int $attempt, ?string $retryAfter): void
    {
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : 2 ** ($attempt + 1);

        Sleep::for(min(30, max(1, $seconds)))->seconds();
    }

    /**
     * @return array{tenant_id: string, client_id: string, client_secret: string, drive_id: string, path: string}
     */
    private function validatedConfig(): array
    {
        $config = (array) config('tickets.cloud_sync');
        $values = [];

        foreach (['tenant_id', 'client_id', 'client_secret', 'drive_id', 'path'] as $key) {
            $value = trim((string) ($config[$key] ?? ''));

            if ($value === '') {
                throw new CloudSyncException('Falta la configuracion CLOUD_SYNC_'.strtoupper($key).'.');
            }

            $values[$key] = $value;
        }

        if (! preg_match('/^[A-Za-z0-9.\-]{1,100}$/', $values['tenant_id']) || ! preg_match('/^[A-Za-z0-9\-]{1,100}$/', $values['client_id'])) {
            throw new CloudSyncException('CLOUD_SYNC_TENANT_ID o CLOUD_SYNC_CLIENT_ID tienen un formato invalido.');
        }

        if (! preg_match('/^[A-Za-z0-9!_\-]{1,200}$/', $values['drive_id'])) {
            throw new CloudSyncException('CLOUD_SYNC_DRIVE_ID tiene un formato invalido.');
        }

        $segments = explode('/', trim($values['path'], '/'));

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || ! preg_match('/^[\p{L}\p{N} ._()\-]{1,120}$/u', $segment)) {
                throw new CloudSyncException('CLOUD_SYNC_PATH tiene un formato invalido.');
            }
        }

        if (! str_ends_with(strtolower($values['path']), '.xlsx')) {
            throw new CloudSyncException('CLOUD_SYNC_PATH debe terminar en .xlsx.');
        }

        return $values;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
