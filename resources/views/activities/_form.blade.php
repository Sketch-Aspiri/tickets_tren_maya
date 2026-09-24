@csrf

@php
    /** @var \App\Models\Activity $activity */
    $isEditing = $activity->exists;
    $isTemplate = $activity->isTemplate();
    // El editor de recurrencia solo existe al crear (para volverla plantilla) o al editar una plantilla.
    $showEditor = ! $isEditing || $isTemplate;
    $recurring = $isTemplate || (bool) old('is_recurring');
    $selectedPriority = old('priority', $activity->priority?->value);
    $selectedCategory = old('category_id', $activity->category_id);
    $startDate = old('start_date', $activity->start_date?->toDateString());
    $dueDate = old('due_date', $activity->due_date?->toDateString());
    // El valor viene del usuario (old): se normaliza a una frecuencia valida antes de usarlo en un atributo data-*.
    $frequency = \App\Enums\RecurrenceFrequency::tryFrom((string) old('recurrence.frequency', $rule['frequency'] ?? ''))?->value ?? 'weekly';
    $interval = old('recurrence.interval', $rule['interval'] ?? 1);
    $selectedDays = array_map('intval', (array) old('recurrence.days_of_week', $rule['days_of_week'] ?? []));
    $dayOfMonth = old('recurrence.day_of_month', $rule['day_of_month'] ?? '');
    $endsAt = old('recurrence.ends_at', $rule['ends_at'] ?? '');
    $selectedResponsible = (int) old('responsible_id');
    $selectedCollaborators = array_map('intval', (array) old('collaborator_ids', []));
@endphp

