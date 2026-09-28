@php
    // Pestañas de filtro por estado: pending_review/converted/discarded (enum) + "all" (quita el filtro).
    // El controlador ya valida `status` (IndexIncomingEmailsRequest) y pagina con withQueryString().
    $statusOptions = collect(\App\Enums\IncomingEmailStatus::cases())
        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
        ->put('all', __('common.all'))
        ->all();
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('common.nav.incoming_emails') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('common.nav.incoming_emails') }}</h1>
    </x-slot>

    <x-card>
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('common.nav.incoming_emails') }}">
            @foreach ($statusOptions as $value => $label)
                @if ($value === $status)
                    <x-primary-button :href="route('incoming-emails.index', ['status' => $value])" aria-current="true">{{ $label }}</x-primary-button>
                @else
                    <x-secondary-button :href="route('incoming-emails.index', ['status' => $value])">{{ $label }}</x-secondary-button>
                @endif
            @endforeach
        </nav>
    </x-card>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('emails.index.columns.sender') }}</th>
                <th class="px-4 py-3">{{ __('emails.index.columns.subject') }}</th>
                <th class="px-4 py-3">{{ __('emails.index.columns.status') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('emails.index.columns.received_at') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.view') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($emails as $email)
                <tr>
                    <td class="px-4 py-3">
                        <p class="max-w-[8rem] truncate font-medium text-gray-900 sm:max-w-xs">{{ $email->from_name ?: $email->from_email }}</p>
                        @if ($email->from_name)
                            <p class="max-w-[8rem] truncate text-xs text-gray-500 sm:max-w-xs">{{ $email->from_email }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('incoming-emails.show', $email) }}" class="inline-flex min-h-[44px] max-w-[11rem] items-center font-medium text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal sm:max-w-md">
                            <span class="line-clamp-2">{{ \Illuminate\Support\Str::limit($email->subject, 80) }}</span>
                        </a>
                        <div class="mt-1 text-xs text-gray-600 md:hidden">
                            <x-local-datetime :value="$email->received_at" />
                        </div>
                        @if ($email->attachments->isNotEmpty())
                            <p class="mt-1 flex items-center gap-1 text-xs text-gray-600">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.44 11.05l-9.19 9.19a5 5 0 01-7.07-7.07l9.19-9.19a3.5 3.5 0 014.95 4.95l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                                {{ trans_choice('emails.index.attachments_count', $email->attachments->count(), ['count' => $email->attachments->count()]) }}
                            </p>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-status-badge :status="$email->status" /></td>
                    <td class="hidden whitespace-nowrap px-4 py-3 md:table-cell"><x-local-datetime :value="$email->received_at" /></td>
                    <td class="px-2 py-3 text-right sm:px-4">
                        <a href="{{ route('incoming-emails.show', $email) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.view') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-600">{{ __('emails.index.empty.'.$status) }}</td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $emails->links() }}
</x-app-layout>
