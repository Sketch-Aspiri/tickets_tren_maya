<x-app-layout>
    <x-slot name="title">{{ __('teams.new') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('teams.new') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('teams.store') }}">
            @include('teams._form')
        </form>
    </x-card>
</x-app-layout>
