<x-guest-layout>
    <h1 class="mb-2 text-lg font-semibold text-brand-green">{{ __('auth.register.title') }}</h1>
    <p class="mb-4 text-sm text-gray-600">{{ __('auth.register.intro') }}</p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div>
            <x-input-label for="name" :value="__('auth.register.name')" />
            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" :value="__('auth.register.email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('auth.register.password')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="new-password" />
            <p class="mt-1 text-xs text-gray-500">{{ __('auth.register.password_hint') }}</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('auth.register.password_confirmation')" />
            <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-4 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-text-link :href="route('login')">
                {{ __('auth.register.have_account') }}
            </x-text-link>
            <x-primary-button class="justify-center">{{ __('auth.register.submit') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
