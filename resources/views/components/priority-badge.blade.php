@props(['priority'])

@php
    /** @var \App\Enums\Priority $priority */
    // La prioridad se comunica con TEXTO y con el numero de barras (no solo con el color).
    [$classes, $bars] = match ($priority) {
        \App\Enums\Priority::Low => ['bg-gray-100 text-gray-800 ring-gray-300', 1],
        \App\Enums\Priority::Medium => ['bg-sky-50 text-sky-900 ring-sky-300', 2],
        \App\Enums\Priority::High => ['bg-amber-50 text-amber-900 ring-amber-400', 3],
        \App\Enums\Priority::Urgent => ['bg-red-50 text-red-900 ring-red-400', 4],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    <span class="flex items-end gap-px" aria-hidden="true">
        @foreach (['h-1.5', 'h-2', 'h-2.5', 'h-3'] as $index => $height)
            <span class="w-0.5 rounded-sm {{ $height }} {{ $index < $bars ? 'bg-current' : 'bg-current opacity-25' }}"></span>
        @endforeach
    </span>
    {{ $priority->label() }}
</span>
