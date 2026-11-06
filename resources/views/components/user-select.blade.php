@props([
    'users',
    'selected' => null,
    'id',
    'name',
    'label',
    'placeholder',
])

{{-- Select de personas agrupado por «Edificio › Coordinación» (la de sus equipos). Una persona en dos coordinaciones
     aparece en el grupo de la primera; `data-coordinations` lleva todas, así la cascada del formulario filtra bien.
     `$users` debe traer `teams.coordination.station` cargada. --}}
@php
    $coordinationOf = fn ($user) => $user->teams->pluck('coordination')->filter()->sortBy('name')->first();
    $groups = $users
        ->sortBy(fn ($user): string => mb_strtolower(($coordinationOf($user) ? '0|'.$coordinationOf($user)->station->name.'|'.$coordinationOf($user)->name : '1|').'|'.$user->name))
        ->groupBy(fn ($user): string => (string) ($coordinationOf($user)?->id ?? ''));
@endphp

<div x-data="coordinationFilter" x-on:cascade-coordination.window="onCoordination" {{ $attributes }}>
    <x-input-label :for="$id" :value="$label" />
    <x-select-input :id="$id" :name="$name" class="mt-1 block w-full">
        <option value="">{{ $placeholder }}</option>
        @foreach ($groups as $group)
            @php $coordination = $coordinationOf($group->first()); @endphp
            <optgroup label="{{ $coordination ? $coordination->station->name.' › '.$coordination->name : __('users.index.no_coordination_group') }}">
                @foreach ($group as $user)
                    <option value="{{ $user->id }}" data-coordinations="{{ $user->teams->pluck('coordination_id')->filter()->unique()->implode(',') }}" @selected((int) $selected === $user->id)>{{ $user->name }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </x-select-input>
</div>
