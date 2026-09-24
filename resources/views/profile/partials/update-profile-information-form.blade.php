<section>
    <header>
        <h2 class="text-lg font-semibold text-brand-green">{{ __('profile.info.title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('profile.info.intro') }}</p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('profile.info.name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('profile.info.email')" />
            <x-text-input id="email" type="email" class="mt-1 block w-full bg-gray-100" :value="$user->email" disabled />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('profile.info.submit') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p class="text-sm text-gray-600">{{ __('profile.info.saved') }}</p>
            @endif
        </div>
    </form>
</section>
