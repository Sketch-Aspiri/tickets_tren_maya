<?php

return [
    'json_denied' => 'Tu cuenta no tiene acceso al sistema.',
    'status' => [
        'title' => 'Estado de tu cuenta',
        'pending' => [
            'heading' => 'Cuenta pendiente de aprobación',
            'body' => 'Recibimos tu solicitud. Un jefe de zona debe aprobarla y asignarte rol y equipo antes de que puedas usar el sistema.',
        ],
        'inactive' => [
            'heading' => 'Cuenta inactiva',
            'body' => 'Tu cuenta no tiene acceso al sistema. Si crees que es un error, contacta al jefe de zona.',
        ],
        'no_role' => [
            'heading' => 'Cuenta sin rol asignado',
            'body' => 'Tu cuenta todavía no tiene un rol asignado. Contacta al jefe de zona.',
        ],
    ],
];
