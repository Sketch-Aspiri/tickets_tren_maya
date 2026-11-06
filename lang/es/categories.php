<?php

return [
    'title' => 'Categorías',
    'new' => 'Nueva categoría',
    'edit' => 'Editar categoría',
    'columns' => [
        'name' => 'Nombre',
        'coordination' => 'Coordinación',
        'status' => 'Estado',
        'tickets' => 'Tickets',
    ],
    'active' => 'Activa',
    'inactive' => 'Inactiva',
    'global' => 'Global (todas)',
    'general' => 'General (todas las coordinaciones)',
    'form' => [
        'coordination' => 'Coordinación',
        'global' => 'Global (todas las coordinaciones)',
        'name' => 'Nombre de la categoría',
        'active' => 'Categoría activa (se puede elegir en tickets nuevos)',
    ],
    'delete_confirm' => '¿Eliminar esta categoría?',
    'errors' => [
        'has_tickets' => 'No se puede eliminar una categoría que tiene tickets. Desactívala para que deje de ofrecerse.',
        'wrong_coordination' => 'La categoría no pertenece a la coordinación del equipo elegido.',
    ],
];
