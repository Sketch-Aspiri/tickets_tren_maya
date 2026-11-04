<x-app-layout>
    <x-slot name="title">{{ __('coordinations.new') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('coordinations.new') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('coordinations.store') }}">
            @include('org.coordinations._form')
        </form>
    </x-card>
</x-app-layout>
