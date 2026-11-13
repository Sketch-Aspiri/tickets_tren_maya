<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\McpClient;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Dice con que cuenta y permisos actua el cliente. Sirve para comprobar la conexion y el alcance sin tocar datos.
 */
#[Description('Indica con que cuenta, rol y permisos (lectura/escritura) estas conectado al sistema de Tickets.')]
#[IsReadOnly]
#[Name('quien_soy')]
class WhoAmITool extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var McpClient|null $client */
        $client = request()->attributes->get('mcp_client');
        $user = $client?->user;

        if ($client === null || $user === null) {
            return Response::error(__('mcp.errors.unauthenticated'));
        }

        return Response::json([
            'cliente' => $client->name,
            'cuenta' => $user->name,
            'rol' => $user->roleEnum()?->value,
            'puede_escribir' => $client->can_write,
        ]);
    }
}
