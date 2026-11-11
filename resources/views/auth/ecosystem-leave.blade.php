{{--
    Cierre de sesion unico (decision 88). Tickets cierra su sesion y despues debe avisar al Dashboard,
    pero la CSP `form-action 'self'` bloquea que el POST de "Cerrar sesion" redirija a otro dominio. Esta
    pagina (sin scripts) hace esa navegacion con <meta http-equiv="refresh">, que la CSP permite.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="0;url={{ $url }}">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('auth.ecosystem.leaving') }} · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, sans-serif; background: #F2F7F5; color: #1f2937; }
        a { color: #1F7460; font-weight: 600; }
        @media (prefers-color-scheme: dark) { body { background: #0b1513; color: #e4eeeb; } a { color: #6fcab3; } }
    </style>
</head>
<body>
    <p>{{ __('auth.ecosystem.leaving') }} <a href="{{ $url }}">{{ __('auth.ecosystem.continue') }}</a></p>
</body>
</html>
