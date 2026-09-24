@php
    $isSelf = auth()->user()->is($managedUser);
    $currentRole = $managedUser->roleEnum();
    $canReactivate = $managedUser->isInactive() && $currentRole !== null && (! $currentRole->requiresTeam() || $managedUser->team_id !== null);
@endphp

<x-app-layout>
    <x-slot name="title">{{ $managedUser->name }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="truncate text-xl font-semibold leading-tight text-brand-green">{{ $managedUser->name }}</h1>
            <x-text-link :href="route('users.index')" class="shrink-0">{{ __('common.actions.back') }}</x-text-link>
        </div>
    </x-slot>

    <x-card :title="__('users.show.details')">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('users.index.columns.email') }}</dt><dd class="break-all font-medium">{{ $managedUser->email }}</dd></div>
            <div><dt class="text-gray-500">{{ __('users.index.columns.status') }}</dt><dd><x-status-badge :status="$managedUser->status" /></dd></div>
            <div><dt class="text-gray-500">{{ __('users.index.columns.role') }}</dt><dd><x-role-badge :role="$currentRole" /></dd></div>
            <div><dt class="text-gray-500">{{ __('users.index.columns.team') }}</dt><dd class="font-medium">{{ $managedUser->team?->name ?? __('users.index.no_team') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('users.index.columns.registered') }}</dt><dd><x-local-datetime :value="$managedUser->created_at" /></dd></div>
            <div><dt class="text-gray-500">{{ __('users.show.two_factor') }}</dt><dd>{{ $managedUser->hasTwoFactorEnabled() ? __('users.show.two_factor_on') : __('users.show.two_factor_off') }}</dd></div>
        </dl>
    </x-card>

    {{-- Aprobar: cuentas pendientes o rechazadas. Rol obligatorio; equipo obligatorio salvo jefe de zona. --}}
    @if (! $managedUser->isActive())
        @can('approve', $managedUser)
            <x-card :title="__('users.show.approve_title')">
                <p class="mb-4 text-sm text-gray-600">{{ __('users.show.approve_intro') }}</p>
                <form method="POST" action="{{ route('users.approve', $managedUser) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <x-input-label for="approve_role" :value="__('users.show.role')" />
                        <x-select-input id="approve_role" name="role" class="mt-1 block w-full" required>
                            <option value="">{{ __('users.show.select_role') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role', $currentRole?->value) === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="approve_team" :value="__('users.show.team')" />
                        <x-select-input id="approve_team" name="team_id" class="mt-1 block w-full">
                            <option value="">{{ __('users.show.team_optional_for_jefe') }}</option>
                            @foreach ($teams as $team)
                                <option value="{{ $team->id }}" @selected((int) old('team_id', $managedUser->team_id) === $team->id)>{{ $team->name }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('team_id')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-primary-button>{{ __('users.show.approve_submit') }}</x-primary-button>
                    </div>
                </form>
            </x-card>
        @endcan
    @endif

    @if ($managedUser->isPending())
        @can('reject', $managedUser)
            <x-card :title="__('users.show.reject_title')">
                <x-danger-button type="button" x-data="modalTrigger" data-modal="reject-user" x-on:click.prevent="open">{{ __('users.show.reject_submit') }}</x-danger-button>

                <x-modal name="reject-user" maxWidth="md" focusable>
                    <form method="POST" action="{{ route('users.reject', $managedUser) }}" class="p-6">
                        @csrf
                        <p class="text-sm text-gray-700">{{ __('users.show.reject_confirm') }}</p>
                        <x-input-label for="reject_reason" class="mt-4" :value="__('users.show.reason')" />
                        <x-text-input id="reject_reason" name="reason" type="text" class="mt-1 block w-full" maxlength="500" />
                        <div class="mt-6 flex justify-end gap-3">
                            <x-secondary-button x-on:click="close">{{ __('common.actions.cancel') }}</x-secondary-button>
                            <x-danger-button>{{ __('users.show.reject_submit') }}</x-danger-button>
                        </div>
                    </form>
                </x-modal>
            </x-card>
        @endcan
    @endif

    {{-- Cambiar rol / equipo: solo cuentas activas. --}}
    @if ($managedUser->isActive())
        @can('update', $managedUser)
            <x-card :title="__('users.show.role_team_title')">
                <form method="POST" action="{{ route('users.update', $managedUser) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="update_role" :value="__('users.show.role')" />
                        <x-select-input id="update_role" name="role" class="mt-1 block w-full" required>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role', $currentRole?->value) === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="update_team" :value="__('users.show.team')" />
                        <x-select-input id="update_team" name="team_id" class="mt-1 block w-full">
                            <option value="">{{ __('users.show.team_optional_for_jefe') }}</option>
                            @foreach ($teams as $team)
                                <option value="{{ $team->id }}" @selected((int) old('team_id', $managedUser->team_id) === $team->id)>{{ $team->name }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('team_id')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-primary-button>{{ __('users.show.save_role_team') }}</x-primary-button>
                    </div>
                </form>
            </x-card>
        @endcan
    @endif

    {{-- Estado: activar / inactivar. --}}
    <x-card :title="__('users.show.status_title')">
        @if ($isSelf)
            <p class="text-sm text-gray-600">{{ __('users.show.cannot_edit_self_hint') }}</p>
        @endif

        @if ($canReactivate)
            @can('activate', $managedUser)
                <form method="POST" action="{{ route('users.activate', $managedUser) }}">
                    @csrf
                    <x-primary-button>{{ __('users.show.activate') }}</x-primary-button>
                </form>
            @endcan
        @endif

        @if ($managedUser->isActive() && ! $isSelf)
            @can('deactivate', $managedUser)
                <x-danger-button type="button" x-data="modalTrigger" data-modal="deactivate-user" x-on:click.prevent="open">{{ __('users.show.deactivate') }}</x-danger-button>

                <x-modal name="deactivate-user" maxWidth="md" focusable>
                    <form method="POST" action="{{ route('users.deactivate', $managedUser) }}" class="p-6">
                        @csrf
                        <p class="text-sm text-gray-700">{{ __('users.show.deactivate_confirm') }}</p>
                        <x-input-label for="deactivate_reason" class="mt-4" :value="__('users.show.reason')" />
                        <x-text-input id="deactivate_reason" name="reason" type="text" class="mt-1 block w-full" maxlength="500" />
                        <div class="mt-6 flex justify-end gap-3">
                            <x-secondary-button x-on:click="close">{{ __('common.actions.cancel') }}</x-secondary-button>
                            <x-danger-button>{{ __('users.show.deactivate') }}</x-danger-button>
                        </div>
                    </form>
                </x-modal>
            @endcan
        @endif
    </x-card>
</x-app-layout>
