<x-app-layout>
    <x-slot name="title">{{ $coordination->name }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ $coordination->name }} <span class="text-sm font-normal text-gray-600">· {{ $coordination->station->name }}</span></h1>
            <x-secondary-button :href="route('coordinations.index')">{{ __('common.actions.back') }}</x-secondary-button>
        </div>
    </x-slot>

    <x-card>
        <h2 class="text-base font-semibold text-brand-green">{{ __('coordinations.columns.teams') }}</h2>
        <ul class="mt-2 divide-y divide-brand-green/10">
            @forelse ($coordination->teams as $team)
                <li class="flex justify-between py-2">
                    <a href="{{ route('teams.show', $team) }}" class="font-medium text-brand-green hover:underline">{{ $team->name }}</a>
                    <span class="text-sm text-gray-600">{{ $team->coordinator?->name ?? __('teams.form.no_coordinator') }} · {{ $team->members_count }}</span>
                </li>
            @empty
                <li class="py-2 text-gray-600">{{ __('common.empty') }}</li>
            @endforelse
        </ul>
    </x-card>

    <h2 class="mt-6 text-base font-semibold text-brand-green">{{ __('coordinations.users') }}</h2>
    @include('org.partials.users-table', ['users' => $users])
</x-app-layout>
