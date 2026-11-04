<x-app-layout>
    <x-slot name="title">{{ __('stations.new') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('stations.new') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('stations.store') }}">
            @include('org.stations._form')
        </form>
    </x-card>
</x-app-layout>
