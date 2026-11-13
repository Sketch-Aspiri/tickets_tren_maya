<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Services\Ecosystem\BridgeClient;
use Illuminate\Support\Facades\Cache;

/**
 * Pasarela del MCP de Tickets hacia las herramientas del Dashboard (decision 97). Pide por el puente firmado la lista
 * de herramientas (se guarda unos minutos) y ejecuta una por una. No decide permisos: eso lo hace cada cliente MCP
 * (`McpClient::dashboardAllows`) antes de llegar aqui. Nunca lanza: ante cualquier falla devuelve un resultado de error
 * con un texto para la IA, sin detalles internos.
 *
 * La respuesta del Dashboard solo viaja protegida por TLS (la firma cubre lo que Tickets envia, no lo que recibe), asi
 * que NO se le cree lo que decide permisos: que una herramienta escriba lo decide esta clase con una lista fija de las
 * de solo lectura; cualquier otra, incluida una que el Dashboard agregue manana, se trata como de escritura.
 */
final class DashboardGateway
{
    /** Herramientas del Dashboard que se sabe que SOLO leen. Todo lo demas exige el nivel `write`. */
    public const READ_ONLY_TOOLS = [
        'consultar_seccion',
        'consultar_personal',
        'consultar_asistencia',
        'buscar_documentos',
        'catalogo_dashboard',
        'asistencia_pendiente_hoy',
    ];

    private const CACHE_KEY = 'ecosistema:ia:herramientas-dashboard';

    private const FAILURE_KEY = 'ecosistema:ia:herramientas-dashboard:falla';

    private const MAX_TOOLS = 30;

    private const MAX_SCHEMA_BYTES = 20000;

    public function __construct(private readonly BridgeClient $bridge) {}

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, escritura: bool}>
     */
    public function definitions(): array
    {
        if (! $this->bridge->enabled()) {
            return [];
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        // Con el Dashboard caido o apagado cada peticion MCP esperaria su tiempo limite: el fallo se recuerda un rato.
        if (Cache::has(self::FAILURE_KEY)) {
            return [];
        }

        $result = $this->bridge->call('GET', '/interno/ia/herramientas', null, (int) config('ecosistema.puente.timeout', 3) + 4);
        $definitions = $result['ok'] ? $this->clean((array) ($result['data']['herramientas'] ?? [])) : [];

        if ($definitions === []) {
            Cache::put(self::FAILURE_KEY, true, now()->addSeconds(45));

            return [];
        }

        Cache::put(self::CACHE_KEY, $definitions, now()->addMinutes((int) config('ecosistema.ia.cache_minutos', 10)));

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{contenido: string, error: bool, fuentes: list<array<string, string>>}
     */
    public function execute(string $tool, array $input, string $client): array
    {
        if (preg_match('/^[a-z_]{3,40}$/', $tool) !== 1) {
            return $this->failure(__('mcp.dashboard.unavailable'));
        }

        $result = $this->bridge->call('POST', '/interno/ia/herramientas/'.$tool, ['cliente' => $client, 'entrada' => $input]);

        if (! $result['ok']) {
            return $this->failure(match ($result['status']) {
                404 => __('mcp.dashboard.not_enabled'),
                422 => __('mcp.dashboard.invalid'),
                default => __('mcp.dashboard.unavailable'),
            });
        }

        $data = (array) $result['data'];

        return [
            'contenido' => mb_substr((string) ($data['contenido'] ?? ''), 0, 200000),
            'error' => (bool) ($data['error'] ?? false),
            'fuentes' => $this->sources((array) ($data['fuentes'] ?? [])),
        ];
    }

    /**
     * Solo lo que el MCP sabe publicar: nombre seguro y sin repetir, texto acotado, esquema de tipo objeto de tamano
     * razonable y un numero maximo de herramientas.
     *
     * @param  array<int, mixed>  $raw
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, escritura: bool}>
     */
    private function clean(array $raw): array
    {
        $clean = [];

        foreach ($raw as $item) {
            if (! is_array($item)
                || ! is_string($item['name'] ?? null) || preg_match('/^[a-z_]{3,40}$/', $item['name']) !== 1
                || isset($clean[$item['name']])
                || ! is_string($item['description'] ?? null)
                || ! is_array($item['inputSchema'] ?? null) || ($item['inputSchema']['type'] ?? null) !== 'object'
                || strlen((string) json_encode($item['inputSchema'])) > self::MAX_SCHEMA_BYTES) {
                continue;
            }

            $clean[$item['name']] = [
                'name' => $item['name'],
                'description' => mb_substr($item['description'], 0, 2000),
                'inputSchema' => $item['inputSchema'],
                'escritura' => ! in_array($item['name'], self::READ_ONLY_TOOLS, true),
            ];

            if (count($clean) >= self::MAX_TOOLS) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * Solo enlaces https al propio Dashboard.
     *
     * @param  array<int, mixed>  $raw
     * @return list<array<string, string>>
     */
    private function sources(array $raw): array
    {
        $host = parse_url((string) config('ecosistema.puente.url'), PHP_URL_HOST);
        $sources = [];

        foreach (array_slice($raw, 0, 20) as $source) {
            if (! is_array($source) || ! is_string($source['titulo'] ?? null) || ! is_string($source['url'] ?? null)) {
                continue;
            }

            $parts = parse_url($source['url']);

            if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host) || ($parts['host'] ?? null) !== $host || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }

            $sources[] = ['titulo' => mb_substr($source['titulo'], 0, 200), 'url' => mb_substr($source['url'], 0, 500)];
        }

        return $sources;
    }

    /**
     * @return array{contenido: string, error: bool, fuentes: list<array<string, string>>}
     */
    private function failure(string $message): array
    {
        return ['contenido' => $message, 'error' => true, 'fuentes' => []];
    }
}
