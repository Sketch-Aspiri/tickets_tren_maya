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

    @if ($mailboxes->count() > 1 || auth()->user()->seesAllTeams())
        @php
            $totalPending = $pendingByMailbox->sum();
            $groups = $mailboxes->groupBy('station_id')->sortBy(fn ($items) => $items->first()->station->name);
            $allActive = $coordinationId === null && $stationId === null;
        @endphp
        <x-card>
            <p class="mb-2 text-sm font-semibold text-brand-green">{{ __('emails.index.mailboxes') }}</p>
            <nav class="space-y-4" aria-label="{{ __('emails.index.mailboxes') }}">
                <div>
                    @if ($allActive)
                        <x-primary-button :href="route('incoming-emails.index', ['status' => $status])" aria-current="true">{{ __('common.all') }} ({{ $totalPending }})</x-primary-button>
                    @else
                        <x-secondary-button :href="route('incoming-emails.index', ['status' => $status])">{{ __('common.all') }} ({{ $totalPending }})</x-secondary-button>
                    @endif
                </div>

                @foreach ($groups as $group => $items)
                    @php
                        $station = $items->first()->station;
                        $stationPending = $items->sum(fn ($mailbox) => $pendingByMailbox[$mailbox->id] ?? 0);
                    @endphp
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3" role="group" aria-labelledby="station-{{ $station->id }}">
                        <h2 id="station-{{ $station->id }}" class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900">
                            <x-icon name="building" class="h-5 w-5 text-brand-teal" />
                            <a href="{{ route('incoming-emails.index', ['status' => $status, 'station_id' => $station->id]) }}"
                               class="rounded hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal {{ $stationId === $station->id ? 'text-brand-green underline' : '' }}"
                               @if ($stationId === $station->id) aria-current="true" @endif>{{ $station->name }}</a>
                            <span class="text-xs font-normal text-gray-600">({{ $stationPending }})</span>
                        </h2>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($items->sortBy('name') as $mailbox)
                                @php $label = $mailbox->name.' ('.($pendingByMailbox[$mailbox->id] ?? 0).')'; @endphp
                                @if ($mailbox->id === $coordinationId)
                                    <x-primary-button :href="route('incoming-emails.index', ['status' => $status, 'coordination_id' => $mailbox->id])" aria-current="true">{{ $label }}</x-primary-button>
                                @else
                                    <x-secondary-button :href="route('incoming-emails.index', ['status' => $status, 'coordination_id' => $mailbox->id])">{{ $label }}</x-secondary-button>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
        </x-card>
    @endif

    <x-card>
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('common.nav.incoming_emails') }}">
            @foreach ($statusOptions as $value => $label)
                @if ($value === $status)
                    <x-primary-button :href="route('incoming-emails.index', array_filter(['status' => $value, 'coordination_id' => $coordinationId, 'station_id' => $stationId]))" aria-current="true">{{ $label }}</x-primary-button>
                @else
                    <x-secondary-button :href="route('incoming-emails.index', array_filter(['status' => $value, 'coordination_id' => $coordinationId, 'station_id' => $stationId]))">{{ $label }}</x-secondary-button>
                @endif
            @endforeach
        </nav>
    </x-card>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('emails.index.columns.sender') }}</th>
                <th class="px-4 py-3">{{ __('emails.index.columns.subject') }}</th>
                <th class="hidden px-4 py-3 lg:table-cell">{{ __('emails.index.columns.mailbox') }}</th>
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
                    <td class="hidden px-4 py-3 lg:table-cell">{{ $email->coordination ? $email->coordination->station->name.' › '.$email->coordination->name : '—' }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$email->status" /></td>
                    <td class="hidden whitespace-nowrap px-4 py-3 md:table-cell"><x-local-datetime :value="$email->received_at" /></td>
                    <td class="px-2 py-3 text-right sm:px-4">
                        <a href="{{ route('incoming-emails.show', $email) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.view') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-600">{{ __('emails.index.empty.'.$status) }}</td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $emails->links() }}
</x-app-layout>
