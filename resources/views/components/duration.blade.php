@props(['seconds'])

@php
    $total = max((int) $seconds, 0);
    $days = intdiv($total, 86400);
    $hours = intdiv($total % 86400, 3600);
    $minutes = intdiv($total % 3600, 60);

    $text = match (true) {
        $days > 0 => trim(__('tracking.duration.days', ['count' => $days]).' '.($hours > 0 ? __('tracking.duration.hours', ['count' => $hours]) : '')),
        $hours > 0 => trim(__('tracking.duration.hours', ['count' => $hours]).' '.($minutes > 0 ? __('tracking.duration.minutes', ['count' => $minutes]) : '')),
        $minutes > 0 => __('tracking.duration.minutes', ['count' => $minutes]),
        default => __('tracking.duration.less_than_minute'),
    };
@endphp

{{-- Duracion legible (dias/horas/minutos) a partir de segundos. --}}
<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ $text }}</span>
