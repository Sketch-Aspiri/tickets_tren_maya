@props(['item', 'storeUrl'])

{{-- Comentarios de un ticket o una actividad: texto plano, siempre escapado (`comments.user` ya cargado). --}}
<x-card :title="__('tickets.show.comments')" id="comentarios">
    @forelse ($item->comments as $comment)
        <div class="mb-4 border-b border-brand-green/10 pb-3 last:mb-0 last:border-0 last:pb-0">
            <p class="text-xs text-gray-600"><span class="font-medium text-gray-900">{{ $comment->user->name }}</span> · <x-local-datetime :value="$comment->created_at" /></p>
            <p class="mt-1 whitespace-pre-line break-words text-sm text-gray-900">{{ $comment->body }}</p>
        </div>
    @empty
        <p class="text-sm text-gray-700">{{ __('tickets.show.no_comments') }}</p>
    @endforelse

    @can('comment', $item)
        <form method="POST" action="{{ $storeUrl }}" class="mt-4">
            @csrf
            <x-input-label for="body" :value="__('tickets.comment.label')" />
            <textarea id="body" name="body" rows="3" maxlength="{{ config('tickets.comment_max_length') }}" required aria-describedby="body_hint" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">{{ old('body') }}</textarea>
            <p id="body_hint" class="mt-1 text-xs text-gray-600">{{ __('tickets.comment.plain_text_hint') }}</p>
            <x-input-error :messages="$errors->get('body')" class="mt-2" />
            <x-primary-button class="mt-3">{{ __('tickets.comment.submit') }}</x-primary-button>
        </form>
    @endcan
</x-card>
