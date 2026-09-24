@csrf

@php
    $isEditing = $ticket->exists;
    $selectedPriority = old('priority', $ticket->priority?->value);
    $selectedCategory = old('category_id', $ticket->category_id);
    $dueDate = old('due_date', $ticket->due_date?->toDateString());
@endphp

<div class="space-y-4">
    @if ($teams->isNotEmpty())
        <div>
            <x-input-label for="team_id" :value="__('tickets.form.team')" />
            <x-select-input id="team_id" name="team_id" class="mt-1 block w-full" required aria-describedby="team_id_hint">
                <option value="">{{ __('tickets.form.select_team') }}</option>
                @foreach ($teams as $team)
                    <option value="{{ $team->id }}" @selected((int) old('team_id') === $team->id)>{{ $team->name }}</option>
                @endforeach
            </x-select-input>
            <p id="team_id_hint" class="mt-1 text-xs text-gray-600">{{ __('tickets.form.team_hint') }}</p>
            <x-input-error :messages="$errors->get('team_id')" class="mt-2" />
        </div>
    @endif

    <div>
        <x-input-label for="title" :value="__('tickets.form.title')" />
        <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $ticket->title)" maxlength="255" required autofocus />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="description" :value="__('tickets.form.description')" />
        <textarea id="description" name="description" rows="6" maxlength="5000" required class="mt-1 block min-h-[44px] w-full rounded-md border-gray-500 shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">{{ old('description', $ticket->description) }}</textarea>
        <x-input-error :messages="$errors->get('description')" class="mt-2" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="priority" :value="__('tickets.form.priority')" />
            <x-select-input id="priority" name="priority" class="mt-1 block w-full" required>
                @foreach ($priorities as $priority)
                    <option value="{{ $priority->value }}" @selected($selectedPriority === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('priority')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="category_id" :value="__('tickets.form.category')" />
            <x-select-input id="category_id" name="category_id" class="mt-1 block w-full">
                <option value="">{{ __('tickets.form.no_category') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) $selectedCategory === $category->id)>{{ $category->name }}{{ $category->active ? '' : ' '.__('tickets.form.inactive_category') }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
        </div>
    </div>

    <div>
        <x-input-label for="due_date" :value="__('tickets.form.due_date')" />
        <x-text-input id="due_date" name="due_date" type="date" class="mt-1 block w-full sm:w-56" :value="$dueDate" aria-describedby="due_date_hint" />
        <p id="due_date_hint" class="mt-1 text-xs text-gray-600">{{ __('tickets.form.due_date_hint') }}</p>
        <x-input-error :messages="$errors->get('due_date')" class="mt-2" />
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>{{ __('common.actions.save') }}</x-primary-button>
    <x-text-link :href="$isEditing ? route('tickets.show', $ticket) : route('tickets.index')">{{ __('common.actions.cancel') }}</x-text-link>
</div>
