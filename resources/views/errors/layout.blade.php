{{--
    Layout AUTONOMO de las pantallas de error. Deliberadamente no usa layouts.app, @vite, navegacion,
    permisos ni consultas: debe renderizar aunque la BD, la sesion o el manifest de Vite fallen (500/503).
    Estilos en un <style> propio (la CSP permite style-src 'unsafe-inline'; no hay scripts ni style="").
    Nunca imprime datos de la excepcion (mensaje, traza, rutas) ni distingue "no existe" de "sin permiso".
--}}
@php
    $isLoggedIn = false;
    $backUrl = null;

    try {
        $isLoggedIn = auth()->check();
    } catch (\Throwable) {
        $isLoggedIn = false; // BD o sesion caidas: se trata como invitado.
    }

    try {
        $previous = url()->previous();
        $backUrl = str_starts_with($previous, url('/')) ? $previous : null;
    } catch (\Throwable) {
        $backUrl = null;
    }

    try {
        $homeUrl = $isLoggedIn ? route('dashboard') : route('login');
        $loginUrl = route('login');
    } catch (\Throwable) {
        $homeUrl = $loginUrl = '/';
    }

    $actions = $actions ?? ['home', 'back'];
    $appName = config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#06534D">
    <title>{{ __('errors.'.$key.'.title') }} · {{ $appName }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
    <style>
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;background:#F2F7F5;color:#1f2937;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;line-height:1.5}
        main{width:100%;max-width:28rem;text-align:center}
        .logo{display:block;margin:0 auto 1.5rem;height:3rem;width:auto;aspect-ratio:21/10;object-fit:cover}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1.5rem;box-shadow:0 1px 2px rgba(0,0,0,.05)}
        .badge{display:inline-flex;align-items:center;justify-content:center;height:3.5rem;width:3.5rem;border-radius:9999px;background:#F2F7F5;color:#1F7460}
        .badge svg{height:1.75rem;width:1.75rem}
        .code{margin:1rem 0 0;font-size:.6875rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#1F7460}
        h1{margin:.25rem 0 0;font-size:1.25rem;font-weight:700;color:#06534D}
        p.msg{margin:.75rem 0 0;font-size:.875rem;color:#374151}
        .actions{display:flex;flex-direction:column;gap:.5rem;margin-top:1.5rem}
        .btn{display:inline-block;padding:.625rem 1rem;border-radius:.5rem;font-size:.875rem;font-weight:600;text-decoration:none;border:1px solid #06534D}
        .btn-primary{background:#06534D;color:#fff}
        .btn-primary:hover{background:#043D39}
        .btn-secondary{background:#fff;color:#06534D}
        .btn-secondary:hover{background:#F2F7F5}
        .btn:focus-visible{outline:2px solid #1F7460;outline-offset:2px}
        @media (min-width:640px){h1{font-size:1.5rem}.card{padding:2rem}.actions{flex-direction:row;justify-content:center}}
    </style>
</head>
<body>
    <main>
        <img class="logo" src="{{ asset('logo.png') }}" width="792" height="480" alt="{{ __('common.logo_alt') }}">
        <div class="card" role="alert">
            <span class="badge"><x-icon :name="$icon" /></span>
            <p class="code">{{ __('errors.code_label', ['code' => $code]) }}</p>
            <h1>{{ __('errors.'.$key.'.title') }}</h1>
            <p class="msg">{{ __('errors.'.$key.'.message') }}</p>

            <div class="actions">
                @if (in_array('home', $actions, true))
                    <a class="btn btn-primary" href="{{ $homeUrl }}">{{ $isLoggedIn ? __('errors.home_auth') : __('errors.home_guest') }}</a>
                @endif
                @if (in_array('login', $actions, true))
                    <a class="btn btn-primary" href="{{ $loginUrl }}">{{ __('errors.login_again') }}</a>
                @endif
                @if (in_array('retry', $actions, true) && request()->isMethod('GET'))
                    <a class="btn btn-secondary" href="{{ url()->current() }}">{{ __('errors.retry') }}</a>
                @endif
                @if (in_array('back', $actions, true) && $backUrl)
                    <a class="btn btn-secondary" href="{{ $backUrl }}">{{ __('errors.back') }}</a>
                @endif
            </div>
        </div>
    </main>
</body>
</html>
