<x-app-layout>
    <x-slot name="title">{{ $team->name }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ $team->name }} <span class="text-sm font-normal text-gray-600">· {{ $team->coordination->station->name }} › {{ $team->coordination->name }}</span></h1>
            <x-secondary-button :href="route('teams.index')">{{ __('common.actions.back') }}</x-secondary-button>
        </div>
    </x-slot>

    <p class="text-sm text-gray-700">{{ __('teams.columns.coordinator') }}: {{ $team->coordinatorNames() ?: __('teams.form.no_coordinator') }}</p>

    <h2 class="mt-4 text-base font-semibold text-brand-green">{{ __('teams.columns.members') }}</h2>
    @include('org.partials.users-table', ['users' => $users])
</x-app-layout>
