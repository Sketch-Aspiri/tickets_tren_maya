{{-- Selector multiple de equipos (un usuario puede pertenecer a varios). Envia `team_ids[]`.
     $teams: colección de equipos (id, name). $selected: ids marcados. $prefix: prefijo de ids únicos por formulario. --}}
@props(['teams', 'selected' => [], 'prefix' => 'team', 'legend', 'hint' => null])

@php
    $selectedIds = array_map('intval', (array) $selected);
@endphp

<fieldset {{ $attributes }}>
    <legend class="block text-sm font-medium text-gray-700">{{ $legend }}</legend>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
    @endif

    <div class="mt-2 grid gap-1 sm:grid-cols-2">
        @forelse ($teams as $team)
            <label for="{{ $prefix }}_{{ $team->id }}" class="flex min-h-[44px] items-center gap-2 rounded-md px-2 text-sm text-gray-800 hover:bg-brand-teal/5">
                <input
                    id="{{ $prefix }}_{{ $team->id }}"
                    type="checkbox"
                    name="team_ids[]"
                    value="{{ $team->id }}"
                    class="h-4 w-4 rounded border-gray-300 text-brand-green focus:ring-brand-teal"
                    @checked(in_array((int) $team->id, $selectedIds, true))
                >
                <span>{{ $team->name }}</span>
            </label>
        @empty
            <p class="text-sm text-gray-500">{{ __('users.show.no_teams_available') }}</p>
        @endforelse
    </div>
</fieldset>
