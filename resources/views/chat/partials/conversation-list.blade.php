{{-- Lista de conversaciones (se vuelve a pedir por polling: la genera el servidor, todo escapado). --}}
@forelse ($conversations as $conversation)
    <li>
        <a href="{{ route('chat.show', $conversation) }}" @if ($activeId === $conversation->getKey()) aria-current="page" @endif class="flex min-h-[44px] items-center justify-between gap-2 rounded-md px-3 text-sm font-medium hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal {{ $activeId === $conversation->getKey() ? 'bg-brand-mist text-brand-green' : 'text-gray-800' }}">
            <span class="flex min-w-0 items-center gap-2">
                @if ($conversation->type === \App\Enums\ConversationType::Team)
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-teal text-xs font-semibold uppercase text-white" aria-hidden="true">{{ mb_substr((string) $conversation->team?->name, 0, 1) }}</span>
                @else
                    <x-user-avatar :user="$conversation->otherParticipant(auth()->user())" size="sm" />
                @endif
                <span class="min-w-0 truncate">
                {{ $conversation->titleFor(auth()->user()) }}
                @if ($conversation->type === \App\Enums\ConversationType::Team)
                    <span class="text-xs font-normal text-gray-600">({{ __('chat.team_channel') }})</span>
                @endif
                </span>
            </span>
            @if ($conversation->unread_count > 0)
                <span class="inline-flex min-w-[1.5rem] justify-center rounded-full bg-brand-green px-2 py-0.5 text-xs font-semibold text-white"><span class="sr-only">{{ __('chat.unread') }}</span>{{ $conversation->unread_count }}</span>
            @endif
        </a>
    </li>
@empty
    <li class="text-sm text-gray-700">{{ __('chat.no_conversations') }}</li>
@endforelse
