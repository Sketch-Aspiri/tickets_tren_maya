<x-app-layout>
    <x-slot name="title">{{ __('categories.new') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('categories.new') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('categories.store') }}">
            @include('categories._form')
        </form>
    </x-card>
</x-app-layout>
