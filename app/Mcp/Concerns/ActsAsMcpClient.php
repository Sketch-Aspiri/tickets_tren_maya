<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\McpClient;
use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

/**
 * Comun a las herramientas MCP (decision 94): el cliente autenticado que dejo `AuthenticateMcp`, la regla de que
 * las herramientas de escritura no existen para clientes de solo lectura, y la traduccion de errores de negocio
 * a mensajes de la herramienta (nunca trazas ni SQL).
 */
trait ActsAsMcpClient
{
    protected function mcpClient(): ?McpClient
    {
        $client = request()->attributes->get('mcp_client');

        return $client instanceof McpClient ? $client : null;
    }

    /**
     * Las herramientas de escritura solo se anuncian a clientes con permiso de escritura.
     */
    protected function clientCanWrite(): bool
    {
        return $this->mcpClient()?->can_write === true;
    }

    /**
     * @param  Closure(McpClient): Response  $callback
     */
    protected function run(Closure $callback): Response
    {
        $client = $this->mcpClient();

        if ($client === null) {
            return Response::error(__('mcp.errors.unauthenticated'));
        }

        try {
            return $callback($client);
        } catch (BusinessRuleException $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            return Response::error(implode(' ', $e->validator->errors()->all()));
        }
    }
}
