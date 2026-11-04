<x-app-layout>
    <x-slot name="title">{{ __('mailboxes.title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('mailboxes.title') }}</h1>
    </x-slot>

    <p class="text-sm text-gray-700">{{ __('mailboxes.intro') }}</p>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('mailboxes.columns.coordination') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('mailboxes.columns.server') }}</th>
                <th class="px-4 py-3">{{ __('mailboxes.columns.status') }}</th>
                <th class="hidden px-4 py-3 lg:table-cell">{{ __('mailboxes.columns.last_polled') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.edit') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($coordinations as $coordination)
                @php($mailbox = $coordination->mailbox)
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">
                        {{ $coordination->name }}
                        <p class="text-xs font-normal text-gray-600">{{ $coordination->station->name }}</p>
                    </td>
                    <td class="hidden px-4 py-3 md:table-cell">{{ $mailbox ? $mailbox->username.' · '.$mailbox->host.':'.$mailbox->port : '—' }}</td>
                    <td class="px-4 py-3">
                        @if (! $mailbox)
                            <span class="text-gray-700">{{ __('mailboxes.status.none') }}</span>
                        @elseif ($mailbox->last_error)
                            <span class="font-medium text-red-700">{{ __('mailboxes.status.error') }}</span>
                            <p class="max-w-xs break-words text-xs text-gray-700">{{ $mailbox->last_error }}</p>
                        @elseif (! $mailbox->active)
                            <span class="text-gray-700">{{ __('mailboxes.status.inactive') }}</span>
                        @else
                            <span class="font-medium text-brand-green">{{ __('mailboxes.status.ok') }}</span>
                        @endif
                    </td>
                    <td class="hidden px-4 py-3 lg:table-cell">
                        @if ($mailbox?->last_polled_at)
                            <x-local-datetime :value="$mailbox->last_polled_at" />
                        @else
                            —
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        <a href="{{ route('mailboxes.edit', $coordination) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ $mailbox ? __('common.actions.edit') : __('mailboxes.configure') }}</a>
                        @if ($mailbox)
                            <form method="POST" action="{{ route('mailboxes.test', $coordination) }}" class="ms-3 inline">
                                @csrf
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('mailboxes.test') }}</button>
                            </form>
                            <form method="POST" action="{{ route('mailboxes.destroy', $coordination) }}" class="ms-3 inline" x-data="confirmSubmit" data-confirm="{{ __('mailboxes.delete_confirm') }}" x-on:submit="onSubmit">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty-state :title="__('common.empty')" /></td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-app-layout>
