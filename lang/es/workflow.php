<?php

// Textos compartidos por tickets y actividades para el flujo de estados (componentes workflow-stepper y
// workflow-actions). Los nombres de cada acción (`*.actions.transition.*`) siguen en tickets.php y activities.php.
return [
    'title' => 'Estado y siguiente paso',
    'label' => 'Flujo de estados',
    'step_done' => 'paso completado',
    'step_current' => 'paso actual',
    'step_todo' => 'paso pendiente',
    'cancelled' => 'Esta solicitud fue cancelada y está fuera del flujo.',
    'optional' => 'opcional',
    'required' => 'obligatorio',
    'other_actions' => 'Otras acciones',
    'go_to_subtasks' => 'Ir a las subtareas',
    'blocked' => 'Aún no puedes avanzar: faltan :count subtarea(s) por hacer. Márcalas como hechas o elimínalas.',
    'reject_hint' => 'Explica qué falta corregir; regresa a En proceso.',
    'reopen_hint' => 'Explica por qué se reabre; vuelve a Pendiente.',
    'cancel_hint' => 'Cancelar es definitivo hasta que un responsable lo reabra.',
    'cancel_confirm' => '¿Cancelar esto? Podrá reabrirse después con un comentario.',
    'next_hint' => [
        'in_progress' => 'Siguiente paso: empieza a trabajar en esto.',
        'in_review' => 'Siguiente paso: cuando termines, envíalo a revisión.',
        'completed' => 'Espera tu revisión: apruébalo o recházalo con un comentario.',
    ],
    'waiting' => [
        'pending' => 'Todavía no se ha iniciado.',
        'in_progress' => 'Está en proceso.',
        'in_review' => 'En revisión: espera la aprobación de un jefe o coordinador.',
    ],
    'bag' => [
        'title' => 'Este ticket está en la bolsa de tu equipo',
        'hint' => 'Nadie lo tiene asignado. Si lo tomas, serás el responsable.',
    ],
    'picker' => [
        'filter' => 'Buscar persona',
        'filter_placeholder' => 'Escribe un nombre',
        'counter' => ':count de :max seleccionados',
        'no_results' => 'Ninguna persona coincide con la búsqueda.',
        'is_responsible' => '(responsable)',
        'hint' => 'Opcional. Marca a quienes colaboran; el responsable no se puede repetir aquí.',
        'no_users' => 'No hay personas disponibles para asignar.',
    ],
];
