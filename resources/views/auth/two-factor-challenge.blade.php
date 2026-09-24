<x-guest-layout>
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <h1 class="mb-2 text-lg font-semibold text-brand-green">{{ __('two_factor.challenge.title') }}</h1>
        <p class="mb-4 text-sm text-gray-600">{{ __('two_factor.challenge.intro') }}</p>

        <form method="POST" action="{{ route('two-factor.verify') }}">
            @csrf

            <div x-show="! recovery">
                <x-input-label for="code" :value="__('two_factor.challenge.code')" />
                <x-text-input id="code" class="mt-1 block w-full tracking-widest" type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" x-bind:disabled="recovery" autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>

            <div x-show="recovery" x-cloak>
                <x-input-label for="recovery_code" :value="__('two_factor.challenge.recovery_code')" />
                <x-text-input id="recovery_code" class="mt-1 block w-full" type="text" name="recovery_code" autocomplete="off" x-bind:disabled="! recovery" />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />
            </div>

            <div class="mt-4 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                <x-text-link x-on:click="recovery = ! recovery">
                    <span x-show="! recovery">{{ __('two_factor.challenge.use_recovery') }}</span>
                    <span x-show="recovery" x-cloak>{{ __('two_factor.challenge.use_code') }}</span>
                </x-text-link>
                <x-primary-button class="justify-center">{{ __('two_factor.challenge.submit') }}</x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
            @csrf
            <x-text-link type="submit">{{ __('auth.logout') }}</x-text-link>
        </form>
    </div>
</x-guest-layout>
