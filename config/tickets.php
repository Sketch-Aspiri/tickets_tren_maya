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
];