<div class="space-y-4">
    @if ($teams->isNotEmpty())
        <div>
            <x-input-label for="team_id" :value="__('activities.form.team')" />
            <x-select-input id="team_id" name="team_id" class="mt-1 block w-full" required aria-describedby="team_id_hint">
                <option value="">{{ __('activities.form.select_team') }}</option>
                @foreach ($teams as $team)
                    <option value="{{ $team->id }}" @selected((int) old('team_id') === $team->id)>{{ $team->name }}</option>
                @endforeach
            </x-select-input>
            <p id="team_id_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.form.team_hint') }}</p>
            <x-input-error :messages="$errors->get('team_id')" class="mt-2" />
        </div>
    @endif

    <div>
        <x-input-label for="title" :value="__('activities.form.title')" />
        <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $activity->title)" maxlength="255" required autofocus />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="description" :value="__('activities.form.description')" />
        <textarea id="description" name="description" rows="6" maxlength="5000" required class="mt-1 block min-h-[44px] w-full rounded-md border-gray-500 shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">{{ old('description', $activity->description) }}</textarea>
        <x-input-error :messages="$errors->get('description')" class="mt-2" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="priority" :value="__('activities.form.priority')" />
            <x-select-input id="priority" name="priority" class="mt-1 block w-full" required>
                @foreach ($priorities as $priority)
                    <option value="{{ $priority->value }}" @selected($selectedPriority === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('priority')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="category_id" :value="__('activities.form.category')" />
            <x-select-input id="category_id" name="category_id" class="mt-1 block w-full">
                <option value="">{{ __('activities.form.no_category') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) $selectedCategory === $category->id)>{{ $category->name }}{{ $category->active ? '' : ' '.__('activities.form.inactive_category') }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="start_date" :value="__('activities.form.start_date')" />
            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full" :value="$startDate" aria-describedby="start_date_hint" :required="$isTemplate" />
            <p id="start_date_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.form.start_date_hint') }}</p>
            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="due_date" :value="__('activities.form.due_date')" />
            <x-text-input id="due_date" name="due_date" type="date" class="mt-1 block w-full" :value="$dueDate" aria-describedby="due_date_hint" />
            <p id="due_date_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.form.due_date_hint') }}</p>
            <x-input-error :messages="$errors->get('due_date')" class="mt-2" />
        </div>
    </div>

    {{-- Asignacion inicial (solo al crear; despues se cambia desde el detalle). --}}
    @if (! $isEditing)
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="responsible_id" :value="__('activities.form.responsible')" />
                <x-select-input id="responsible_id" name="responsible_id" class="mt-1 block w-full" required>
                    <option value="">{{ __('activities.form.select_responsible') }}</option>
                    @foreach ($assignableUsers as $candidate)
                        <option value="{{ $candidate->id }}" @selected($selectedResponsible === $candidate->id)>{{ $candidate->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('responsible_id')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="collaborator_ids" :value="__('activities.form.collaborators')" />
                <x-select-input id="collaborator_ids" name="collaborator_ids[]" class="mt-1 block w-full" multiple size="4" aria-describedby="collaborators_hint">
                    @foreach ($assignableUsers as $candidate)
                        <option value="{{ $candidate->id }}" @selected(in_array($candidate->id, $selectedCollaborators, true))>{{ $candidate->name }}</option>
                    @endforeach
                </x-select-input>
                <p id="collaborators_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.form.collaborators_hint') }}</p>
                <x-input-error :messages="$errors->get('collaborator_ids')" class="mt-2" />
                <x-input-error :messages="$errors->get('collaborator_ids.*')" class="mt-2" />
            </div>
        </div>
    @else
        <p class="text-sm text-gray-700">{{ __('activities.form.assignment_after') }}</p>
    @endif

    {{-- Editor de recurrencia: campos normales; Alpine (recurrenceEditor) solo muestra/oculta segun la frecuencia. --}}
    @if ($showEditor)
        <div x-data="recurrenceEditor" data-recurring="{{ $recurring ? '1' : '0' }}" data-frequency="{{ $frequency }}" class="space-y-4 rounded-md border border-brand-green/10 bg-brand-mist/40 p-4">
            @unless ($isTemplate)
                <div>
                    <label for="is_recurring" class="flex min-h-[44px] items-center gap-3 text-sm font-medium text-gray-900">
                        <input id="is_recurring" name="is_recurring" type="checkbox" value="1" x-on:change="onToggle" class="h-5 w-5 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" aria-describedby="is_recurring_hint" @checked($recurring)>
                        {{ __('activities.recurrence.enable') }}
                    </label>
                    <p id="is_recurring_hint" class="text-xs text-gray-600">{{ __('activities.recurrence.enable_hint') }}</p>
                </div>
            @else
                <p class="text-sm text-gray-800">{{ __('activities.recurrence.edit_hint') }}</p>
            @endunless

            <fieldset x-show="isRecurring" class="space-y-4">
                <legend class="text-sm font-semibold text-brand-green">{{ __('activities.recurrence.legend') }}</legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="recurrence_frequency" :value="__('activities.recurrence.frequency')" />
                        <x-select-input id="recurrence_frequency" name="recurrence[frequency]" class="mt-1 block w-full" x-on:change="onFrequency">
                            @foreach ($frequencies as $option)
                                <option value="{{ $option->value }}" @selected($frequency === $option->value)>{{ $option->label() }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('recurrence.frequency')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="recurrence_interval" :value="__('activities.recurrence.interval')" />
                        <x-text-input id="recurrence_interval" name="recurrence[interval]" type="number" min="1" max="{{ \App\Support\RecurrenceRule::MAX_INTERVAL }}" step="1" class="mt-1 block w-full sm:w-32" :value="$interval" aria-describedby="recurrence_interval_hint" />
                        <p id="recurrence_interval_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.recurrence.interval_hint', ['max' => \App\Support\RecurrenceRule::MAX_INTERVAL]) }}</p>
                        <x-input-error :messages="$errors->get('recurrence.interval')" class="mt-2" />
                    </div>
                </div>

                <fieldset x-show="isWeekly" class="space-y-1">
                    <legend class="text-sm font-medium text-gray-800">{{ __('activities.recurrence.days_of_week') }}</legend>
                    <div class="flex flex-wrap gap-x-4">
                        @foreach (range(1, 7) as $day)
                            <label for="recurrence_day_{{ $day }}" class="flex min-h-[44px] items-center gap-2 text-sm text-gray-800">
                                <input id="recurrence_day_{{ $day }}" name="recurrence[days_of_week][]" type="checkbox" value="{{ $day }}" class="h-5 w-5 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" @checked(in_array($day, $selectedDays, true))>
                                {{ __('activities.recurrence.days.'.$day) }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-600">{{ __('activities.recurrence.days_of_week_hint') }}</p>
                    <x-input-error :messages="$errors->get('recurrence.days_of_week')" class="mt-2" />
                    <x-input-error :messages="$errors->get('recurrence.days_of_week.*')" class="mt-2" />
                </fieldset>

                <div x-show="isMonthly">
                    <x-input-label for="recurrence_day_of_month" :value="__('activities.recurrence.day_of_month')" />
                    <x-text-input id="recurrence_day_of_month" name="recurrence[day_of_month]" type="number" min="1" max="31" step="1" class="mt-1 block w-full sm:w-32" :value="$dayOfMonth" aria-describedby="recurrence_day_of_month_hint" />
                    <p id="recurrence_day_of_month_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.recurrence.day_of_month_hint') }}</p>
                    <x-input-error :messages="$errors->get('recurrence.day_of_month')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="recurrence_ends_at" :value="__('activities.recurrence.ends_at')" />
                    <x-text-input id="recurrence_ends_at" name="recurrence[ends_at]" type="date" class="mt-1 block w-full sm:w-56" :value="$endsAt" aria-describedby="recurrence_ends_at_hint" />
                    <p id="recurrence_ends_at_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.recurrence.ends_at_hint') }}</p>
                    <x-input-error :messages="$errors->get('recurrence.ends_at')" class="mt-2" />
                    <x-input-error :messages="$errors->get('recurrence')" class="mt-2" />
                </div>
            </fieldset>
        </div>
    @endif
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>{{ __('common.actions.save') }}</x-primary-button>
    <x-text-link :href="$isEditing ? route('activities.show', $activity) : route('activities.index')">{{ __('common.actions.cancel') }}</x-text-link>
</div>
