<x-app-layout>
    <x-slot name="title">{{ __('activities.edit') }}</x-slot>
    <x-slot name="header">
        <div class="min-w-0">
            <p class="font-mono text-xs text-gray-700">{{ $activity->folio }}</p>
            <h1 class="truncate text-xl font-semibold leading-tight text-brand-green">{{ __('activities.edit') }}</h1>
        </div>
    </x-slot>

    <x-card class="max-w-3xl">
        <form method="POST" action="{{ route('activities.update', $activity) }}">
            @method('PUT')
            @include('activities._form')
        </form>
    </x-card>
</x-app-layout>
