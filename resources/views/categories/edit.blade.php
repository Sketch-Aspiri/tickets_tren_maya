<x-app-layout>
    <x-slot name="title">{{ __('categories.edit') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('categories.edit') }}</h1>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('categories.update', $category) }}">
            @method('PUT')
            @include('categories._form')
        </form>
    </x-card>
</x-app-layout>
