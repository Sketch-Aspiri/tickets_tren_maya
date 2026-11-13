<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Servidor MCP de Tickets (decision 94)
|--------------------------------------------------------------------------
| `modo`: `propuesta` (por defecto) = la IA solo propone y una persona confirma en la bandeja;
| `automatico_confiable` = la IA crea la actividad sola SOLO si el cliente puede escribir, la confianza alcanza el
| umbral, el remitente esta autorizado y todo valida; en cualquier otro caso cae a propuesta.
*/

return [
    'correo' => [
        'modo' => env('MCP_CORREO_MODO', 'propuesta'),
        'umbral_confianza' => (int) env('MCP_CORREO_UMBRAL', 90),
        // Correos (separados por coma) cuyos mensajes pueden crear actividades en modo automatico.
        'remitentes_autorizados' => array_values(array_filter(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            explode(',', (string) env('MCP_CORREO_REMITENTES', '')),
        ))),
        // Actividades que un cliente puede crear solo por hora (defensa ante un correo que intente inundar).
        'creaciones_por_hora' => (int) env('MCP_CORREO_CREACIONES_HORA', 5),
        // Correos que un cliente puede descartar por hora (un correo malicioso no debe vaciar la bandeja).
        'descartes_por_hora' => (int) env('MCP_CORREO_DESCARTES_HORA', 20),
        // El From del correo NO esta autenticado (no se valida SPF/DKIM/DMARC): en modo automatico, un correo con
        // adjuntos nunca se crea solo. No bajar a false sin antes autenticar al remitente.
        'auto_sin_adjuntos' => (bool) env('MCP_CORREO_AUTO_SIN_ADJUNTOS', true),
        // Tope de caracteres del cuerpo que se entrega a la IA.
        'cuerpo_max' => 4000,
        // Fecha limite maxima aceptada (dias a partir de hoy).
        'dias_max' => 730,
    ],
];
