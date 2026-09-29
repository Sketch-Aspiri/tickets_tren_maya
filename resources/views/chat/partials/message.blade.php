{{-- Un mensaje del chat. `$body` es un HtmlString ya escapado por ChatMessageFormatter (solo los folios son enlaces). --}}
<div data-message-id="{{ $message->getKey() }}" class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
    <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm {{ $isMine ? 'bg-brand-mint/30 text-gray-900' : 'border border-brand-green/10 bg-white text-gray-900' }}">
        <p class="text-xs text-gray-600"><span class="font-medium text-gray-900">{{ $isMine ? __('chat.you') : $message->user->name }}</span> · <x-local-datetime :value="$message->created_at" /></p>
        @if ($message->body !== '')
            <p class="mt-1 whitespace-pre-line break-words">{{ $body }}</p>
        @endif
        @foreach ($message->attachments as $attachment)
            <x-attachment-preview :attachment="$attachment" />
            <p class="mt-1">
                <a href="{{ route('attachments.download', $attachment) }}" class="inline-flex min-h-[44px] items-center break-all font-medium text-brand-teal underline underline-offset-2 hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ $attachment->original_name }}</a>
                <span class="text-xs text-gray-600">({{ number_format($attachment->size / 1024, 1) }} KB)</span>
            </p>
        @endforeach
    </div>
</div>
