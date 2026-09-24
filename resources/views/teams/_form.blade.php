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
        <x-input-error :messages="$coordinatorErrors" class="mt-2" />

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
