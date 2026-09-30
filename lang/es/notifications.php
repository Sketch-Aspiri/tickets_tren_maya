<?php

return [
    'greeting' => 'Hola, :name',
    'new_user_pending' => [
        'subject' => 'Nueva cuenta pendiente de aprobación',
        'line' => 'Se registró :name (:email) y espera tu aprobación.',
        'action' => 'Revisar solicitud',
    ],
    'ui' => [
        'title' => 'Notificaciones',
        'mark_all' => 'Marcar todo como leído',
        'chat_section' => 'Mensajes nuevos',
        'no_chat' => 'No tienes mensajes sin leer.',
        'alerts_section' => 'Avisos',
        'no_alerts' => 'No tienes avisos.',
        'unread' => 'Sin leer:',
        'generic' => 'Aviso del sistema.',
        'work_item_commented' => [
            'ticket' => ':by comentó en el ticket :folio — :title',
            'activity' => ':by comentó en la actividad :folio — :title',
        ],
        'work_item_rejected' => [
            'ticket' => ':by rechazó el ticket :folio — :title; regresó a En proceso. Revisa el motivo en el historial.',
            'activity' => ':by rechazó la actividad :folio — :title; regresó a En proceso. Revisa el motivo en el historial.',
        ],
        'assigned' => [
            'ticket' => ':by te asignó el ticket :folio — :title',
            'activity' => ':by te asignó la actividad :folio — :title',
        ],
    ],
    'new_incoming_email' => [
        'subject' => 'Correos nuevos por revisar',
        'line' => 'Llegaron :count correo(s) nuevo(s) a la bandeja de correos entrantes y esperan tu revisión.',
        'action' => 'Revisar bandeja',
    ],
];
