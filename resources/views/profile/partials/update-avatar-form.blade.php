<section>
    <header>
        <h2 class="text-lg font-semibold text-brand-green">{{ __('profile.avatar.title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('profile.avatar.intro') }}</p>
    </header>

    <div class="mt-6 flex flex-wrap items-center gap-6">
        <x-user-avatar :user="$user" size="lg" :label="__('profile.avatar.current', ['name' => $user->name])" />

        <div class="min-w-0 flex-1 space-y-3">
            <form method="POST" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <x-input-label for="avatar" :value="__('profile.avatar.label')" />
                <input id="avatar" name="avatar" type="file" required accept=".jpg,.jpeg,.png,image/jpeg,image/png" aria-describedby="avatar_hint" class="block min-h-[44px] w-full text-sm text-gray-800 file:mr-3 file:min-h-[44px] file:cursor-pointer file:rounded-lg file:border file:border-brand-teal file:bg-white file:px-4 file:text-sm file:font-semibold file:text-brand-green hover:file:bg-brand-mist">
                <p id="avatar_hint" class="text-xs text-gray-600">{{ __('profile.avatar.hint', ['max' => (int) (config('tickets.avatars.max_kilobytes') / 1024)]) }}</p>
                <x-input-error :messages="$errors->get('avatar')" />
                <x-primary-button>{{ __('profile.avatar.submit') }}</x-primary-button>
            </form>

            @if ($user->avatar_path)
                <form method="POST" action="{{ route('profile.avatar.destroy') }}" x-data="confirmSubmit" data-confirm="{{ __('profile.avatar.remove_confirm') }}" x-on:submit="onSubmit">
                    @csrf
                    @method('DELETE')
                    <x-danger-button>{{ __('profile.avatar.remove') }}</x-danger-button>
                </form>
            @endif
        </div>
    </div>
</section>
