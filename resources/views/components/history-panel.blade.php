@props(['item', 'createdLabel'])

{{-- Historial de estados de un ticket o una actividad (`statusHistories.user` ya cargados). Los comentarios
     del historial son texto de usuario: siempre escapados. --}}
<x-card :title="__('tickets.show.history')">
    <ol class="space-y-3 text-sm">
        @foreach ($item->statusHistories as $history)
            <li class="border-s-2 border-brand-mint ps-3">
                <p class="font-medium text-gray-900">
                    @if ($history->from_status === null)
                        {{ $createdLabel }}
                    @else
                        {{ __('tickets.show.history_change', ['from' => $history->from_status->label(), 'to' => $history->to_status->label()]) }}
                    @endif
                </p>
                <p class="text-xs text-gray-600">{{ __('tickets.show.by') }} {{ $history->user->name }} · <x-local-datetime :value="$history->created_at" /></p>
                @if ($history->comment)
                    <p class="mt-1 whitespace-pre-line break-words text-gray-800">{{ $history->comment }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</x-card>
