<x-app-layout>
    <x-slot name="title">{{ __('profile.title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('profile.title') }}</h1>
    </x-slot>

    <div class="space-y-4">
        <x-card class="max-w-2xl">
            @include('profile.partials.update-profile-information-form')
        </x-card>

        <x-card class="max-w-2xl">
            @include('profile.partials.update-password-form')
        </x-card>

        <x-card class="max-w-2xl">
            @include('profile.partials.two-factor-form')
        </x-card>
    </div>
</x-app-layout>
