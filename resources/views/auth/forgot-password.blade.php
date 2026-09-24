<x-guest-layout>
    <h1 class="mb-2 text-lg font-semibold text-brand-green">{{ __('auth.forgot.title') }}</h1>
    <p class="mb-4 text-sm text-gray-600">{{ __('auth.forgot.intro') }}</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div>
            <x-input-label for="email" :value="__('auth.login.email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-text-link :href="route('login')">{{ __('auth.forgot.back') }}</x-text-link>
            <x-primary-button class="justify-center">{{ __('auth.forgot.submit') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
