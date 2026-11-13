<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\Servers\TicketsServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * Servidor MCP de Tickets (decision 94). `laravel/mcp` carga este archivo en un grupo SIN `web`: no hay sesion,
 * cookies ni CSRF; cada peticion la autentica AuthenticateMcp (token Bearer de un cliente registrado) y actua
 * como la cuenta de ese cliente. Sin clientes creados el endpoint responde 404.
 */
Mcp::web('/mcp', TicketsServer::class)->middleware(['throttle:mcp-ip', 'throttle:mcp', AuthenticateMcp::class]);
