<x-app-layout>
    <x-slot name="title">{{ $station->name }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ $station->name }}</h1>
            <x-secondary-button :href="route('stations.index')">{{ __('common.actions.back') }}</x-secondary-button>
        </div>
    </x-slot>

    <x-card>
        <h2 class="text-base font-semibold text-brand-green">{{ __('stations.columns.coordinations') }}</h2>
        <ul class="mt-2 divide-y divide-brand-green/10">
            @forelse ($station->coordinations as $coordination)
                <li class="flex justify-between py-2">
                    <a href="{{ route('coordinations.show', $coordination) }}" class="font-medium text-brand-green hover:underline">{{ $coordination->name }}</a>
                    <span class="text-sm text-gray-600">{{ trans_choice('stations.teams_count', $coordination->teams_count, ['count' => $coordination->teams_count]) }}</span>
                </li>
            @empty
                <li class="py-2 text-gray-600">{{ __('common.empty') }}</li>
            @endforelse
        </ul>
    </x-card>

    <h2 class="mt-6 text-base font-semibold text-brand-green">{{ __('stations.users') }}</h2>
    @include('org.partials.users-table', ['users' => $users])
</x-app-layout>
