<?php

return [
    'title' => 'Mi perfil',
    'info' => [
        'title' => 'Información del perfil',
        'intro' => 'Puedes actualizar tu nombre. El correo lo administra el jefe de zona.',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'submit' => 'Guardar',
        'saved' => 'Guardado.',
    ],
    'avatar' => [
        'title' => 'Foto de perfil',
        'intro' => 'Se muestra en el chat y en el menú. La recortamos en un cuadrado y quitamos los datos ocultos del archivo (como la ubicación).',
        'current' => 'Foto de perfil de :name',
        'label' => 'Elegir foto',
        'hint' => 'JPG o PNG, hasta :max MB.',
        'submit' => 'Guardar foto',
        'remove' => 'Quitar foto',
        'remove_confirm' => '¿Quitar tu foto de perfil?',
        'errors' => [
            'type' => 'El archivo no es una imagen JPG o PNG válida.',
            'dimensions' => 'La imagen es demasiado grande (máximo :max px por lado).',
            'upload_failed' => 'No se pudo guardar la foto. Inténtalo de nuevo.',
        ],
    ],
    'password' => [
        'title' => 'Cambiar contraseña',
        'intro' => 'Usa una contraseña larga (mínimo 10 caracteres, con mayúsculas, minúsculas y números).',
        'current' => 'Contraseña actual',
        'new' => 'Contraseña nueva',
        'confirm' => 'Confirmar contraseña nueva',
        'submit' => 'Actualizar contraseña',
        'saved' => 'Guardada.',
    ],
];
