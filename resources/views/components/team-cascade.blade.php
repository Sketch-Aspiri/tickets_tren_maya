@props([
    'teams',
    'selected' => null,
    'name' => 'team_id',
    'id' => 'team_id',
    'label' => null,
    'hint' => null,
    'required' => false,
    'optional' => false,
    'messages' => null,
    'coordinationName' => null,
    'selectedCoordination' => null,
])

{{-- Selector en cascada Edificio → Coordinación → Equipo. Los equipos nunca se listan revueltos: sin JavaScript
     se agrupan por coordinación (<optgroup>); con JavaScript (Alpine.data('teamCascade'), resources/js/org.js) cada
     nivel solo muestra lo que pertenece al anterior. Solo se envía `$name` (y `$coordinationName` si se indica, para
     filtros por coordinación); el edificio es solo de apoyo. `$teams` debe traer `coordination.station` cargada. --}}
@php
    $teams->loadMissing('coordination.station');

    $sorted = $teams
        ->sortBy(fn ($team): string => mb_strtolower($team->coordination->station->name.'|'.$team->coordination->name.'|'.$team->name))
        ->values();
    $coordinations = $sorted->pluck('coordination')->unique('id')->values();
    $stations = $coordinations->pluck('station')->unique('id')->sortBy('name')->values();

    $selectedTeam = $selected ? $sorted->firstWhere('id', (int) $selected) : null;
    $selectedCoordinationId = $selectedTeam?->coordination_id
        ?? ($selectedCoordination ? $coordinations->firstWhere('id', (int) $selectedCoordination)?->id : null)
        ?? ($coordinations->count() === 1 ? $coordinations->first()->id : null);
    $selectedStationId = $coordinations->firstWhere('id', $selectedCoordinationId)?->station_id
        ?? ($stations->count() === 1 ? $stations->first()->id : null);

    $labels = [
        'station' => $optional ? __('cascade.all_stations') : __('cascade.pick_station'),
        'coordination' => $optional ? __('cascade.all_coordinations') : __('cascade.pick_coordination'),
        'team' => $optional ? __('cascade.all_teams') : __('cascade.pick_team'),
        'pickFirst' => __('cascade.pick_first'),
        'pickFirstCoordination' => __('cascade.pick_first_coordination'),
    ];
@endphp

<div x-data="teamCascade"
     data-labels="{{ json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
     data-auto-team="{{ $optional ? '0' : '1' }}"
     {{ $attributes->class(['grid gap-3 sm:grid-cols-3']) }}>
    <div data-cascade-wrap="station" @class(['hidden' => $stations->count() <= 1])>
        <x-input-label :for="$id.'_station'" :value="__('cascade.station')" />
        <x-select-input :id="$id.'_station'" data-cascade="station" x-on:change="onStation" class="mt-1 block w-full">
            <option value="">{{ $labels['station'] }}</option>
            @foreach ($stations as $station)
                <option value="{{ $station->id }}" @selected($selectedStationId === $station->id)>{{ $station->name }}</option>
            @endforeach
        </x-select-input>
    </div>

    <div data-cascade-wrap="coordination" @class(['hidden' => $coordinations->count() <= 1])>
        <x-input-label :for="$id.'_coordination'" :value="__('cascade.coordination')" />
        <x-select-input :id="$id.'_coordination'" :name="$coordinationName" data-cascade="coordination" x-on:change="onCoordination" class="mt-1 block w-full">
            <option value="">{{ $labels['coordination'] }}</option>
            @foreach ($coordinations as $coordination)
                <option value="{{ $coordination->id }}" data-parent="{{ $coordination->station_id }}" @selected($selectedCoordinationId === $coordination->id)>{{ $coordination->name }}</option>
            @endforeach
        </x-select-input>
    </div>

    <div data-cascade-wrap="team">
        <x-input-label :for="$id" :value="$label ?? __('cascade.team')" />
        <x-select-input :id="$id" :name="$name" data-cascade="team" class="mt-1 block w-full" :required="$required" :aria-describedby="$hint ? $id.'_hint' : null">
            <option value="">{{ $labels['team'] }}</option>
            @foreach ($sorted->groupBy('coordination_id') as $group)
                <optgroup label="{{ $group->first()->coordination->station->name }} › {{ $group->first()->coordination->name }}">
                    @foreach ($group as $team)
                        <option value="{{ $team->id }}" data-parent="{{ $team->coordination_id }}" @selected($selectedTeam?->id === $team->id)>{{ $team->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </x-select-input>
        @if ($hint)
            <p id="{{ $id }}_hint" class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
        @endif
        @if ($messages)
            <x-input-error :messages="$messages" class="mt-2" />
        @endif
    </div>
</div>
