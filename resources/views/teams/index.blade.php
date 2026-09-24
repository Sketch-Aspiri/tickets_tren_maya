<x-app-layout>
    <x-slot name="title">{{ __('teams.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('teams.title') }}</h1>
            @can('create', \App\Models\Team::class)
                <x-primary-button :href="route('teams.create')">
                    {{ __('teams.new') }}
                </x-primary-button>
            @endcan
        </div>
    </x-slot>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('teams.columns.name') }}</th>
                <th class="hidden px-4 py-3 sm:table-cell">{{ __('teams.columns.coordinator') }}</th>
                <th class="px-4 py-3">{{ __('teams.columns.members') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.edit') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($teams as $team)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">
                        {{ $team->name }}
                        <p class="text-xs font-normal sm:hidden {{ $team->coordinator ? 'text-gray-600' : 'italic text-gray-700' }}">{{ __('teams.columns.coordinator') }}: {{ $team->coordinator?->name ?? __('teams.form.no_coordinator') }}</p>
                    </td>
                    <td class="hidden px-4 py-3 sm:table-cell {{ $team->coordinator ? '' : 'italic text-gray-700' }}">{{ $team->coordinator?->name ?? __('teams.form.no_coordinator') }}</td>
                    <td class="px-4 py-3">{{ $team->members_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        @can('update', $team)
                            <a href="{{ route('teams.edit', $team) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.edit') }}</a>
                        @endcan
                        @can('delete', $team)
                            <form method="POST" action="{{ route('teams.destroy', $team) }}" class="ms-3 inline" x-data x-on:submit="if (! confirm(@js(__('teams.delete_confirm')))) $event.preventDefault()">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">{{ __('common.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $teams->links() }}
</x-app-layout>
