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

];
