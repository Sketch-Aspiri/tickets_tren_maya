{{-- Boton primario. Con `href` se renderiza como enlace con el mismo aspecto. --}}
@php
    $classes = 'inline-flex min-h-[44px] items-center justify-center rounded-md border border-transparent bg-brand-green px-5 py-2 text-sm font-semibold tracking-wide text-white shadow-sm transition-colors hover:bg-brand-green-dark active:bg-brand-green-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 disabled:opacity-50 motion-reduce:transition-none';
@endphp

@if ($attributes->has('href'))
    <a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $classes]) }}>{{ $slot }}</button>
@endif
