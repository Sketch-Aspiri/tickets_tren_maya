@props(['active' => false])

@php
    $classes = $active
        ? 'border-brand-teal bg-brand-mint/20 font-semibold text-brand-green'
        : 'border-transparent font-medium text-gray-700 hover:bg-brand-mist hover:text-brand-green';
@endphp

<a {{ $attributes->merge(['class' => "flex min-h-[44px] items-center rounded-md border-s-4 px-3 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal {$classes}"]) }} @if ($active) aria-current="page" @endif>
    {{ $slot }}
</a>
