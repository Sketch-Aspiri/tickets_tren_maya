<x-app-layout>
    <x-slot name="title">{{ __('coordinations.edit') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('coordinations.edit') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('coordinations.update', $coordination) }}">
            @method('PUT')
            @include('org.coordinations._form')
        </form>
    </x-card>
</x-app-layout>
