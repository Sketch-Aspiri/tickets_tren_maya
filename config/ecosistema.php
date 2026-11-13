<?php

declare(strict_types=1);

/*
 * Ecosistema Zona Oriente: el sistema de Tickets y el Dashboard se usan como uno solo
 * (selector de sistema, tema compartido y, en fases siguientes, inicio de sesion unico y
 * datos compartidos). Ver docs/ECOSISTEMA.md.
 */
return [

    // Este sistema dentro del ecosistema.
    'sistema' => 'tickets',

    // Direccion publica de cada sistema (sin diagonal final).
    'urls' => [
        'dashboard' => rtrim((string) env('ECOSISTEMA_DASHBOARD_URL', 'https://dashboard.zonaorientetm.tech'), '/'),
        'tickets' => rtrim((string) env('ECOSISTEMA_TICKETS_URL', 'https://tickets.zonaorientetm.tech'), '/'),
    ],

    // Inicio de sesion unico entre los dos sistemas (App\Services\Ecosystem\SingleSignOnService, decision 88).
    // ECOSISTEMA_SSO_SECRET: el MISMO valor en los .env de ambos sistemas (32+ caracteres aleatorios).
    // Sin el, cada sistema pide su propio login.
    'sso' => [
        'secreto' => env('ECOSISTEMA_SSO_SECRET'),
        // Vigencia de cada codigo firmado, en segundos.
        'vigencia' => 60,
        // Tras preguntarle al otro sistema, no se vuelve a preguntar en estos minutos (evita ciclos).
        'reintento_minutos' => 5,
    ],

    // Actividades que el Dashboard pide por el puente cuando ocurre algo alla (decision 92). La actividad la crea
    // el usuario de sistema «Dashboard (automatico)» y se asigna al COORDINADOR del equipo destino. El equipo lo
    // decide Tickets, nunca el Dashboard: sin equipo configurado para un evento NO se crea nada.
    //   equipo:  id o nombre EXACTO del equipo (`php artisan ecosystem:teams` los lista).
    //   alcance: `estacion` (puede tener equipo propio en `estaciones`, si no usa el general) o `zona`.
    //   dias:    plazo de la actividad desde hoy. prioridad: low|medium|high|urgent.
    'actividades' => [
        'usuario_sistema' => [
            'nombre' => 'Dashboard (automático)',
            'correo' => 'dashboard-automatico@sistema.invalid',
        ],
        'eventos' => [
            'insumos_observada' => ['alcance' => 'estacion', 'prioridad' => 'high', 'dias' => 3, 'equipo' => env('ECOSISTEMA_ACT_EQUIPO_INSUMOS_OBSERVADA')],
            'insumos_devuelta' => ['alcance' => 'estacion', 'prioridad' => 'high', 'dias' => 3, 'equipo' => env('ECOSISTEMA_ACT_EQUIPO_INSUMOS_DEVUELTA')],
            'factura_cancelada' => ['alcance' => 'zona', 'prioridad' => 'urgent', 'dias' => 2, 'equipo' => env('ECOSISTEMA_ACT_EQUIPO_FACTURA_CANCELADA')],
            'asistencia_pendiente' => ['alcance' => 'estacion', 'prioridad' => 'medium', 'dias' => 1, 'equipo' => env('ECOSISTEMA_ACT_EQUIPO_ASISTENCIA')],
        ],
        // Codigo de estacion del Dashboard => equipo de Tickets (id o nombre exacto), para los eventos de alcance
        // `estacion`. JSON en el .env, p. ej. {"23":5,"24":"Estacion Tulum"}.
        // Tope de actividades NUEVAS por evento y hora (con el secreto del puente robado evita el spam). Lo ya creado
        // (mismo `ref`) no cuenta: se devuelve igual.
        'limite_por_hora' => (int) env('ECOSISTEMA_ACT_LIMITE_HORA', 60),
        'estaciones' => (array) (json_decode((string) env('ECOSISTEMA_ACT_ESTACIONES', '{}'), true) ?: []),
    ],

    // Puente interno entre los dos servidores (decision 89): llamadas firmadas con HMAC, nunca desde el navegador.
    // Un secreto por direccion, el MISMO valor en los dos .env (32+ caracteres aleatorios cada uno):
    //   ECOSISTEMA_PUENTE_SECRETO_DASHBOARD firma lo que el Dashboard le pide a Tickets;
    //   ECOSISTEMA_PUENTE_SECRETO_TICKETS firma lo que Tickets le pide al Dashboard.
    // Sin secretos, el puente queda apagado (las rutas /interno responden 404) y nada cambia.
    'puente' => [
        'secreto_entrada' => env('ECOSISTEMA_PUENTE_SECRETO_DASHBOARD'),
        'secreto_salida' => env('ECOSISTEMA_PUENTE_SECRETO_TICKETS'),
        // Direccion con la que este servidor llama al otro (por omision, la publica).
        'url' => rtrim((string) (env('ECOSISTEMA_PUENTE_DASHBOARD_URL') ?: env('ECOSISTEMA_DASHBOARD_URL', 'https://dashboard.zonaorientetm.tech')), '/'),
        // IPs desde las que se aceptan llamadas (separadas por coma; vacio = cualquiera, solo vale la firma).
        'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ECOSISTEMA_PUENTE_IPS', ''))))),
        // Diferencia maxima de reloj aceptada, en segundos.
        'ventana' => 300,
        'timeout' => 3,
    ],

    // Herramientas del Dashboard que el MCP publica (decision 97): cuanto se guarda su lista y cuanto se espera una
    // consulta (las consultas a secciones grandes tardan mas que un aviso).
    'ia' => [
        'cache_minutos' => 10,
        'timeout' => 30,
    ],

];
