{{-- <head> compartido por layouts app y guest. Todo se sirve desde el mismo origen (CSP). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#06534D">

<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" sizes="256x256">
<link rel="apple-touch-icon" href="{{ asset('favicon.png') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
