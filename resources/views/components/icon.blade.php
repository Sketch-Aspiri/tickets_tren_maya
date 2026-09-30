@props(['name'])

@php
    // Iconos de trazo (viewBox 24). Rutas estaticas del codigo, nunca datos de usuario. Decorativos: el texto
    // del enlace/boton que los acompana es lo que anuncian los lectores de pantalla.
    $path = match ($name) {
        'home' => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
        'check' => 'M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'list' => 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
        'calendar' => 'M8 3v3M16 3v3M4 8h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z',
        'repeat' => 'M4 12V9a3 3 0 013-3h11l-3-3M20 12v3a3 3 0 01-3 3H6l3 3',
        'chart' => 'M4 20h16M7 16v-5M12 16V6M17 16v-8',
        'mail' => 'M4 6h16a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V7a1 1 0 011-1zM3 7l9 6 9-6',
        'bell' => 'M6 8a6 6 0 1112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0',
        'chat' => 'M4 5h16a1 1 0 011 1v10a1 1 0 01-1 1h-9l-5 4v-4H4a1 1 0 01-1-1V6a1 1 0 011-1z',
        'users' => 'M16 19v-1a4 4 0 00-4-4H7a4 4 0 00-4 4v1M9.5 10a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM21 19v-1a4 4 0 00-3-3.9M15.5 3.2a3.5 3.5 0 010 6.6',
        'flag' => 'M4 21V4M4 5h12l-2 4 2 4H4',
        'tag' => 'M3 12V4h8l10 10-8 8L3 12zM7.5 8h.01',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4',
        'user' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
        'logout' => 'M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M15 8l4 4-4 4M19 12H9',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'close' => 'M6 18L18 6M6 6l12 12',
        'plus' => 'M12 5v14M5 12h14',
        'inbox' => 'M3 13l3-8h12l3 8v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM3 13h5l1 3h6l1-3h5',
        'search' => 'M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z',
        'arrow-right' => 'M5 12h14M13 6l6 6-6 6',
        'lock' => 'M6 11h12a1 1 0 011 1v8a1 1 0 01-1 1H6a1 1 0 01-1-1v-8a1 1 0 011-1zM8 11V8a4 4 0 118 0v3',
        'clock' => 'M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'wrench' => 'M14.7 6.3a4 4 0 005 5L21 13l-8 8-4-4 8-8-2.3-2.7zM9 17l-5 5',
        'arrow-uturn-left' => 'M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3',
        'alert' => 'M12 9v4M12 17h.01M10.3 4l-8 14a2 2 0 001.7 3h16a2 2 0 001.7-3l-8-14a2 2 0 00-3.4 0z',
        default => 'M12 8v5M12 16h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    };
@endphp

<svg {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }} fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $path }}"/></svg>
