@props(['activity'])

{{-- Distingue la plantilla de una serie recurrente y sus instancias (con texto + icono, no solo color).
     Las actividades normales no muestran nada. Requiere `parent` cargado para las instancias. --}}
@if ($activity->isTemplate())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-semibold text-sky-900']) }}>
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        {{ __('activities.badges.template') }}
    </span>
@elseif ($activity->isInstance())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-300']) }}>
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        {{ __('activities.badges.instance', ['folio' => $activity->parent?->folio ?? __('common.none')]) }}
    </span>
@endif
