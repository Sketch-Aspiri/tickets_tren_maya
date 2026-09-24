<x-guest-layout>
    <h1 class="mb-4 text-lg font-semibold text-brand-green">{{ __('auth.login.title') }}</h1>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="email" :value="__('auth.login.email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('auth.login.password')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4 block">
            <label for="remember_me" class="inline-flex min-h-[44px] items-center">
                <input id="remember_me" type="checkbox" class="h-5 w-5 rounded border-gray-500 text-brand-green shadow-sm focus:ring-2 focus:ring-brand-teal" name="remember" value="1">
                <span class="ms-2 text-sm text-gray-700">{{ __('auth.login.remember') }}</span>
            </label>
        </div>

        <div class="mt-4 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-text-link :href="route('password.request')">
                {{ __('auth.login.forgot') }}
            </x-text-link>
            <x-primary-button class="justify-center">{{ __('auth.login.submit') }}</x-primary-button>
        </div>
    </form>

    <p class="mt-6 text-center text-sm text-gray-600">
        {{ __('auth.login.no_account') }}
        <x-text-link :href="route('register')" class="font-semibold">{{ __('auth.login.register_link') }}</x-text-link>
    </p>
</x-guest-layout>
