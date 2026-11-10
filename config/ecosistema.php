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

];
