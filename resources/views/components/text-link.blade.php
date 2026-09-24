{{-- Enlace de texto (o boton con aspecto de enlace si no hay `href`). Objetivo tactil >= 44px. --}}
@php
    $classes = 'inline-flex min-h-[44px] items-center rounded-sm text-sm font-medium text-brand-teal underline underline-offset-2 hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2';
@endphp

@if ($attributes->has('href'))
    <a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>{{ $slot }}</button>
@endif
