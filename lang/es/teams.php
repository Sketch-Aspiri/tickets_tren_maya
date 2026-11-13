<?php

return [
    'title' => 'Equipos',
    'new' => 'Nuevo equipo',
    'edit' => 'Editar equipo',
    'columns' => [
        'name' => 'Nombre',
        'coordination' => 'Coordinación',
        'coordinator' => 'Coordinadores',
        'members' => 'Integrantes',
    ],
    'form' => [
        'coordination' => 'Coordinación',
        'choose_coordination' => 'Elige una coordinación',
        'name' => 'Nombre del equipo',
        'coordinator' => 'Coordinador principal',
        'coordinator_hint' => 'Es el responsable de las actividades que se crean solas para este equipo.',
        'additional' => 'Coordinadores adicionales',
        'additional_hint' => 'Comparten la gestión del equipo y reciben sus avisos. Solo aparecen integrantes con rol de coordinador.',
        'no_coordinator' => 'Sin coordinador',
        'coordinator_on_create_note' => 'El coordinador se asigna al editar el equipo, una vez que sea miembro del mismo.',
        'coordinator_none_eligible' => 'Aún no hay coordinadores elegibles. Primero aprueba o asigna a un coordinador a este equipo desde Usuarios; después podrás elegirlo aquí.',
        'go_to_users' => 'Ir a Usuarios',
        'errors_heading' => 'No se pudo guardar el equipo',
    ],
    'delete_confirm' => '¿Eliminar este equipo?',
    'errors' => [
        'has_members' => 'No se puede eliminar un equipo que aún tiene integrantes. Reasígnalos primero.',
        'has_tickets' => 'No se puede eliminar un equipo que tiene tickets registrados.',
    ],
    'validation' => [
        'coordinator_invalid' => 'El coordinador debe ser un usuario activo con rol de coordinador que pertenezca a este equipo.',
        'additional_requires_principal' => 'Para agregar coordinadores adicionales primero elige el coordinador principal.',
        'additional_same_as_principal' => 'El coordinador principal no puede repetirse como adicional.',
        'additional_max' => 'Un equipo puede tener como máximo :max coordinadores adicionales.',
        'coordinator_requires_existing_team' => 'Un equipo nuevo aún no tiene integrantes: créalo primero, asigna al coordinador al equipo desde Usuarios y luego elígelo al editar el equipo.',
    ],
];
