<?php

return [
    'title' => 'Estaciones',
    'new' => 'Nueva estación',
    'edit' => 'Editar estación',
    'columns' => [
        'name' => 'Nombre',
        'status' => 'Estado',
        'coordinations' => 'Coordinaciones',
    ],
    'users' => 'Usuarios de esta estación',
    'teams_count' => '{0} Sin equipos|{1} 1 equipo|[2,*] :count equipos',
    'active' => 'Activa',
    'inactive' => 'Inactiva',
    'form' => [
        'name' => 'Nombre de la estación o edificio',
        'active' => 'Estación activa (se puede elegir en registros y equipos nuevos)',
    ],
    'delete_confirm' => '¿Eliminar esta estación?',
    'errors' => [
        'has_coordinations' => 'No se puede eliminar una estación que tiene coordinaciones. Desactívala o muévelas primero.',
    ],
];
