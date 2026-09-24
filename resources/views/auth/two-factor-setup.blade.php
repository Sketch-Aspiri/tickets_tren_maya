<x-guest-layout>
    <h1 class="mb-2 text-lg font-semibold text-brand-green">{{ __('two_factor.setup.title') }}</h1>

    @if ($isRequired)
        <p class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">{{ __('two_factor.setup.required_notice') }}</p>
    @endif

    <ol class="space-y-4 text-sm text-gray-700">
        <li>
            <p>{{ __('two_factor.setup.step_scan') }}</p>
            {{-- SVG generado en el servidor por BaconQrCode: solo trazos, sin texto interpretable del usuario. --}}
            <div class="mt-3 flex justify-center rounded-md border border-brand-green/15 bg-white p-2">{!! $qr_svg !!}</div>
        </li>
        <li>
            <p>{{ __('two_factor.setup.step_manual') }}</p>
            <code class="mt-1 block break-all rounded bg-gray-100 px-2 py-1 font-mono text-sm">{{ $secret }}</code>
        </li>
        <li>
            <p>{{ __('two_factor.setup.step_confirm') }}</p>
            <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-2">
                @csrf
                <x-input-label for="code" :value="__('two_factor.setup.code')" />
                <x-text-input id="code" class="mt-1 block w-full tracking-widest" type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />

                <div class="mt-4 flex justify-end">
                    <x-primary-button>{{ __('two_factor.setup.submit') }}</x-primary-button>
                </div>
            </form>
        </li>
    </ol>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <x-text-link type="submit">{{ __('auth.logout') }}</x-text-link>
    </form>
</x-guest-layout>
