<?php

return [
    'title' => 'Categorías',
    'new' => 'Nueva categoría',
    'edit' => 'Editar categoría',
    'columns' => [
        'name' => 'Nombre',
        'status' => 'Estado',
        'tickets' => 'Tickets',
    ],
    'active' => 'Activa',
    'inactive' => 'Inactiva',
    'form' => [
        'name' => 'Nombre de la categoría',
        'active' => 'Categoría activa (se puede elegir en tickets nuevos)',
    ],
    'delete_confirm' => '¿Eliminar esta categoría?',
    'errors' => [
        'has_tickets' => 'No se puede eliminar una categoría que tiene tickets. Desactívala para que deje de ofrecerse.',
    ],
];
