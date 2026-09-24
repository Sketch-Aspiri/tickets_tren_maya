@props(['percent' => 0, 'label' => null])

{{-- Avance 0-100. Se comunica con TEXTO (el porcentaje) ademas de la barra; <progress> es nativo, accesible y no
     necesita estilos en linea (compatible con la CSP). --}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <progress value="{{ $percent }}" max="100" role="progressbar" aria-label="{{ $label ?? __('activities.columns.progress') }}" class="h-2.5 min-w-[4rem] flex-1 appearance-none overflow-hidden rounded-full [&::-moz-progress-bar]:bg-brand-teal [&::-webkit-progress-bar]:bg-gray-200 [&::-webkit-progress-value]:bg-brand-teal">{{ $percent }} %</progress>
    <span class="w-11 shrink-0 text-right text-xs font-semibold tabular-nums text-gray-900">{{ $percent }} %</span>
</div>
