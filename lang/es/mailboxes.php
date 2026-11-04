<?php

return [
    'title' => 'Buzones de correo',
    'edit' => 'Buzón de la coordinación',
    'intro' => 'Cada coordinación tiene su propio buzón IMAP. Los correos que llegan a él aparecen solo en la bandeja de esa coordinación.',
    'configure' => 'Configurar',
    'test' => 'Probar conexión',
    'delete_confirm' => '¿Eliminar el buzón de esta coordinación? Los correos ya recibidos se conservan.',
    'columns' => [
        'coordination' => 'Coordinación',
        'server' => 'Servidor',
        'status' => 'Estado',
        'last_polled' => 'Última revisión',
    ],
    'status' => [
        'none' => 'Sin buzón',
        'ok' => 'Activo',
        'inactive' => 'Desactivado',
        'error' => 'Con error',
    ],
    'encryptions' => [
        'ssl' => 'SSL/TLS (puerto 993)',
        'tls' => 'TLS',
        'starttls' => 'STARTTLS',
        'none' => 'Sin cifrado (no recomendado)',
    ],
    'form' => [
        'host' => 'Servidor IMAP (nombre público)',
        'port' => 'Puerto',
        'encryption' => 'Cifrado',
        'username' => 'Usuario / correo del buzón',
        'password' => 'Contraseña del buzón',
        'password_help' => 'Se guarda cifrada y no se vuelve a mostrar.',
        'password_keep' => 'Déjala vacía para conservar la contraseña actual. Nunca se muestra.',
        'folder' => 'Carpeta',
        'active' => 'Revisar este buzón periódicamente',
    ],
    'errors' => [
        'not_configured' => 'Esta coordinación aún no tiene un buzón configurado.',
        'test_failed' => 'No se pudo conectar al buzón: :reason',
    ],
    'validation' => [
        'host_invalid' => 'El servidor no es un nombre de host o IP válido.',
        'host_unresolvable' => 'No se pudo resolver el servidor. Revisa el nombre.',
        'host_not_public' => 'El servidor debe ser una dirección pública; no se aceptan direcciones privadas, locales o reservadas.',
    ],
    'flash' => [
        'saved' => 'Buzón guardado.',
        'deleted' => 'Buzón eliminado.',
        'test_ok' => 'La conexión al buzón funciona.',
    ],
];
