<x-app-layout>
    <x-slot name="title">{{ __('sheet_sources.title') }}</x-slot>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('sheet_sources.title') }}</h1>
            <p class="text-sm text-gray-700">{{ $coordination->station->name }} › {{ $coordination->name }}</p>
        </div>
    </x-slot>

    <x-card class="max-w-2xl">
        <p class="text-sm text-gray-700">{{ __('sheet_sources.form.intro') }}</p>

        <form method="POST" action="{{ route('sheet-sources.update', $coordination) }}" class="mt-4 space-y-4" autocomplete="off">
            @csrf
            @method('PUT')

            <div>
                <x-input-label for="name" :value="__('sheet_sources.form.name')" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $source->name)" maxlength="100" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="share_url" :value="__('sheet_sources.form.share_url')" />
                <x-text-input id="share_url" name="share_url" type="url" class="mt-1 block w-full" :value="old('share_url', $source->share_url)" maxlength="2000" aria-describedby="share_url_hint" />
                <p id="share_url_hint" class="mt-1 text-xs text-gray-600">{{ __('sheet_sources.form.share_url_hint') }}</p>
                <x-input-error :messages="$errors->get('share_url')" class="mt-2" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="worksheet" :value="__('sheet_sources.form.worksheet')" />
                    <x-text-input id="worksheet" name="worksheet" type="text" class="mt-1 block w-full" :value="old('worksheet', $source->worksheet)" maxlength="100" aria-describedby="worksheet_hint" />
                    <p id="worksheet_hint" class="mt-1 text-xs text-gray-600">{{ __('sheet_sources.form.worksheet_hint') }}</p>
                    <x-input-error :messages="$errors->get('worksheet')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="header_row" :value="__('sheet_sources.form.header_row')" />
                    <x-text-input id="header_row" name="header_row" type="number" min="1" max="50" class="mt-1 block w-full" :value="old('header_row', $source->header_row ?? 1)" required />
                    <x-input-error :messages="$errors->get('header_row')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="subject_column" :value="__('sheet_sources.form.subject_column')" />
                <x-text-input id="subject_column" name="subject_column" type="text" class="mt-1 block w-full" :value="old('subject_column', $source->subject_column)" maxlength="100" required />
                <x-input-error :messages="$errors->get('subject_column')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="body_columns_text" :value="__('sheet_sources.form.body_columns')" />
                <textarea id="body_columns_text" name="body_columns_text" rows="4" aria-describedby="body_columns_hint" class="mt-1 block w-full rounded-lg border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">{{ old('body_columns_text', implode("\n", (array) $source->body_columns)) }}</textarea>
                <p id="body_columns_hint" class="mt-1 text-xs text-gray-600">{{ __('sheet_sources.form.body_columns_hint') }}</p>
                <x-input-error :messages="$errors->get('body_columns')" class="mt-2" />
                <x-input-error :messages="$errors->get('body_columns.*')" class="mt-2" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="requester_name_column" :value="__('sheet_sources.form.requester_name_column')" />
                    <x-text-input id="requester_name_column" name="requester_name_column" type="text" class="mt-1 block w-full" :value="old('requester_name_column', $source->requester_name_column)" maxlength="100" />
                    <x-input-error :messages="$errors->get('requester_name_column')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="requester_email_column" :value="__('sheet_sources.form.requester_email_column')" />
                    <x-text-input id="requester_email_column" name="requester_email_column" type="text" class="mt-1 block w-full" :value="old('requester_email_column', $source->requester_email_column)" maxlength="100" />
                    <x-input-error :messages="$errors->get('requester_email_column')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="key_column" :value="__('sheet_sources.form.key_column')" />
                <x-text-input id="key_column" name="key_column" type="text" class="mt-1 block w-full" :value="old('key_column', $source->key_column)" maxlength="100" aria-describedby="key_column_hint" />
                <p id="key_column_hint" class="mt-1 text-xs text-gray-600">{{ __('sheet_sources.form.key_column_hint') }}</p>
                <x-input-error :messages="$errors->get('key_column')" class="mt-2" />
            </div>

            <label for="active" class="flex min-h-[44px] items-center gap-2 text-sm text-gray-800">
                <input id="active" name="active" type="checkbox" value="1" class="h-4 w-4 rounded border-gray-300 text-brand-green focus:ring-brand-teal" @checked(old('active', $source->active ?? true))>
                {{ __('sheet_sources.form.active') }}
            </label>

            <div class="flex items-center gap-3">
                <x-primary-button>{{ __('sheet_sources.form.save') }}</x-primary-button>
                <x-text-link :href="route('inbox-settings.index')">{{ __('common.actions.cancel') }}</x-text-link>
            </div>
        </form>
    </x-card>
</x-app-layout>
