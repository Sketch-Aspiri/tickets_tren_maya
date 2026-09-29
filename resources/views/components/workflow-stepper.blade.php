@props(['status'])

@php
    /** @var \App\Enums\TicketStatus $status */
    $steps = [
        \App\Enums\TicketStatus::Pending,
        \App\Enums\TicketStatus::InProgress,
        \App\Enums\TicketStatus::InReview,
        \App\Enums\TicketStatus::Completed,
    ];
    $current = array_search($status, $steps, true);
@endphp

{{-- Indicador del flujo Pendiente > En proceso > En revision > Completado. Un estado cancelado queda fuera del flujo.
     El paso se comunica con texto (etiqueta + texto para lectores), no solo con color. Cuatro pasos caben en 360 px. --}}
@if ($current === false)
    <p {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-800']) }}>
        <x-icon name="close" class="h-4 w-4" />
        {{ __('workflow.cancelled') }}
    </p>
@else
    <ol {{ $attributes->merge(['class' => 'flex items-start']) }} aria-label="{{ __('workflow.label') }}">
        @foreach ($steps as $index => $step)
            @php
                $state = $index < $current || ($index === $current && $step === \App\Enums\TicketStatus::Completed) ? 'done' : ($index === $current ? 'current' : 'todo');
                $circleClasses = match ($state) {
                    'done' => 'bg-brand-green text-white',
                    'current' => 'border-2 border-brand-green bg-white text-brand-green ring-4 ring-brand-mint/40',
                    default => 'border-2 border-gray-300 bg-white text-gray-600',
                };
                $labelClasses = match ($state) {
                    'current' => 'font-semibold text-gray-900',
                    'done' => 'font-medium text-brand-green',
                    default => 'text-gray-600',
                };
                $stateText = match ($state) {
                    'done' => __('workflow.step_done'),
                    'current' => __('workflow.step_current'),
                    default => __('workflow.step_todo'),
                };
            @endphp
            <li class="relative flex min-w-0 flex-1 flex-col items-center px-0.5 text-center" @if ($state === 'current') aria-current="step" @endif>
                @if ($index > 0)
                    <span class="absolute start-[-50%] top-4 h-0.5 w-full {{ $index <= $current ? 'bg-brand-green' : 'bg-gray-300' }}" aria-hidden="true"></span>
                @endif
                <span class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold {{ $circleClasses }}" aria-hidden="true">
                    @if ($state === 'done')
                        <x-icon name="check" class="h-5 w-5" />
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>
                <span class="mt-1.5 text-[11px] leading-tight sm:text-xs {{ $labelClasses }}">
                    {{ $step->label() }}
                    <span class="sr-only">({{ $stateText }})</span>
                </span>
            </li>
        @endforeach
    </ol>
@endif
