<x-app-layout>
    <x-slot name="title">{{ __('mailboxes.edit') }}</x-slot>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('mailboxes.edit') }}</h1>
            <p class="text-sm text-gray-700">{{ $coordination->station->name }} › {{ $coordination->name }}</p>
        </div>
    </x-slot>

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('mailboxes.update', $coordination) }}" autocomplete="off">
            @csrf
            @method('PUT')

            <div>
                <x-input-label for="host" :value="__('mailboxes.form.host')" />
                <x-text-input id="host" name="host" type="text" class="mt-1 block w-full" :value="old('host', $mailbox->host)" maxlength="253" required autofocus />
                <x-input-error :messages="$errors->get('host')" class="mt-2" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="port" :value="__('mailboxes.form.port')" />
                    <x-text-input id="port" name="port" type="number" min="1" max="65535" class="mt-1 block w-full" :value="old('port', $mailbox->port)" required />
                    <x-input-error :messages="$errors->get('port')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="encryption" :value="__('mailboxes.form.encryption')" />
                    <x-select-input id="encryption" name="encryption" class="mt-1 block w-full" required>
                        @foreach ($encryptions as $encryption)
                            <option value="{{ $encryption }}" @selected(old('encryption', $mailbox->encryption) === $encryption)>{{ __('mailboxes.encryptions.'.$encryption) }}</option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('encryption')" class="mt-2" />
                </div>
            </div>

            <div class="mt-4">
                <x-input-label for="username" :value="__('mailboxes.form.username')" />
                <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username', $mailbox->username)" maxlength="255" required autocomplete="off" />
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="password" :value="__('mailboxes.form.password')" />
                {{-- La contrasena guardada NUNCA se vuelve a mostrar; vacio al editar conserva la actual. --}}
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" maxlength="255" autocomplete="new-password" :required="! $mailbox->exists" />
                <p class="mt-1 text-xs text-gray-600">{{ $mailbox->exists ? __('mailboxes.form.password_keep') : __('mailboxes.form.password_help') }}</p>
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="folder" :value="__('mailboxes.form.folder')" />
                <x-text-input id="folder" name="folder" type="text" class="mt-1 block w-full" :value="old('folder', $mailbox->folder)" maxlength="100" required />
                <x-input-error :messages="$errors->get('folder')" class="mt-2" />
            </div>

            <div class="mt-4">
                <input type="hidden" name="active" value="0">
                <label for="active" class="flex min-h-[44px] items-center gap-3 text-sm text-gray-800">
                    <input id="active" name="active" type="checkbox" value="1" class="h-5 w-5 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" @checked((bool) old('active', $mailbox->active))>
                    {{ __('mailboxes.form.active') }}
                </label>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <x-primary-button>{{ __('common.actions.save') }}</x-primary-button>
                <x-text-link :href="auth()->user()->can('viewAny', \App\Models\CoordinationMailbox::class) ? route('mailboxes.index') : route('inbox-settings.index')">{{ __('common.actions.cancel') }}</x-text-link>
            </div>
        </form>
    </x-card>
</x-app-layout>
