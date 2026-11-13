<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ActsAsMcpClient;
use App\Models\McpClient;
use App\Services\AuditLogger;
use App\Services\Mcp\DashboardGateway;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Una herramienta del Dashboard publicada en el MCP de Tickets (decision 97). El nombre, la descripcion y el esquema
 * vienen del Dashboard, por eso la clase no lleva atributos (el cache de atributos es por clase, no por instancia) y
 * reemplaza `name()`/`toArray()`. Solo se anuncia y solo se ejecuta si el cliente tiene acceso al Dashboard con el
 * nivel que la herramienta exige. El resultado se entrega como texto NO confiable: viene de tablas y documentos.
 */
final class DashboardProxyTool extends Tool
{
    use ActsAsMcpClient;

    /**
     * @param  array{name: string, description: string, inputSchema: array<string, mixed>, escritura: bool}  $definition
     */
    public function __construct(private readonly array $definition)
    {
        $this->name = 'dashboard_'.$definition['name'];
        $this->title = 'Dashboard: '.str_replace('_', ' ', $definition['name']);
        $this->description = '[Dashboard] '.$definition['description'];
    }

    public function shouldRegister(): bool
    {
        return $this->mcpClient()?->dashboardAllows($this->definition['escritura']) === true;
    }

    public function handle(Request $request, DashboardGateway $gateway, AuditLogger $audit): Response
    {
        return $this->run(function (McpClient $client) use ($request, $gateway, $audit): Response {
            // Aunque la herramienta solo se anuncia a quien puede, se vuelve a comprobar al ejecutarla.
            if (! $client->dashboardAllows($this->definition['escritura'])) {
                // Un intento contra una herramienta que el cliente no puede usar queda registrado.
                $audit->record('mcp', 'dashboard_denied', null, $client->user, [], [], ['client' => $client->name, 'tool' => $this->definition['name']]);

                return Response::error(__('mcp.errors.no_permission'));
            }

            $result = $gateway->execute($this->definition['name'], $request->all(), $client->name);

            if ($this->definition['escritura']) {
                $audit->record('mcp', 'dashboard_write', null, $client->user, [], [], [
                    'client' => $client->name,
                    'tool' => $this->definition['name'],
                    'error' => $result['error'],
                ]);
            }

            if ($result['error']) {
                return Response::error($result['contenido']);
            }

            $sources = $result['fuentes'] === []
                ? ''
                : "\n\n".__('mcp.dashboard.sources').":\n".implode("\n", array_map(fn (array $s): string => '- '.$s['titulo'].' '.$s['url'], $result['fuentes']));

            return Response::text(__('mcp.dashboard.untrusted_notice')."\n\n".$result['contenido'].$sources);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = $this->definition['inputSchema'];

        // Un objeto sin propiedades debe serializarse como `{}`, no como `[]`.
        if (empty($schema['properties'])) {
            $schema['properties'] = (object) [];
        }

        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $schema,
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => ! $this->definition['escritura'],
                'destructiveHint' => $this->definition['escritura'],
                'idempotentHint' => ! $this->definition['escritura'],
                'openWorldHint' => false,
            ],
        ];
    }
}
