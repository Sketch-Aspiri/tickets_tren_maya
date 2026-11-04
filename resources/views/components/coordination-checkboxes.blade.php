{{-- Selector multiple de coordinaciones (solo jefes de zona). Envia `coordination_ids[]`.
     $coordinations: coleccion con `station`. $selected: ids marcados. $prefix: prefijo de ids unicos por formulario. --}}
@props(['coordinations', 'selected' => [], 'prefix' => 'coordination', 'legend', 'hint' => null])

@php
    $selectedIds = array_map('intval', (array) $selected);
@endphp

<fieldset {{ $attributes }}>
    <legend class="block text-sm font-medium text-gray-700">{{ $legend }}</legend>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
    @endif

    <div class="mt-2 grid gap-1 sm:grid-cols-2">
        @forelse ($coordinations as $coordination)
            <label for="{{ $prefix }}_{{ $coordination->id }}" class="flex min-h-[44px] items-center gap-2 rounded-md px-2 text-sm text-gray-800 hover:bg-brand-teal/5">
                <input
                    id="{{ $prefix }}_{{ $coordination->id }}"
                    type="checkbox"
                    name="coordination_ids[]"
                    value="{{ $coordination->id }}"
                    class="h-4 w-4 rounded border-gray-300 text-brand-green focus:ring-brand-teal"
                    @checked(in_array((int) $coordination->id, $selectedIds, true))
                >
                <span>{{ $coordination->station->name }} › {{ $coordination->name }}</span>
            </label>
        @empty
            <p class="text-sm text-gray-500">{{ __('users.show.no_coordinations_available') }}</p>
        @endforelse
    </div>
</fieldset>
