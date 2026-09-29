@props(['active' => false, 'icon' => null])

@php
    $classes = $active
        ? 'bg-brand-green text-white font-semibold shadow-sm'
        : 'text-gray-700 font-medium hover:bg-brand-mist hover:text-brand-green';
    $iconClasses = $active ? 'text-brand-mint' : 'text-brand-teal group-hover:text-brand-green';
@endphp

{{-- Enlace del menu lateral: icono + texto; el activo se distingue por fondo solido (no solo color) y aria-current. --}}
<a {{ $attributes->merge(['class' => "group flex min-h-[44px] items-center gap-3 rounded-lg px-3 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal {$classes}"]) }} @if ($active) aria-current="page" @endif>
    @if ($icon)
        <x-icon :name="$icon" class="h-5 w-5 shrink-0 {{ $iconClasses }}" />
    @endif
    <span class="min-w-0 flex-1">{{ $slot }}</span>
</a>
