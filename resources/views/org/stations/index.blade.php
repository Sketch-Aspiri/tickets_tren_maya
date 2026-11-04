<x-app-layout>
    <x-slot name="title">{{ __('stations.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('stations.title') }}</h1>
            @can('create', \App\Models\Station::class)
                <x-primary-button :href="route('stations.create')">{{ __('stations.new') }}</x-primary-button>
            @endcan
        </div>
    </x-slot>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('stations.columns.name') }}</th>
                <th class="px-4 py-3">{{ __('stations.columns.status') }}</th>
                <th class="hidden px-4 py-3 sm:table-cell">{{ __('stations.columns.coordinations') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.edit') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($stations as $station)
                <tr>
                    <td class="px-4 py-3 font-medium"><a href="{{ route('stations.show', $station) }}" class="text-brand-green hover:underline">{{ $station->name }}</a></td>
                    <td class="px-4 py-3">
                        @if ($station->active)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-mint/20 px-2.5 py-0.5 text-xs font-medium text-brand-green"><span class="h-1.5 w-1.5 rounded-full bg-brand-teal" aria-hidden="true"></span>{{ __('stations.active') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-800"><span class="h-1.5 w-1.5 rounded-full bg-gray-500" aria-hidden="true"></span>{{ __('stations.inactive') }}</span>
                        @endif
                    </td>
                    <td class="hidden px-4 py-3 sm:table-cell">{{ $station->coordinations_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        @can('update', $station)
                            <a href="{{ route('stations.edit', $station) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.edit') }}</a>
                        @endcan
                        @can('delete', $station)
                            <form method="POST" action="{{ route('stations.destroy', $station) }}" class="ms-3 inline" x-data="confirmSubmit" data-confirm="{{ __('stations.delete_confirm') }}" x-on:submit="onSubmit">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-0"><x-empty-state :title="__('common.empty')" /></td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $stations->links() }}
</x-app-layout>
