<x-app-layout>
    <x-slot name="title">{{ __('sheet_sources.inbox_title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('sheet_sources.inbox_title') }}</h1>
    </x-slot>

    <p class="text-sm text-gray-700">{{ __('sheet_sources.inbox_intro') }}</p>

    @php
        $link = 'inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal';
    @endphp

    @forelse ($coordinations as $coordination)
        @php($mailbox = $coordination->mailbox)
        @php($source = $coordination->spreadsheetSource)
        <x-card :title="$coordination->station->name.' › '.$coordination->name">
            <div class="grid gap-6 md:grid-cols-2">
                {{-- Buzón de correo (IMAP) --}}
                <section aria-labelledby="mail-{{ $coordination->id }}" class="space-y-2">
                    <h3 id="mail-{{ $coordination->id }}" class="flex items-center gap-2 text-sm font-semibold text-gray-900"><x-icon name="mail" class="h-5 w-5 text-brand-teal" />{{ __('sheet_sources.mail_card') }}</h3>
                    @if (! $mailbox)
                        <p class="text-sm text-gray-700">{{ __('sheet_sources.none') }}</p>
                    @else
                        <p class="text-sm text-gray-800">{{ $mailbox->username }} · {{ $mailbox->host }}</p>
                        @if ($mailbox->last_error)
                            <p class="break-words text-xs font-medium text-red-700">{{ $mailbox->last_error }}</p>
                        @endif
                        <p class="text-xs text-gray-600">{{ __('sheet_sources.last_sync') }} @if ($mailbox->last_polled_at)<x-local-datetime :value="$mailbox->last_polled_at" />@else{{ __('sheet_sources.never') }}@endif</p>
                    @endif
                    <div class="flex flex-wrap items-center gap-1">
                        <a href="{{ route('mailboxes.edit', $coordination) }}" class="{{ $link }}">{{ __('sheet_sources.configure') }}</a>
                        @if ($mailbox)
                            <form method="POST" action="{{ route('mailboxes.sync', $coordination) }}">
                                @csrf
                                <button type="submit" class="{{ $link }}">{{ __('sheet_sources.sync') }}</button>
                            </form>
                            <form method="POST" action="{{ route('mailboxes.test', $coordination) }}">
                                @csrf
                                <button type="submit" class="{{ $link }}">{{ __('mailboxes.test') }}</button>
                            </form>
                        @endif
                    </div>
                </section>

                {{-- Hoja de Excel (OneDrive) --}}
                <section aria-labelledby="sheet-{{ $coordination->id }}" class="space-y-2">
                    <h3 id="sheet-{{ $coordination->id }}" class="flex items-center gap-2 text-sm font-semibold text-gray-900"><x-icon name="list" class="h-5 w-5 text-brand-teal" />{{ __('sheet_sources.sheet_card') }}</h3>
                    @if (! $source)
                        <p class="text-sm text-gray-700">{{ __('sheet_sources.none') }}</p>
                    @else
                        <p class="text-sm text-gray-800">{{ $source->name }}</p>
                        @if ($source->isConnected())
                            <p class="text-xs text-brand-green">{{ __('sheet_sources.connected_as', ['account' => $source->account_name ?? '—']) }}</p>
                        @elseif ($source->share_url === '')
                            <p class="text-xs text-gray-700">{{ __('sheet_sources.manual_mode') }}</p>
                        @else
                            <p class="text-xs font-medium text-amber-800">{{ __('sheet_sources.not_connected') }}</p>
                        @endif
                        @if ($source->last_error)
                            <p class="break-words text-xs font-medium text-red-700">{{ $source->last_error }}</p>
                        @endif
                        <p class="text-xs text-gray-600">{{ __('sheet_sources.last_sync') }} @if ($source->last_synced_at)<x-local-datetime :value="$source->last_synced_at" />@else{{ __('sheet_sources.never') }}@endif</p>
                    @endif
                    @if ($source && $source->share_url !== '' && ! $sheetsConfigured)
                        <p class="text-xs text-gray-700">{{ __('sheet_sources.not_available') }}</p>
                    @endif
                    @if ($source)
                        <form method="POST" action="{{ route('sheet-sources.upload', $coordination) }}" enctype="multipart/form-data" class="space-y-1 rounded-lg border border-gray-200 bg-gray-50 p-3">
                            @csrf
                            <label for="sheet-file-{{ $coordination->id }}" class="block text-xs font-semibold text-gray-900">{{ __('sheet_sources.upload_card') }}</label>
                            <input id="sheet-file-{{ $coordination->id }}" name="file" type="file" required accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-describedby="sheet-file-hint-{{ $coordination->id }}" class="block w-full text-sm text-gray-800 file:me-3 file:min-h-[44px] file:rounded-lg file:border-0 file:bg-brand-green file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-green/90">
                            <p id="sheet-file-hint-{{ $coordination->id }}" class="text-xs text-gray-600">{{ __('sheet_sources.upload_hint', ['max' => config('tickets.sheet_inbox.max_file_kb')]) }}</p>
                            <button type="submit" class="{{ $link }}">{{ __('sheet_sources.upload_button') }}</button>
                        </form>
                    @endif
                    <div class="flex flex-wrap items-center gap-1">
                        <a href="{{ route('sheet-sources.edit', $coordination) }}" class="{{ $link }}">{{ __('sheet_sources.configure') }}</a>
                        @if ($source && $sheetsConfigured)
                            <form method="POST" action="{{ route('sheet-sources.connect', $coordination) }}">
                                @csrf
                                <button type="submit" class="{{ $link }}">{{ $source->isConnected() ? __('sheet_sources.reconnect') : __('sheet_sources.connect') }}</button>
                            </form>
                        @endif
                        @if ($source?->isConnected())
                            <form method="POST" action="{{ route('sheet-sources.sync', $coordination) }}">
                                @csrf
                                <button type="submit" class="{{ $link }}">{{ __('sheet_sources.sync') }}</button>
                            </form>
                            <form method="POST" action="{{ route('sheet-sources.disconnect', $coordination) }}">
                                @csrf
                                <button type="submit" class="{{ $link }}">{{ __('sheet_sources.disconnect') }}</button>
                            </form>
                        @endif
                        @if ($source)
                            <form method="POST" action="{{ route('sheet-sources.destroy', $coordination) }}" x-data="confirmSubmit" data-confirm="{{ __('sheet_sources.delete_confirm') }}" x-on:submit="onSubmit">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('common.actions.delete') }}</button>
                            </form>
                        @endif
                    </div>
                </section>
            </div>
        </x-card>
    @empty
        <x-card><x-empty-state :title="__('sheet_sources.no_coordinations')" /></x-card>
    @endforelse
</x-app-layout>
