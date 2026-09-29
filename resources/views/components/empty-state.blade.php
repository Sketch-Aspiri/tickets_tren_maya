@props(['title', 'icon' => 'inbox', 'description' => null])

{{-- Estado vacio: icono, mensaje y (opcional) una accion util en el slot. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-4 py-10 text-center']) }}>
    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-mist text-brand-teal" aria-hidden="true">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>
    <p class="mt-3 text-sm font-semibold text-gray-900">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-gray-600">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
