<x-app-layout>
    <x-slot name="title">{{ __('tickets.pending_title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('tickets.pending_title') }}</h1>
    </x-slot>

    <p class="text-sm text-gray-700">{{ __('tickets.pending_intro') }}</p>

    {{-- Dos secciones claras, cada una con su propia paginación (`page` y `activities_page`). --}}
    <section aria-labelledby="pending-tickets" class="space-y-3">
        <h2 id="pending-tickets" class="text-lg font-semibold text-brand-green">{{ __('tickets.pending_tickets_heading') }}</h2>

        <x-ticket-table :tickets="$tickets" :show-team="auth()->user()->team_id === null" :empty-message="__('tickets.pending_empty')" />

        {{ $tickets->links() }}
    </section>

    <section aria-labelledby="pending-activities" class="space-y-3">
        <h2 id="pending-activities" class="text-lg font-semibold text-brand-green">{{ __('tickets.pending_activities_heading') }}</h2>

        <x-activity-table :activities="$activities" :show-team="auth()->user()->team_id === null" :empty-message="__('activities.pending_empty')" />

        {{ $activities->links() }}
    </section>
</x-app-layout>
