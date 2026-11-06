<?php

declare(strict_types=1);

namespace App\Services\SheetInbox;

use App\Models\SpreadsheetSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Inicio de sesión con una cuenta Microsoft (personal) para LEER un Excel de OneDrive: flujo de autorización con
 * código + PKCE (delegado, `Files.Read.All` de solo lectura y `offline_access` para renovar). Las URLs son fijas;
 * el `tenant` se valida con una lista estricta. Los tokens se guardan cifrados en la fuente y nunca salen en
 * mensajes de error.
 */
final class MicrosoftPersonalAuth
{
    public const SCOPES = 'offline_access Files.Read.All User.Read';

    private const LOGIN_HOST = 'https://login.microsoftonline.com/';

    private const GRAPH_ME = 'https://graph.microsoft.com/v1.0/me?$select=displayName,userPrincipalName,mail';

    private const REFRESH_MARGIN_SECONDS = 60;

    private const TIMEOUT_SECONDS = 20;

    public function isConfigured(): bool
    {
        return trim((string) config('tickets.sheet_inbox.client_id')) !== '' && $this->tenant() !== null;
    }

    public function authorizationUrl(string $state, string $codeVerifier): string
    {
        $this->assertConfigured();

        return self::LOGIN_HOST.$this->tenant().'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => (string) config('tickets.sheet_inbox.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => self::challenge($codeVerifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Canjea el código por tokens y los guarda (cifrados) en la fuente junto con el nombre de la cuenta.
     */
    public function connect(SpreadsheetSource $source, string $code, string $codeVerifier): void
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);

        $this->store($source, $tokens);
        $source->forceFill(['account_name' => $this->accountName($tokens['access_token']), 'last_error' => null])->save();
    }

    /**
     * Token de acceso vigente; lo renueva con el refresh token si está por vencer (Microsoft puede rotarlo).
     */
    public function accessToken(SpreadsheetSource $source): string
    {
        if (! $source->isConnected()) {
            throw SheetInboxException::because('sheet_sources.errors.not_connected');
        }

        if ($source->access_token && $source->token_expires_at?->isAfter(now()->addSeconds(self::REFRESH_MARGIN_SECONDS))) {
            return (string) $source->access_token;
        }

        $tokens = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $source->refresh_token,
        ]);
        $this->store($source, $tokens);

        return $tokens['access_token'];
    }

    public function disconnect(SpreadsheetSource $source): void
    {
        $source->forceFill(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'account_name' => null])->save();
    }

    public static function newVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     */
    private function tokenRequest(array $grant): array
    {
        $this->assertConfigured();

        $payload = [
            'client_id' => (string) config('tickets.sheet_inbox.client_id'),
            'scope' => self::SCOPES,
            ...$grant,
        ];
        $secret = trim((string) config('tickets.sheet_inbox.client_secret'));

        if ($secret !== '') {
            $payload['client_secret'] = $secret;
        }

        $response = $this->send(fn () => Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)
            ->post(self::LOGIN_HOST.$this->tenant().'/oauth2/v2.0/token', $payload));

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            // `invalid_grant` = el permiso se revocó o el refresh token venció: hay que volver a conectar la cuenta.
            throw SheetInboxException::because($response->json('error') === 'invalid_grant'
                ? 'sheet_sources.errors.reconnect'
                : 'sheet_sources.errors.token_failed', ['status' => $response->status()]);
        }

        $refresh = $response->json('refresh_token');

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_in' => max(60, (int) $response->json('expires_in', 3600)),
        ];
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int}  $tokens
     */
    private function store(SpreadsheetSource $source, array $tokens): void
    {
        $source->forceFill([
            'access_token' => $tokens['access_token'],
            // Si Microsoft no rota el refresh token se conserva el anterior.
            'refresh_token' => $tokens['refresh_token'] ?? $source->refresh_token,
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ])->save();
    }

    private function accountName(string $accessToken): ?string
    {
        $response = $this->send(fn () => Http::withToken($accessToken)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->get(self::GRAPH_ME));

        if (! $response->successful()) {
            return null;
        }

        $name = $response->json('mail') ?: $response->json('userPrincipalName') ?: $response->json('displayName');

        return is_string($name) ? mb_substr($name, 0, 255) : null;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw SheetInboxException::because('sheet_sources.errors.network');
        }
    }

    private function redirectUri(): string
    {
        return route('sheet-sources.callback');
    }

    private function tenant(): ?string
    {
        $tenant = strtolower(trim((string) config('tickets.sheet_inbox.tenant', 'consumers')));

        return preg_match('/^(consumers|common|organizations|[0-9a-f-]{36}|[a-z0-9-]+\.onmicrosoft\.com)$/', $tenant) === 1 ? $tenant : null;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw SheetInboxException::because('sheet_sources.errors.not_configured');
        }
    }
}
