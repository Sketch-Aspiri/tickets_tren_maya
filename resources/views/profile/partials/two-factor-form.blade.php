<section>
    <header>
        <h2 class="text-lg font-semibold text-brand-green">{{ __('two_factor.settings.title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">
            @if ($user->hasTwoFactorEnabled())
                {{ __('two_factor.settings.enabled') }}
            @elseif ($user->requiresTwoFactor())
                {{ __('two_factor.settings.required') }}
            @else
                {{ __('two_factor.settings.disabled') }}
            @endif
        </p>
    </header>

    {{-- Codigos de recuperacion: se muestran una sola vez (flash), justo tras generarlos. --}}
    @if (session('recovery_codes'))
        <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4">
            <h3 class="text-sm font-semibold text-amber-900">{{ __('two_factor.settings.recovery_title') }}</h3>
            <p class="mt-1 text-sm text-amber-800">{{ __('two_factor.settings.recovery_warning') }}</p>
            <ul class="mt-3 grid grid-cols-1 gap-1 font-mono text-sm sm:grid-cols-2">
                @foreach (session('recovery_codes') as $recoveryCode)
                    <li>{{ $recoveryCode }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $user->hasTwoFactorEnabled())
        <div class="mt-6">
            <x-primary-button :href="route('two-factor.setup')">
                {{ __('two_factor.settings.enable') }}
            </x-primary-button>
        </div>
    @else
        <p class="mt-4 text-sm text-gray-600">{{ __('two_factor.settings.recovery_remaining', ['count' => $remainingRecoveryCodes]) }}</p>

        <form method="post" action="{{ route('two-factor.recovery-codes') }}" class="mt-4 space-y-3">
            @csrf
            <div>
                <x-input-label for="regen_password" :value="__('two_factor.settings.password')" />
                <x-text-input id="regen_password" name="password" type="password" class="mt-1 block w-full sm:w-72" autocomplete="current-password" required />
            </div>
            <x-secondary-button type="submit">{{ __('two_factor.settings.regenerate') }}</x-secondary-button>
        </form>

        @can('disableTwoFactor', $user)
            <form method="post" action="{{ route('two-factor.destroy') }}" class="mt-6 space-y-3 border-t border-brand-green/10 pt-6">
                @csrf
                @method('delete')
                <div>
                    <x-input-label for="disable_password" :value="__('two_factor.settings.password')" />
                    <x-text-input id="disable_password" name="password" type="password" class="mt-1 block w-full sm:w-72" autocomplete="current-password" required />
                </div>
                <x-danger-button>{{ __('two_factor.settings.disable') }}</x-danger-button>
            </form>
        @endcan
    @endif

    <x-input-error class="mt-3" :messages="$errors->twoFactor->all()" />
</section>
