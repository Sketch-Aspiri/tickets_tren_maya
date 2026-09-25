@php
    $isTeam = $scope === \App\Enums\PendingScope::Team;
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('tickets.pending_title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('tickets.pending_title') }}</h1>
    </x-slot>

    {{-- Alternar entre lo propio y lo del equipo: solo coordinadores (enlaces GET, sin JavaScript). --}}
    @if ($scopes !== [])
        <nav aria-label="{{ __('tickets.pending_scope.legend') }}" class="inline-flex overflow-hidden rounded-md border border-brand-teal bg-white shadow-sm">
            @foreach ($scopes as $option)
                <a href="{{ route('tickets.pending', ['scope' => $option->value]) }}"
                   @if ($option === $scope) aria-current="page" @endif
                   class="inline-flex min-h-[44px] items-center px-5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal {{ $option === $scope ? 'bg-brand-green text-white' : 'text-brand-green hover:bg-brand-mist' }}">
                    {{ $option->label() }}
                </a>
            @endforeach
        </nav>
    @endif

    <p class="text-sm text-gray-700">{{ $isTeam ? __('tickets.pending_intro_team') : __('tickets.pending_intro') }}</p>

    {{-- Dos secciones claras, cada una con su propia paginación (`page` y `activities_page`). --}}
    <section aria-labelledby="pending-tickets" class="space-y-3">
        <h2 id="pending-tickets" class="text-lg font-semibold text-brand-green">{{ __('tickets.pending_tickets_heading') }}</h2>

        <x-ticket-table :tickets="$tickets" :show-team="auth()->user()->mustChooseTeam()" :empty-message="$isTeam ? __('tickets.pending_empty_team') : __('tickets.pending_empty')" />

        {{ $tickets->links() }}
    </section>

    <section aria-labelledby="pending-activities" class="space-y-3">
        <h2 id="pending-activities" class="text-lg font-semibold text-brand-green">{{ __('tickets.pending_activities_heading') }}</h2>

        <x-activity-table :activities="$activities" :show-team="auth()->user()->mustChooseTeam()" :empty-message="$isTeam ? __('tickets.pending_empty_team') : __('activities.pending_empty')" />

        {{ $activities->links() }}
    </section>
</x-app-layout>
