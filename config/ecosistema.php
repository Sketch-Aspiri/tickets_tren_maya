<?php

declare(strict_types=1);

/*
 * Ecosistema Zona Oriente: el sistema de Tickets y el Dashboard se usan como uno solo
 * (selector de sistema, tema compartido y, en fases siguientes, inicio de sesion unico y
 * datos compartidos). Ver docs/ECOSISTEMA.md.
 */
return [

    // Este sistema dentro del ecosistema.
    'sistema' => 'tickets',

    // Direccion publica de cada sistema (sin diagonal final).
    'urls' => [
        'dashboard' => rtrim((string) env('ECOSISTEMA_DASHBOARD_URL', 'https://dashboard.zonaorientetm.tech'), '/'),
        'tickets' => rtrim((string) env('ECOSISTEMA_TICKETS_URL', 'https://tickets.zonaorientetm.tech'), '/'),
    ],

    // Inicio de sesion unico entre los dos sistemas (App\Services\Ecosystem\SingleSignOnService, decision 88).
    // ECOSISTEMA_SSO_SECRET: el MISMO valor en los .env de ambos sistemas (32+ caracteres aleatorios).
    // Sin el, cada sistema pide su propio login.
    'sso' => [
        'secreto' => env('ECOSISTEMA_SSO_SECRET'),
        // Vigencia de cada codigo firmado, en segundos.
        'vigencia' => 60,
        // Tras preguntarle al otro sistema, no se vuelve a preguntar en estos minutos (evita ciclos).
        'reintento_minutos' => 5,
    ],

    // Puente interno entre los dos servidores (decision 89): llamadas firmadas con HMAC, nunca desde el navegador.
    // Un secreto por direccion, el MISMO valor en los dos .env (32+ caracteres aleatorios cada uno):
    //   ECOSISTEMA_PUENTE_SECRETO_DASHBOARD firma lo que el Dashboard le pide a Tickets;
    //   ECOSISTEMA_PUENTE_SECRETO_TICKETS firma lo que Tickets le pide al Dashboard.
    // Sin secretos, el puente queda apagado (las rutas /interno responden 404) y nada cambia.
    'puente' => [
        'secreto_entrada' => env('ECOSISTEMA_PUENTE_SECRETO_DASHBOARD'),
        'secreto_salida' => env('ECOSISTEMA_PUENTE_SECRETO_TICKETS'),
        // Direccion con la que este servidor llama al otro (por omision, la publica).
        'url' => rtrim((string) (env('ECOSISTEMA_PUENTE_DASHBOARD_URL') ?: env('ECOSISTEMA_DASHBOARD_URL', 'https://dashboard.zonaorientetm.tech')), '/'),
        // IPs desde las que se aceptan llamadas (separadas por coma; vacio = cualquiera, solo vale la firma).
        'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ECOSISTEMA_PUENTE_IPS', ''))))),
        // Diferencia maxima de reloj aceptada, en segundos.
        'ventana' => 300,
        'timeout' => 3,
    ],

];
