<?php

// Preguntas frecuentes de Temayin. `audience`: all (todos), manage (jefe/coordinador con actividades) o admin (gestión de usuarios).
return [
    'name' => 'Temayin',
    'subtitle' => 'Asistente virtual',
    'avatar_alt' => 'Temayin, asistente virtual',
    'open' => 'Abrir asistente Temayin',
    'close' => 'Cerrar asistente',
    'greeting' => '¡Hola! Soy Temayin. Elige una pregunta y te explico cómo funciona el sistema.',
    'choose' => 'Preguntas frecuentes',
    'back' => 'Ver otras preguntas',
    'faqs' => [
        'ticket_vs_activity' => [
            'audience' => 'all',
            'q' => '¿Qué diferencia hay entre un ticket y una actividad?',
            'a' => 'Un ticket es una solicitud que hay que atender (por ejemplo, una falla en una impresora). Una actividad es una tarea planificada por el jefe de zona o un coordinador, con subtareas y, si se necesita, recurrencia (por ejemplo, un reporte semanal).',
        ],
        'create_ticket' => [
            'audience' => 'all',
            'q' => '¿Cómo creo un ticket?',
            'a' => 'Entra a Tickets y usa el botón para crear uno nuevo. Escribe un título y una descripción, elige la prioridad y, si quieres, una fecha límite. También puedes adjuntar archivos y elegir el equipo al que se lo envías.',
        ],
        'other_team' => [
            'audience' => 'all',
            'q' => '¿Puedo mandar un ticket a otro equipo?',
            'a' => 'Sí. Al crear el ticket elige el equipo destino (por ejemplo, TI). El ticket queda en la bolsa de ese equipo y tú lo sigues viendo porque lo creaste.',
        ],
        'states' => [
            'audience' => 'all',
            'q' => '¿Qué significa cada estado?',
            'a' => 'Pendiente: aún no se empieza. En proceso: alguien trabaja en ello. En revisión: ya se terminó y espera aprobación. Completado: fue aprobado. Cancelado: se dio de baja. Solo el jefe o el coordinador aprueban; tú puedes avanzar hasta En revisión.',
        ],
        'pending' => [
            'audience' => 'all',
            'q' => '¿Dónde veo lo que tengo pendiente?',
            'a' => 'En «Mis pendientes». Ahí aparecen tus tickets y actividades asignados, ordenados por fecha de vencimiento y prioridad. Puedes filtrar los que vencen hoy.',
        ],
        'overdue' => [
            'audience' => 'all',
            'q' => '¿Qué significa «vencido»?',
            'a' => 'Un ticket o actividad está vencido cuando su fecha límite ya pasó y todavía no está completado ni cancelado. Se marca en rojo en los listados.',
        ],
        'take_ticket' => [
            'audience' => 'all',
            'q' => '¿Qué es la bolsa y cómo tomo un ticket?',
            'a' => 'La bolsa reúne los tickets de tu equipo que aún no tienen responsable. Abre el ticket y usa «Tomar este ticket» para quedarte con él.',
        ],
        'rejected' => [
            'audience' => 'all',
            'q' => 'Rechazaron mi trabajo, ¿qué hago?',
            'a' => 'Cuando se rechaza, el elemento regresa a En proceso y se deja un comentario con el motivo. Léelo en el historial, corrige lo indicado y vuelve a enviarlo a revisión.',
        ],
        'attachments' => [
            'audience' => 'all',
            'q' => '¿Qué archivos puedo adjuntar?',
            'a' => 'PDF, imágenes PNG o JPG, texto (txt), Word (docx) y Excel (xlsx), de hasta 10 MB cada uno. Otros tipos, como HTML o SVG, no se permiten por seguridad.',
        ],
        'chat' => [
            'audience' => 'all',
            'q' => '¿Cómo uso el chat?',
            'a' => 'En Chat puedes escribir a una persona o al canal de tu equipo, y adjuntar archivos. Si escribes un folio (TM-… o ACT-…) se convierte en enlace, siempre que tengas acceso a él.',
        ],
        'notifications' => [
            'audience' => 'all',
            'q' => '¿Dónde veo mis avisos?',
            'a' => 'En Notificaciones. Ahí verás cuando te asignen algo, comenten en tus tickets o actividades, o rechacen tu trabajo. El contador rojo del menú indica lo que no has leído.',
        ],
        'avatar' => [
            'audience' => 'all',
            'q' => '¿Cómo cambio mi foto de perfil?',
            'a' => 'Entra a Mi perfil y sube una imagen JPG o PNG de hasta 4 MB. Se recorta a un cuadrado y se guarda sin datos ocultos, como la ubicación.',
        ],
        'two_factor' => [
            'audience' => 'all',
            'q' => 'Perdí mi celular, ¿cómo entro con la verificación en dos pasos?',
            'a' => 'Usa uno de tus códigos de recuperación en la pantalla de verificación (cada uno sirve una sola vez). Si no los tienes, pide al jefe de zona o a soporte que restablezcan tu verificación.',
        ],
        'password' => [
            'audience' => 'all',
            'q' => 'Olvidé mi contraseña',
            'a' => 'En la pantalla de inicio de sesión elige «¿Olvidaste tu contraseña?» y escribe tu correo. Si la cuenta existe, recibirás un enlace para crear una nueva.',
        ],
        'assign' => [
            'audience' => 'manage',
            'q' => '¿Cómo asigno o reasigno un ticket o actividad?',
            'a' => 'Abre el detalle y usa la tarjeta de asignación: elige un responsable (uno solo) y, si quieres, colaboradores. El coordinador solo puede asignar a personas de su equipo.',
        ],
        'review' => [
            'audience' => 'manage',
            'q' => '¿Cómo apruebo o rechazo un trabajo en revisión?',
            'a' => 'Abre el elemento En revisión. Para aprobarlo usa la acción principal. Para rechazarlo abre «Rechazar» y escribe el motivo, que es obligatorio; regresará a En proceso.',
        ],
        'cancel_reopen' => [
            'audience' => 'manage',
            'q' => '¿Cómo cancelo o reabro algo?',
            'a' => 'En «Otras acciones» del detalle. Cancelar pide confirmación. Reabrir un elemento completado o cancelado exige un comentario y lo regresa a Pendiente.',
        ],
        'recurrence' => [
            'audience' => 'manage',
            'q' => '¿Cómo funcionan las actividades recurrentes?',
            'a' => 'Al crear una actividad recurrente defines frecuencia (diaria, semanal o mensual), intervalo y, si quieres, una fecha final. El sistema genera cada día las próximas instancias, y cada una se gestiona por separado. La plantilla se edita en «Plantillas».',
        ],
        'subtasks' => [
            'audience' => 'manage',
            'q' => '¿Cómo funcionan las subtareas y el avance?',
            'a' => 'El avance es el porcentaje de subtareas hechas. No se puede pasar a En revisión ni Completado con subtareas pendientes.',
        ],
        'incoming_emails' => [
            'audience' => 'manage',
            'q' => '¿Qué hago con los correos entrantes?',
            'a' => 'Cada correo llega a la bandeja «Correos entrantes» para que una persona lo revise. Si es una tarea real, conviértelo en actividad; si no, descártalo indicando el motivo. Nunca se borra.',
        ],
        'tracking' => [
            'audience' => 'manage',
            'q' => '¿Qué muestra el panel de seguimiento?',
            'a' => 'Tarjetas de abiertos, en revisión, vencidos y completados; carga de trabajo por persona; tiempo promedio de cierre y gráficas, con filtros por periodo, equipo, persona y categoría. El coordinador ve solo su equipo.',
        ],
        'export' => [
            'audience' => 'manage',
            'q' => '¿Cómo exporto a Excel?',
            'a' => 'En los listados de Tickets y Actividades usa «Exportar a Excel». Se exporta lo que ves con los filtros actuales.',
        ],
        'approve_users' => [
            'audience' => 'admin',
            'q' => '¿Cómo apruebo a un usuario nuevo?',
            'a' => 'En Usuarios verás las cuentas pendientes. Apruébalas asignando rol y equipo, o recházalas con un motivo. Hasta entonces, la persona no tiene acceso.',
        ],
        'teams_categories' => [
            'audience' => 'admin',
            'q' => '¿Cómo administro equipos y categorías?',
            'a' => 'En Equipos creas equipos y eliges al coordinador (debe pertenecer a ese equipo). En Categorías creas o desactivas categorías; una con tickets no se borra, se desactiva.',
        ],
        'audit' => [
            'audience' => 'admin',
            'q' => '¿Dónde consulto la bitácora?',
            'a' => 'En Bitácora, con filtros por usuario, evento, tipo de registro, fechas y texto. Es solo de lectura.',
        ],
    ],
];
