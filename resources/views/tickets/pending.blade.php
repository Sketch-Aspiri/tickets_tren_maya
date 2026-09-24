<x-app-layout>
    <x-slot name="title">{{ __('tickets.pending_title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('tickets.pending_title') }}</h1>
    </x-slot>

    <p class="text-sm text-gray-700">{{ __('tickets.pending_intro') }}</p>

    <x-ticket-table :tickets="$tickets" :show-team="auth()->user()->team_id === null" :empty-message="__('tickets.pending_empty')" />

    {{ $tickets->links() }}
</x-app-layout>
