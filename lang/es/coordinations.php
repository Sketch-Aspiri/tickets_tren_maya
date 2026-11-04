<?php

return [
    'title' => 'Coordinaciones',
    'new' => 'Nueva coordinación',
    'edit' => 'Editar coordinación',
    'columns' => [
        'name' => 'Nombre',
        'station' => 'Estación',
        'code' => 'Código',
        'status' => 'Estado',
        'teams' => 'Equipos',
        'bosses' => 'Jefes de zona',
    ],
    'users' => 'Usuarios de esta coordinación',
    'active' => 'Activa',
    'inactive' => 'Inactiva',
    'form' => [
        'station' => 'Estación',
        'choose_station' => 'Elige una estación',
        'name' => 'Nombre de la coordinación',
        'code' => 'Código corto',
        'code_help' => 'Letras, números, guiones; por ejemplo TI-MER. Debe ser único.',
        'active' => 'Coordinación activa (se puede elegir en registros y equipos nuevos)',
    ],
    'delete_confirm' => '¿Eliminar esta coordinación?',
    'errors' => [
        'has_teams' => 'No se puede eliminar una coordinación que tiene equipos. Desactívala o mueve los equipos primero.',
        'has_bosses' => 'No se puede eliminar una coordinación que tiene jefes de zona asignados. Quítalos primero.',
        'has_categories' => 'No se puede eliminar una coordinación que tiene categorías propias.',
    ],
];
