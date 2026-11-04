<x-app-layout>
    <x-slot name="title">{{ __('stations.edit') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('stations.edit') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('stations.update', $station) }}">
            @method('PUT')
            @include('org.stations._form')
        </form>
    </x-card>
</x-app-layout>
