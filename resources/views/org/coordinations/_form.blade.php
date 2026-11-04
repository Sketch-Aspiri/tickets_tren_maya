@csrf

<div>
    <x-input-label for="station_id" :value="__('coordinations.form.station')" />
    <x-select-input id="station_id" name="station_id" class="mt-1 block w-full" required>
        <option value="">{{ __('coordinations.form.choose_station') }}</option>
        @foreach ($stations as $station)
            <option value="{{ $station->id }}" @selected((int) old('station_id', $coordination->station_id) === $station->id)>{{ $station->name }}</option>
        @endforeach
    </x-select-input>
    <x-input-error :messages="$errors->get('station_id')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="name" :value="__('coordinations.form.name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $coordination->name)" maxlength="255" required />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="code" :value="__('coordinations.form.code')" />
    <x-text-input id="code" name="code" type="text" class="mt-1 block w-full uppercase" :value="old('code', $coordination->code)" maxlength="30" required />
    <p class="mt-1 text-xs text-gray-600">{{ __('coordinations.form.code_help') }}</p>
    <x-input-error :messages="$errors->get('code')" class="mt-2" />
</div>

<div class="mt-4">
    <input type="hidden" name="active" value="0">
    <label for="active" class="flex min-h-[44px] items-center gap-3 text-sm text-gray-800">
        <input id="active" name="active" type="checkbox" value="1" class="h-5 w-5 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" @checked((bool) old('active', $coordination->active))>
        {{ __('coordinations.form.active') }}
    </label>
    <x-input-error :messages="$errors->get('active')" class="mt-2" />
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>{{ __('common.actions.save') }}</x-primary-button>
    <x-text-link :href="route('coordinations.index')">{{ __('common.actions.cancel') }}</x-text-link>
</div>
