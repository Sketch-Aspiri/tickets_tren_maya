<x-app-layout>
    <x-slot name="title">{{ __('chat.title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('chat.title') }}</h1>
    </x-slot>

    <div class="grid gap-4 lg:grid-cols-[20rem_1fr]" x-data="imageViewer" x-on:click="openPreview">
        <x-image-viewer />
        {{-- Lista de conversaciones. En movil solo se muestra si no hay una conversacion abierta. --}}
        <div class="{{ $active ? 'hidden lg:block' : '' }} space-y-4">
            <x-card :title="__('chat.new_direct')" x-data="chatUserSearch" data-url="{{ route('chat.users') }}">
                <label for="chat-user-q" class="sr-only">{{ __('chat.search_label') }}</label>
                <input id="chat-user-q" type="search" maxlength="60" autocomplete="off" placeholder="{{ __('chat.search_placeholder') }}" x-on:input.debounce.300ms="search" x-model="query" class="block min-h-[44px] w-full rounded-md border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">
                <ul class="mt-2 space-y-1" aria-live="polite">
                    <template x-for="user in results" :key="user.id">
                        <li>
                            <form method="POST" action="{{ route('chat.direct') }}">
                                @csrf
                                <input type="hidden" name="user_id" x-bind:value="user.id">
                                <button type="submit" x-text="user.name" class="flex min-h-[44px] w-full items-center rounded-md px-3 text-left text-sm font-medium text-gray-800 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal"></button>
                            </form>
                        </li>
                    </template>
                </ul>
                <p x-show="noResults" x-cloak class="mt-2 text-sm text-gray-700">{{ __('chat.no_results') }}</p>
            </x-card>

            <x-card :title="__('chat.conversations')" x-data="chatList" data-url="{{ route('chat.list', ['active' => $active?->getKey()]) }}">
                <ul x-ref="list" class="space-y-1">
                    @include('chat.partials.conversation-list', ['conversations' => $conversations, 'activeId' => $active?->getKey()])
                </ul>
            </x-card>
        </div>

        {{-- Panel de la conversacion abierta. --}}
        <div class="{{ $active ? '' : 'hidden lg:block' }}">
            @if ($active)
                <x-card>
                    <div class="mb-3 flex items-center gap-3">
                        <a href="{{ route('chat.index') }}" class="inline-flex min-h-[44px] items-center text-sm font-medium text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal lg:hidden">&larr; {{ __('chat.back') }}</a>
                        <h2 class="truncate text-lg font-semibold text-brand-green">{{ $active->titleFor(auth()->user()) }}</h2>
                    </div>

                    <div x-data="chat"
                         data-poll-url="{{ route('chat.messages.index', $active) }}"
                         data-send-url="{{ route('chat.messages.store', $active) }}"
                         data-last-id="{{ $messages->last()?->getKey() ?? 0 }}"
                         data-csrf="{{ csrf_token() }}"
                         data-error-generic="{{ __('chat.errors.generic') }}"
                         data-error-session="{{ __('chat.errors.session') }}">
                        <div x-ref="list" role="log" aria-live="polite" tabindex="0" class="flex h-[55vh] flex-col gap-2 overflow-y-auto rounded-md bg-brand-mist/50 p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                            @foreach ($messages as $message)
                                @include('chat.partials.message', ['message' => $message, 'body' => $rendered[$message->getKey()], 'isMine' => (int) $message->user_id === (int) auth()->id()])
                            @endforeach
                        </div>

                        <form x-ref="form" x-on:submit.prevent="send" class="mt-3 space-y-2" enctype="multipart/form-data">
                            <label for="chat-body" class="sr-only">{{ __('chat.message_label') }}</label>
                            <textarea id="chat-body" name="body" rows="2" maxlength="{{ config('tickets.chat.message_max_length') }}" placeholder="{{ __('chat.message_placeholder') }}" x-on:keydown.enter="sendOnEnter" class="block min-h-[44px] w-full rounded-md border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal"></textarea>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <label for="chat-file" class="sr-only">{{ __('chat.attach_label') }}</label>
                                    <input id="chat-file" type="file" name="attachment" class="block text-xs text-gray-700 file:mr-2 file:min-h-[44px] file:rounded-md file:border-0 file:bg-brand-mist file:px-3 file:text-sm file:font-medium file:text-brand-green">
                                </div>
                                <x-primary-button type="submit" x-bind:disabled="sending">{{ __('chat.send') }}</x-primary-button>
                            </div>
                            <p x-show="hasError" x-cloak x-text="error" role="alert" class="text-sm text-red-700"></p>
                            <p class="text-xs text-gray-600">{{ __('chat.hint') }}</p>
                        </form>
                    </div>
                </x-card>
            @else
                <x-card>
                    <p class="text-sm text-gray-700">{{ __('chat.select_conversation') }}</p>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
