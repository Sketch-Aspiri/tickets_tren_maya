{{-- <head> compartido por layouts app y guest. Todo se sirve desde el mismo origen (CSP). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#06534D">

<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" sizes="256x256">
<link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">

{{-- PWA instalable + Web Push (llave pública VAPID; la privada nunca sale del servidor). --}}
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Tickets TM">
@if (config('webpush.vapid.public_key'))
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
