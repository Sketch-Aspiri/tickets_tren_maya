<x-app-layout>
    <x-slot name="title">{{ __('activities.templates.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('activities.templates.title') }}</h1>
            <x-text-link :href="route('activities.index')">{{ __('activities.show.back') }}</x-text-link>
        </div>
    </x-slot>

    <p class="text-sm text-gray-700">{{ __('activities.templates.intro') }}</p>

    {{-- Alternar entre plantillas activas y la papelera: enlaces GET, sin JavaScript (mismo patron que
         "Mis pendientes" mine/team). --}}
    <nav aria-label="{{ __('activities.templates.tabs_legend') }}" class="inline-flex overflow-hidden rounded-md border border-brand-teal bg-white shadow-sm">
        <a href="{{ route('activities.templates.index') }}"
           @unless ($trashed) aria-current="page" @endunless
           class="inline-flex min-h-[44px] items-center px-5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal {{ $trashed ? 'text-brand-green hover:bg-brand-mist' : 'bg-brand-green text-white' }}">
            {{ __('activities.templates.active') }}
        </a>
        <a href="{{ route('activities.templates.index', ['trashed' => 1]) }}"
           @if ($trashed) aria-current="page" @endif
           class="inline-flex min-h-[44px] items-center px-5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal {{ $trashed ? 'bg-brand-green text-white' : 'text-brand-green hover:bg-brand-mist' }}">
            {{ __('activities.templates.trash') }}
        </a>
    </nav>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('activities.columns.folio') }}</th>
                <th class="px-4 py-3">{{ __('activities.columns.title') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('activities.show.recurrence_rule') }}</th>
                <th class="px-4 py-3">{{ __('activities.columns.status') }}</th>
                <th class="hidden px-4 py-3 lg:table-cell">{{ __('tickets.columns.category') }}</th>
                <th class="hidden px-4 py-3 xl:table-cell">{{ __('activities.columns.team') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.view') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($templates as $template)
                @php
                    $rule = \App\Support\RecurrenceRule::fromArray((array) $template->recurrence_rule);
                @endphp
                <tr>
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-700">{{ $template->folio }}</td>
                    <td class="px-4 py-3">
                        <span class="line-clamp-2 font-medium text-gray-900">{{ $template->title }}</span>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 md:hidden">
                            <span class="text-xs text-gray-700">{{ __('activities.recurrence.summary.'.$rule->frequency->value, ['interval' => $rule->interval]) }}</span>
                        </div>
                    </td>
                    <td class="hidden px-4 py-3 text-sm text-gray-800 md:table-cell">{{ __('activities.recurrence.summary.'.$rule->frequency->value, ['interval' => $rule->interval]) }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$template->status" /></td>
                    <td class="hidden px-4 py-3 lg:table-cell">{{ $template->category?->name ?? __('activities.form.no_category') }}</td>
                    <td class="hidden px-4 py-3 xl:table-cell">{{ $template->team->name }}</td>
                    <td class="px-2 py-3 text-right sm:px-4">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @unless ($trashed)
                                <a href="{{ route('activities.show', $template) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.view') }}</a>
                                @can('update', $template)
                                    <a href="{{ route('activities.edit', $template) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.edit') }}</a>
                                @endcan
                                @can('delete', $template)
                                    <form method="POST" action="{{ route('activities.destroy', $template) }}" x-data="confirmSubmit" data-confirm="{{ __('activities.show.delete_confirm') }}" x-on:submit="onSubmit">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                                    </form>
                                @endcan
                            @else
                                @can('restore', $template)
                                    <form method="POST" action="{{ route('activities.templates.restore', $template) }}" x-data="confirmSubmit" data-confirm="{{ __('activities.templates.restore_confirm') }}" x-on:submit="onSubmit">
                                        @csrf
                                        <x-secondary-button type="submit">{{ __('activities.templates.restore') }}</x-secondary-button>
                                    </form>
                                @endcan
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-600">{{ $trashed ? __('activities.templates.empty_trash') : __('activities.templates.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $templates->links() }}
</x-app-layout>
