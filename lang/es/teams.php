<?php

return [
    'title' => 'Equipos',
    'new' => 'Nuevo equipo',
    'edit' => 'Editar equipo',
    'columns' => [
        'name' => 'Nombre',
        'coordinator' => 'Coordinador',
        'members' => 'Integrantes',
    ],
    'form' => [
        'name' => 'Nombre del equipo',
        'coordinator' => 'Coordinador',
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
        'coordinator_requires_existing_team' => 'Un equipo nuevo aún no tiene integrantes: créalo primero, asigna al coordinador al equipo desde Usuarios y luego elígelo al editar el equipo.',
    ],
];
