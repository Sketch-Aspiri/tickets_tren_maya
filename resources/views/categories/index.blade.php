<x-app-layout>
    <x-slot name="title">{{ __('categories.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('categories.title') }}</h1>
            @can('create', \App\Models\Category::class)
                <x-primary-button :href="route('categories.create')">{{ __('categories.new') }}</x-primary-button>
            @endcan
        </div>
    </x-slot>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('categories.columns.name') }}</th>
                <th class="px-4 py-3">{{ __('categories.columns.status') }}</th>
                <th class="hidden px-4 py-3 sm:table-cell">{{ __('categories.columns.tickets') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.edit') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($categories as $category)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $category->name }}</td>
                    <td class="px-4 py-3">
                        @if ($category->active)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-mint/20 px-2.5 py-0.5 text-xs font-medium text-brand-green"><span class="h-1.5 w-1.5 rounded-full bg-brand-teal" aria-hidden="true"></span>{{ __('categories.active') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-800"><span class="h-1.5 w-1.5 rounded-full bg-gray-500" aria-hidden="true"></span>{{ __('categories.inactive') }}</span>
                        @endif
                    </td>
                    <td class="hidden px-4 py-3 sm:table-cell">{{ $category->tickets_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        @can('update', $category)
                            <a href="{{ route('categories.edit', $category) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.edit') }}</a>
                        @endcan
                        @can('delete', $category)
                            <form method="POST" action="{{ route('categories.destroy', $category) }}" class="ms-3 inline" x-data="confirmSubmit" data-confirm="{{ __('categories.delete_confirm') }}" x-on:submit="onSubmit">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-600">{{ __('common.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $categories->links() }}
</x-app-layout>
