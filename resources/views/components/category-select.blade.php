@props([
    'categories',
    'selected' => null,
    'id' => 'category_id',
    'name' => 'category_id',
    'label',
    'placeholder',
    'inactiveLabel' => null,
    'messages' => null,
])

{{-- Categorías agrupadas: «General» (sin coordinación, sirven en todas) y una por «Edificio › Coordinación», así dos
     categorías con el mismo nombre en coordinaciones distintas no se confunden. Si el formulario tiene la cascada
     de equipo, solo se ofrecen las generales y las de la coordinación elegida (Alpine.data('coordinationFilter')).
     `$categories` debe traer `coordination.station` cargada (o `coordination_id` nulo). --}}
@php
    $groups = $categories
        ->sortBy(fn ($category): string => mb_strtolower(($category->coordination ? '1|'.$category->coordination->station->name.'|'.$category->coordination->name : '0|').'|'.$category->name))
        ->groupBy(fn ($category): string => (string) ($category->coordination_id ?? ''));
@endphp

<div x-data="coordinationFilter" x-on:cascade-coordination.window="onCoordination" {{ $attributes }}>
    <x-input-label :for="$id" :value="$label" />
    <x-select-input :id="$id" :name="$name" class="mt-1 block w-full">
        <option value="">{{ $placeholder }}</option>
        @foreach ($groups as $coordinationId => $group)
            @php $coordination = $group->first()->coordination; @endphp
            <optgroup label="{{ $coordination ? $coordination->station->name.' › '.$coordination->name : __('categories.general') }}">
                @foreach ($group as $category)
                    <option value="{{ $category->id }}" data-coordinations="{{ $coordinationId }}" @selected((int) $selected === $category->id)>{{ $category->name }}{{ $inactiveLabel && ! $category->active ? ' '.$inactiveLabel : '' }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </x-select-input>
    @if ($messages)
        <x-input-error :messages="$messages" class="mt-2" />
    @endif
</div>
