<?php

return [
    'errors' => [
        'invalid_endpoint' => 'El servicio de notificaciones del navegador no es válido.',
        'too_many' => 'Ya tienes el máximo de dispositivos con notificaciones activadas. Desactívalas en otro dispositivo primero.',
    ],
    'assigned' => ['title' => 'Nueva asignación', 'body' => ':by te asignó :folio.'],
    'commented' => ['title' => 'Nuevo comentario', 'body' => ':by comentó en :folio.'],
    'rejected' => ['title' => 'Regresado a trabajo', 'body' => ':by rechazó la revisión de :folio.'],
    'status' => ['title' => 'Cambio de estado', 'body' => ':by cambió :folio a «:status».'],
    'chat' => [
        'title' => 'Nuevo mensaje',
        'body_direct' => ':by te escribió en el chat.',
        'body_team' => ':by escribió en el canal :team.',
    ],
    'ui' => [
        'title' => 'Notificaciones en este dispositivo',
        'intro' => 'Recibe avisos en tu celular o computadora aunque el sistema esté cerrado.',
        'enable' => 'Activar notificaciones',
        'disable' => 'Desactivar notificaciones',
        'state_on' => 'Activadas en este dispositivo.',
        'state_off' => 'Desactivadas en este dispositivo.',
        'state_blocked' => 'Bloqueadas por el navegador. Permítelas en la configuración del sitio para activarlas.',
        'state_unsupported' => 'Este navegador no admite notificaciones push.',
        'state_ios_install' => 'En iPhone, primero instala la app: Compartir → «Agregar a pantalla de inicio», y ábrela desde ahí.',
        'state_missing_key' => 'Las notificaciones push no están configuradas en el servidor.',
        'error' => 'No se pudo completar la acción. Inténtalo de nuevo.',
    ],
];
