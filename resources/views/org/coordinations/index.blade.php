<x-app-layout>
    <x-slot name="title">{{ __('coordinations.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('coordinations.title') }}</h1>
            @can('create', \App\Models\Coordination::class)
                <x-primary-button :href="route('coordinations.create')">{{ __('coordinations.new') }}</x-primary-button>
            @endcan
        </div>
    </x-slot>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('coordinations.columns.name') }}</th>
                <th class="hidden px-4 py-3 sm:table-cell">{{ __('coordinations.columns.station') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('coordinations.columns.code') }}</th>
                <th class="px-4 py-3">{{ __('coordinations.columns.status') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('coordinations.columns.teams') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('coordinations.columns.bosses') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.edit') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($coordinations as $coordination)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">
                        {{ $coordination->name }}
                        <p class="text-xs font-normal text-gray-600 sm:hidden">{{ $coordination->station->name }}</p>
                    </td>
                    <td class="hidden px-4 py-3 sm:table-cell">{{ $coordination->station->name }}</td>
                    <td class="hidden px-4 py-3 font-mono text-xs md:table-cell">{{ $coordination->code }}</td>
                    <td class="px-4 py-3">
                        @if ($coordination->active)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-mint/20 px-2.5 py-0.5 text-xs font-medium text-brand-green"><span class="h-1.5 w-1.5 rounded-full bg-brand-teal" aria-hidden="true"></span>{{ __('coordinations.active') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-800"><span class="h-1.5 w-1.5 rounded-full bg-gray-500" aria-hidden="true"></span>{{ __('coordinations.inactive') }}</span>
                        @endif
                    </td>
                    <td class="hidden px-4 py-3 md:table-cell">{{ $coordination->teams_count }}</td>
                    <td class="hidden px-4 py-3 md:table-cell">{{ $coordination->bosses_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        @can('update', $coordination)
                            <a href="{{ route('coordinations.edit', $coordination) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.edit') }}</a>
                        @endcan
                        @can('delete', $coordination)
                            <form method="POST" action="{{ route('coordinations.destroy', $coordination) }}" class="ms-3 inline" x-data="confirmSubmit" data-confirm="{{ __('coordinations.delete_confirm') }}" x-on:submit="onSubmit">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-0"><x-empty-state :title="__('common.empty')" /></td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $coordinations->links() }}
</x-app-layout>
