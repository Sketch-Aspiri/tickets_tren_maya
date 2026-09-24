<x-app-layout>
    <x-slot name="title">{{ __('tickets.edit') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="truncate text-xl font-semibold leading-tight text-brand-green">{{ __('tickets.edit') }} · {{ $ticket->folio }}</h1>
            <x-text-link :href="route('tickets.show', $ticket)" class="shrink-0">{{ __('common.actions.back') }}</x-text-link>
        </div>
    </x-slot>

    <x-card class="max-w-3xl">
        <form method="POST" action="{{ route('tickets.update', $ticket) }}">
            @method('PUT')
            @include('tickets._form')
        </form>
    </x-card>
</x-app-layout>
