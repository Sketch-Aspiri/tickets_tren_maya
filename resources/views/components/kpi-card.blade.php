@props(['label', 'value', 'tickets' => null, 'activities' => null, 'note' => null, 'tone' => 'default'])

@php
    $valueClass = $tone === 'danger' ? 'text-red-700' : 'text-brand-green';
    $accent = $tone === 'danger' ? 'border-t-red-600' : 'border-t-brand-teal';
@endphp

{{-- Tarjeta de indicador (panel de seguimiento). El numero se comunica con texto; el color es solo un refuerzo. --}}
<section {{ $attributes->merge(['class' => "rounded-xl border border-t-4 border-brand-green/10 bg-white p-4 shadow-sm {$accent}"]) }}>
    <h3 class="text-sm font-semibold text-gray-700">{{ $label }}</h3>
    <p class="mt-2 text-3xl font-bold tabular-nums {{ $valueClass }}">{{ number_format((int) $value) }}</p>

    @if ($tickets !== null && $activities !== null)
        <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700">
            <div class="flex gap-1">
                <dt>{{ __('tracking.cards.tickets') }}:</dt>
                <dd class="font-semibold tabular-nums">{{ number_format((int) $tickets) }}</dd>
            </div>
            <div class="flex gap-1">
                <dt>{{ __('tracking.cards.activities') }}:</dt>
                <dd class="font-semibold tabular-nums">{{ number_format((int) $activities) }}</dd>
            </div>
        </dl>
    @endif

    @if ($note)
        <p class="mt-2 text-xs text-gray-600">{{ $note }}</p>
    @endif
</section>
