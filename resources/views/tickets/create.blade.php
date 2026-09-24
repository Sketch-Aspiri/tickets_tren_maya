<x-app-layout>
    <x-slot name="title">{{ __('tickets.new') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('tickets.new') }}</h1>
    </x-slot>

    <x-card class="max-w-3xl">
        <form method="POST" action="{{ route('tickets.store') }}">
            @include('tickets._form')
        </form>
    </x-card>
</x-app-layout>
