@props([
    'users',
    'selectedResponsible' => 0,
    'selectedCollaborators' => [],
    'responsibleLabel',
    'selectResponsible',
    'collaboratorsLabel',
    'idPrefix' => 'assign',
    'filterBy' => 'coordination',
])

@php
    // Mismos nombres de campo que el formulario anterior: `responsible_id` y `collaborator_ids[]`.
    $max = (int) config('tickets.max_collaborators');
    $selectedResponsible = (int) $selectedResponsible;
    $selectedIds = array_values(array_diff(array_map('intval', (array) $selectedCollaborators), [$selectedResponsible]));
    $initialCount = $users->whereIn('id', $selectedIds)->count();
    // Coordinaciones de la persona (via sus equipos): el selector las oculta si no cuadran con la elegida.
    $coordinationsOf = fn ($candidate): string => $candidate->relationLoaded('teams') ? $candidate->teams->pluck('coordination_id')->filter()->unique()->implode(',') : '';
    $teamsOf = fn ($candidate): string => $candidate->relationLoaded('teams') ? $candidate->teams->pluck('id')->implode(',') : '';
@endphp

{{-- Selector de responsable (lista desplegable) y de colaboradores (casillas con buscador local). Sin JavaScript las
     casillas funcionan igual y el servidor descarta al responsable si viniera repetido; con Alpine se agrega el
     filtro por nombre, el contador y la exclusion del responsable. La logica vive en Alpine.data('assigneePicker'). --}}
<div x-data="assigneePicker" x-on:change="onChange" x-on:cascade-coordination.window="onCoordination" data-filter-by="{{ $filterBy === 'team' ? 'team' : 'coordination' }}" data-max="{{ $max }}" data-counter="{{ __('workflow.picker.counter', ['count' => '{count}', 'max' => '{max}']) }}" {{ $attributes->merge(['class' => 'grid gap-4 sm:col-span-2 sm:grid-cols-2']) }}>
    <div>
        <x-input-label :for="$idPrefix.'_responsible'" :value="$responsibleLabel" />
        <x-select-input :id="$idPrefix.'_responsible'" name="responsible_id" class="mt-1 block w-full" required data-responsible>
            <option value="">{{ $selectResponsible }}</option>
            @foreach ($users as $candidate)
                <option value="{{ $candidate->id }}" data-coordinations="{{ $coordinationsOf($candidate) }}" data-teams="{{ $teamsOf($candidate) }}" @selected($selectedResponsible === $candidate->id)>{{ $candidate->name }}</option>
            @endforeach
        </x-select-input>
                    <x-input-error :messages="$errors->get('responsible_id')" class="mt-2" />
    </div>

    <fieldset aria-describedby="{{ $idPrefix }}_collaborators_hint">
        <legend class="block text-sm font-medium text-gray-800">{{ $collaboratorsLabel }}</legend>
        <p id="{{ $idPrefix }}_collaborators_hint" class="mt-0.5 text-xs text-gray-600">{{ __('workflow.picker.hint') }}</p>

        {{-- Buscador: solo tiene sentido con JavaScript (x-cloak lo oculta si Alpine no corre); no viaja en el formulario. --}}
        <div x-show="ready" x-cloak class="mt-2">
            <label for="{{ $idPrefix }}_filter" class="sr-only">{{ __('workflow.picker.filter') }}</label>
            <input id="{{ $idPrefix }}_filter" type="search" autocomplete="off" x-on:input="onFilter" placeholder="{{ __('workflow.picker.filter_placeholder') }}" class="block min-h-[44px] w-full rounded-lg border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">
        </div>

        <p class="mt-2 text-xs font-semibold text-gray-800" role="status" aria-live="polite" x-text="counterText">{{ __('workflow.picker.counter', ['count' => $initialCount, 'max' => $max]) }}</p>

        <div class="mt-1 max-h-64 divide-y divide-brand-green/10 overflow-y-auto rounded-lg border border-gray-300 bg-white" role="group" aria-label="{{ $collaboratorsLabel }}">
            @forelse ($users as $candidate)
                @php
                    $isResponsible = $selectedResponsible === $candidate->id;
                    $isChecked = in_array($candidate->id, $selectedIds, true);
                    // Equipo y coordinacion: con varias coordinaciones un nombre de equipo solo no basta.
                    $teamNames = $candidate->relationLoaded('teams')
                        ? $candidate->teams->map(fn ($team): string => $team->relationLoaded('coordination') && $team->coordination ? $team->name.' · '.$team->coordination->name : $team->name)->implode(', ')
                        : '';
                @endphp
                <div data-row data-coordinations="{{ $coordinationsOf($candidate) }}" data-teams="{{ $teamsOf($candidate) }}" class="{{ $isResponsible ? 'opacity-50' : '' }}">
                    <label class="flex min-h-[44px] cursor-pointer items-center gap-3 px-3 py-2 hover:bg-brand-mist">
                        <input type="checkbox" name="collaborator_ids[]" value="{{ $candidate->id }}" data-assignee class="h-5 w-5 shrink-0 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" @checked($isChecked && ! $isResponsible) @disabled($isResponsible)>
                        <x-user-avatar :user="$candidate" size="sm" />
                        <span class="min-w-0 flex-1 text-sm">
                            <span class="block truncate font-medium text-gray-900">{{ $candidate->name }}</span>
                            @if ($teamNames !== '')
                                <span class="block truncate text-xs text-gray-700">{{ $teamNames }}</span>
                            @endif
                        </span>
                        <span data-responsible-tag class="shrink-0 text-xs font-semibold text-brand-green {{ $isResponsible ? '' : 'hidden' }}">{{ __('workflow.picker.is_responsible') }}</span>
                    </label>
                </div>
            @empty
                <p class="p-3 text-sm text-gray-700">{{ __('workflow.picker.no_users') }}</p>
            @endforelse
        </div>

        <p x-show="noResults" x-cloak class="mt-1 text-sm text-gray-700">{{ __('workflow.picker.no_results') }}</p>
        <p x-show="noTeam" x-cloak class="mt-1 text-sm text-gray-700">{{ __('workflow.picker.choose_team_first') }}</p>

            <x-input-error :messages="$errors->get('collaborator_ids')" class="mt-2" />
            <x-input-error :messages="$errors->get('collaborator_ids.*')" class="mt-2" />
    </fieldset>
</div>
