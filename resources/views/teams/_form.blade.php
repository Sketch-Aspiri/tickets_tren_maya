@csrf

@php
    $isEditing = $team->exists;
    $coordinatorErrors = $errors->get('coordinator_id');
@endphp

{{-- Con el selector oculto (crear) el error de coordinador no tiene campo donde mostrarse: va en un aviso sobre el formulario. --}}
@if (! $isEditing && $coordinatorErrors)
    <div class="mb-4 rounded-md border-l-4 border-red-600 bg-red-50 p-3" role="alert">
        <p class="text-sm font-semibold text-red-800">{{ __('teams.form.errors_heading') }}</p>
        <x-input-error :messages="$coordinatorErrors" class="mt-1" />
    </div>
@endif

<div>
    <x-input-label for="coordination_id" :value="__('teams.form.coordination')" />
    <x-select-input id="coordination_id" name="coordination_id" class="mt-1 block w-full" required>
        <option value="">{{ __('teams.form.choose_coordination') }}</option>
        @foreach ($coordinations->groupBy(fn ($coordination) => $coordination->station->name) as $stationName => $group)
            <optgroup label="{{ $stationName }}">
                @foreach ($group as $coordination)
                    <option value="{{ $coordination->id }}" @selected((int) old('coordination_id', $team->coordination_id) === $coordination->id)>{{ $coordination->name }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </x-select-input>
    <x-input-error :messages="$errors->get('coordination_id')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="name" :value="__('teams.form.name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $team->name)" maxlength="255" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

@if ($isEditing)
    <div class="mt-4">
        <x-input-label for="coordinator_id" :value="__('teams.form.coordinator')" />
        <x-select-input id="coordinator_id" name="coordinator_id" class="mt-1 block w-full" :aria-describedby="$coordinators->isEmpty() ? 'coordinator_id_help' : null">
            <option value="">{{ __('teams.form.no_coordinator') }}</option>
            @foreach ($coordinators as $coordinator)
                <option value="{{ $coordinator->id }}" @selected((int) old('coordinator_id', $team->coordinator_id) === $coordinator->id)>{{ $coordinator->name }}</option>
            @endforeach
        </x-select-input>
        <p class="mt-1 text-xs text-gray-600">{{ __('teams.form.coordinator_hint') }}</p>
        <x-input-error :messages="$coordinatorErrors" class="mt-2" />

        @if ($coordinators->isNotEmpty())
            @php
                $selectedAdditional = array_map('intval', (array) old('additional_coordinator_ids', $team->additionalCoordinators->pluck('id')->all()));
                $additionalErrors = array_merge($errors->get('additional_coordinator_ids'), $errors->get('additional_coordinator_ids.*'));
            @endphp
            <fieldset class="mt-4">
                <legend class="text-sm font-medium text-gray-700">{{ __('teams.form.additional') }}</legend>
                <p class="mt-1 text-xs text-gray-600">{{ __('teams.form.additional_hint') }}</p>
                <div class="mt-2 grid gap-1 sm:grid-cols-2">
                    @foreach ($coordinators as $coordinator)
                        <label class="flex min-h-[44px] items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm">
                            <input type="checkbox" name="additional_coordinator_ids[]" value="{{ $coordinator->id }}" class="rounded border-gray-500 text-brand-green focus:ring-brand-teal" @checked(in_array($coordinator->id, $selectedAdditional, true))>
                            <span>{{ $coordinator->name }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$additionalErrors" class="mt-2" />
            </fieldset>
        @endif

        @if ($coordinators->isEmpty())
            <div id="coordinator_id_help" class="mt-2 rounded-md border-l-4 border-brand-teal bg-brand-mint/15 p-3 text-sm text-gray-800">
                <p>{{ __('teams.form.coordinator_none_eligible') }}</p>
                @can('viewAny', \App\Models\User::class)
                    <x-text-link :href="route('users.index')">{{ __('teams.form.go_to_users') }}</x-text-link>
                @endcan
            </div>
        @endif
    </div>
@else
    <p class="mt-4 rounded-md border-l-4 border-brand-teal bg-brand-mint/15 p-3 text-sm text-gray-800">{{ __('teams.form.coordinator_on_create_note') }}</p>
@endif

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>{{ __('common.actions.save') }}</x-primary-button>
    <x-text-link :href="route('teams.index')">{{ __('common.actions.cancel') }}</x-text-link>
</div>
