@csrf

<div>
    <x-input-label for="name" :value="__('categories.form.name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $category->name)" maxlength="255" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mt-4">
    {{-- El 0 oculto garantiza que un checkbox desmarcado se envie como "inactiva". --}}
    <input type="hidden" name="active" value="0">
    <label for="active" class="flex min-h-[44px] items-center gap-3 text-sm text-gray-800">
        <input id="active" name="active" type="checkbox" value="1" class="h-5 w-5 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" @checked((bool) old('active', $category->active))>
        {{ __('categories.form.active') }}
    </label>
    <x-input-error :messages="$errors->get('active')" class="mt-2" />
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>{{ __('common.actions.save') }}</x-primary-button>
    <x-text-link :href="route('categories.index')">{{ __('common.actions.cancel') }}</x-text-link>
</div>
