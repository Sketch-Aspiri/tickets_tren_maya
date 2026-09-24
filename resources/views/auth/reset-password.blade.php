<x-guest-layout>
    <h1 class="mb-4 text-lg font-semibold text-brand-green">{{ __('auth.reset.title') }}</h1>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <x-input-label for="email" :value="__('auth.login.email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('profile.password.new')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="new-password" />
            <p class="mt-1 text-xs text-gray-500">{{ __('auth.register.password_hint') }}</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('profile.password.confirm')" />
            <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-4 flex justify-end">
            <x-primary-button>{{ __('auth.reset.submit') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
