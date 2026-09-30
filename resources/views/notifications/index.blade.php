<x-app-layout>
    <x-slot name="title">{{ __('notifications.ui.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('notifications.ui.title') }}</h1>
            @if ($notifications->contains(fn ($item) => $item->read_at === null))
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <x-secondary-button type="submit">{{ __('notifications.ui.mark_all') }}</x-secondary-button>
                </form>
            @endif
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-card :title="__('notifications.ui.chat_section')">
            <ul class="divide-y divide-brand-green/10">
                @forelse ($unreadChats as $conversation)
                    <li>
                        <a href="{{ route('chat.show', $conversation) }}" class="flex min-h-[44px] items-center justify-between gap-3 py-2 text-sm font-medium text-gray-900 hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                            <span class="min-w-0 truncate">{{ $conversation->titleFor(auth()->user()) }}</span>
                            <span class="inline-flex min-w-[1.5rem] justify-center rounded-full bg-brand-green px-2 py-0.5 text-xs font-semibold text-white"><span class="sr-only">{{ __('chat.unread') }}</span>{{ $conversation->unread_count }}</span>
                        </a>
                    </li>
                @empty
                    <li class="py-2 text-sm text-gray-700">{{ __('notifications.ui.no_chat') }}</li>
                @endforelse
            </ul>
        </x-card>

        <x-card :title="__('notifications.ui.alerts_section')">
            <ul class="divide-y divide-brand-green/10">
                @forelse ($notifications as $item)
                    @php($data = (array) $item->data)
                    <li>
                        <a href="{{ route('notifications.open', $item->id) }}" class="flex min-h-[44px] flex-col gap-0.5 py-3 text-sm hover:bg-brand-mist/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                            <span class="{{ $item->read_at === null ? 'font-semibold text-gray-900' : 'text-gray-700' }}">
                                @if ($item->read_at === null)<span class="me-1 inline-block h-2 w-2 rounded-full bg-brand-teal" aria-hidden="true"></span><span class="sr-only">{{ __('notifications.ui.unread') }}</span>@endif
                                @switch($data['type'] ?? null)
                                    @case(\App\Notifications\WorkItemAssigned::TYPE)
                                        {{ __('notifications.ui.assigned.'.($data['kind'] ?? 'ticket'), ['folio' => $data['folio'] ?? '', 'title' => $data['title'] ?? '', 'by' => $data['assigned_by'] ?? '']) }}
                                        @break
                                    @case(\App\Notifications\WorkItemCommented::TYPE)
                                        {{ __('notifications.ui.work_item_commented.'.($data['kind'] ?? 'ticket'), ['folio' => $data['folio'] ?? '', 'title' => $data['title'] ?? '', 'by' => $data['by'] ?? '']) }}
                                        @break
                                    @case(\App\Notifications\WorkItemRejected::TYPE)
                                        {{ __('notifications.ui.work_item_rejected.'.($data['kind'] ?? 'ticket'), ['folio' => $data['folio'] ?? '', 'title' => $data['title'] ?? '', 'by' => $data['by'] ?? '']) }}
                                        @break
                                    @case('user_pending_approval')
                                        {{ __('notifications.new_user_pending.line', ['name' => $data['name'] ?? '', 'email' => $data['email'] ?? '']) }}
                                        @break
                                    @case('incoming_email_pending')
                                        {{ __('notifications.new_incoming_email.line', ['count' => $data['count'] ?? 0]) }}
                                        @break
                                    @default
                                        {{ __('notifications.ui.generic') }}
                                @endswitch
                            </span>
                            <span class="text-xs text-gray-600"><x-local-datetime :value="$item->created_at" /></span>
                        </a>
                    </li>
                @empty
                    <li class="py-2 text-sm text-gray-700">{{ __('notifications.ui.no_alerts') }}</li>
                @endforelse
            </ul>
            <div class="mt-4">{{ $notifications->links() }}</div>
        </x-card>
    </div>
</x-app-layout>
