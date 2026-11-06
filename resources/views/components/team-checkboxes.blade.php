{{-- Selector multiple de equipos (un usuario puede pertenecer a varios). Envia `team_ids[]`.
     $teams: colección de equipos con `coordination.station` cargada; se agrupan por «Edificio › Coordinación» para
     no confundir equipos homónimos de coordinaciones distintas. $selected: ids marcados. $prefix: prefijo de ids. --}}
@props(['teams', 'selected' => [], 'prefix' => 'team', 'legend', 'hint' => null, 'primaryCoordinations' => []])

@php
    $selectedIds = array_map('intval', (array) $selected);
    $teams->loadMissing('coordination.station');
    $groups = $teams
        ->sortBy(fn ($team): string => mb_strtolower($team->coordination->station->name.'|'.$team->coordination->name.'|'.$team->name))
        ->groupBy('coordination_id');

    // Con coordinaciones "propias" de la persona solo esas se muestran; las demas van plegadas (y se abren solas si
    // ya tiene marcado algun equipo de ellas). Sin ninguna propia (cuenta sin ubicar) se muestran todas.
    $primaryIds = array_map('intval', (array) $primaryCoordinations);
    $primary = $primaryIds === [] ? $groups : $groups->filter(fn ($group, $coordinationId): bool => in_array((int) $coordinationId, $primaryIds, true));
    $others = $primaryIds === [] ? collect() : $groups->reject(fn ($group, $coordinationId): bool => in_array((int) $coordinationId, $primaryIds, true));
    $othersSelected = $others->flatten()->contains(fn ($team): bool => in_array((int) $team->id, $selectedIds, true));
@endphp

<fieldset {{ $attributes }}>
    <legend class="block text-sm font-medium text-gray-700">{{ $legend }}</legend>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
    @endif

    <div class="mt-2 space-y-3">
        @forelse ($primary as $group)
            @include('components.partials.team-checkbox-group', ['group' => $group, 'prefix' => $prefix, 'selectedIds' => $selectedIds])
        @empty
            <p class="text-sm text-gray-500">{{ __('users.show.no_teams_available') }}</p>
        @endforelse

        @if ($others->isNotEmpty())
            <details class="rounded-lg border border-gray-200 px-3 py-2" @if ($othersSelected) open @endif>
                <summary class="min-h-[44px] cursor-pointer py-2 text-sm font-medium text-brand-teal focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('users.show.other_coordinations_teams') }}</summary>
                <div class="mt-2 space-y-3">
                    @foreach ($others as $group)
                        @include('components.partials.team-checkbox-group', ['group' => $group, 'prefix' => $prefix, 'selectedIds' => $selectedIds])
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</fieldset>
