<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Politica de contrasenas
    |--------------------------------------------------------------------------
    | Longitud minima, mayusculas/minusculas y numeros. `check_breached`
    | consulta la API de contrasenas filtradas (desactivado en pruebas y local).
    */
    'password' => [
        'min_length' => 10,
        'check_breached' => (bool) env('PASSWORD_CHECK_BREACHED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites de peticiones (RateLimiter)
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'login_per_minute' => 5,
        'login_per_ip_per_minute' => 20,
        'register_per_hour' => 10,
        'password_reset_per_minute' => 5,
        'two_factor_per_minute' => 5,
        // Por usuario autenticado (Sprint 2).
        'ticket_create_per_hour' => 30,
        'ticket_write_per_minute' => 60,
        'ticket_comment_per_minute' => 20,
        'ticket_upload_per_minute' => 10,
        'attachment_download_per_minute' => 60,
        // Por usuario autenticado (Sprint 3, actividades).
        'activity_create_per_hour' => 30,
        'activity_write_per_minute' => 60,
        'activity_comment_per_minute' => 20,
        'activity_upload_per_minute' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Autenticacion en dos pasos
    |--------------------------------------------------------------------------
    */
    'two_factor' => [
        'recovery_codes' => 8,
        'qr_size' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxies y hosts de confianza
    |--------------------------------------------------------------------------
    | Listas separadas por comas en el .env (TRUSTED_PROXIES, TRUSTED_HOSTS).
    | Vacias por defecto: en local no se confia en ningun proxy y TrustHosts no
    | actua. Sin TRUSTED_HOSTS, staging/produccion solo aceptan el host exacto de APP_URL.
    */
    'http' => [
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
        'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Datos demo (solo local)
    |--------------------------------------------------------------------------
    | Si `demo_password` es nulo, el seeder genera una contrasena aleatoria y
    | la imprime en consola. Si se define, debe cumplir la politica de contrasenas.
    */
    'demo_password' => env('DEMO_USER_PASSWORD') ?: null,

    'users_per_page' => 15,

    /*
    |--------------------------------------------------------------------------
    | Tickets (Sprint 2)
    |--------------------------------------------------------------------------
    */
    'tickets_per_page' => 15,
    'categories_per_page' => 15,
    'folio_prefixes' => [
        'ticket' => 'TM',
        'activity' => 'ACT',
    ],
    'max_collaborators' => 10,
    'comment_max_length' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Actividades (Sprint 3)
    |--------------------------------------------------------------------------
    | `max_subtasks`: tope de subtareas por actividad (acota el trabajo de las consultas de avance).
    | `recurrence.horizon_days`: hasta cuantos dias por delante de HOY (hora de negocio, inclusive) genera
    | instancias `activities:generate-recurring`. `recurrence.schedule_at`: hora local (America/Cancun) de la
    | ejecucion diaria programada.
    */
    'activities_per_page' => 15,
    'max_subtasks' => 50,
    'recurrence' => [
        'horizon_days' => (int) env('RECURRENCE_HORIZON_DAYS', 14),
        'schedule_at' => env('RECURRENCE_SCHEDULE_AT', '02:00'),
    ],

    /*
    | Adjuntos: disco PRIVADO (storage/app/private, nunca public/). Un tipo solo se acepta si la
    | extension esta en la lista blanca Y el MIME detectado por el contenido (finfo) corresponde a
    | esa extension. `max_kilobytes` y `max_per_ticket` (por ticket o por actividad) son ajustables.
    */
    'attachments' => [
        'disk' => 'local',
        'directory' => 'tickets',
        // Los adjuntos de actividades van en otro directorio: los ids de tickets y actividades pueden coincidir.
        'activity_directory' => 'activities',
        'max_kilobytes' => (int) env('ATTACHMENT_MAX_KB', 10240),
        'max_per_ticket' => (int) env('ATTACHMENT_MAX_PER_TICKET', 10),
        'allowed' => [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'txt' => ['text/plain'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
        ],
    ],
];
