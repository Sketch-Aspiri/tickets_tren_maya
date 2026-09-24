<x-app-layout>
    <x-slot name="title">{{ __('activities.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('activities.title') }}</h1>
            @can('create', \App\Models\Activity::class)
                <x-primary-button :href="route('activities.create')" class="shrink-0">{{ __('activities.new') }}</x-primary-button>
            @endcan
        </div>
    </x-slot>

    <x-card>
        <form method="GET" action="{{ route('activities.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <x-input-label for="q" :value="__('activities.filters.search')" />
                <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q'] ?? ''" maxlength="100" />
            </div>

            <div>
                <x-input-label for="status" :value="__('activities.filters.status')" />
                <x-select-input id="status" name="status" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="priority" :value="__('activities.filters.priority')" />
                <x-select-input id="priority" name="priority" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority->value }}" @selected(($filters['priority'] ?? null) === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="category_id" :value="__('activities.filters.category')" />
                <x-select-input id="category_id" name="category_id" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) ($filters['category_id'] ?? 0) === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </x-select-input>
            </div>

            @if ($teams->isNotEmpty())
                <div>
                    <x-input-label for="team_id" :value="__('activities.filters.team')" />
                    <x-select-input id="team_id" name="team_id" class="mt-1 block w-full">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}" @selected((int) ($filters['team_id'] ?? 0) === $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            @endif

            @if ($responsibles->isNotEmpty())
                <div>
                    <x-input-label for="responsible_id" :value="__('activities.filters.responsible')" />
                    <x-select-input id="responsible_id" name="responsible_id" class="mt-1 block w-full">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($responsibles as $responsible)
                            <option value="{{ $responsible->id }}" @selected((int) ($filters['responsible_id'] ?? 0) === $responsible->id)>{{ $responsible->name }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            @endif

            <div>
                <x-input-label for="kind" :value="__('activities.filters.kind')" />
                <x-select-input id="kind" name="kind" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind }}" @selected(($filters['kind'] ?? null) === $kind)>{{ __('activities.filters.kinds.'.$kind) }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="sort" :value="__('activities.filters.sort')" />
                <x-select-input id="sort" name="sort" class="mt-1 block w-full">
                    @foreach (\App\Services\ActivityListingService::SORTABLE as $column)
                        <option value="{{ $column }}" @selected(($filters['sort'] ?? 'created_at') === $column)>{{ __('activities.sort.'.$column) }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="direction" :value="__('activities.filters.direction')" />
                <x-select-input id="direction" name="direction" class="mt-1 block w-full">
                    <option value="desc" @selected(($filters['direction'] ?? 'desc') === 'desc')>{{ __('activities.filters.desc') }}</option>
                    <option value="asc" @selected(($filters['direction'] ?? null) === 'asc')>{{ __('activities.filters.asc') }}</option>
                </x-select-input>
            </div>

            <div class="flex flex-col justify-end">
                <label for="overdue" class="flex min-h-[44px] items-center gap-3 text-sm text-gray-800">
                    <input id="overdue" name="overdue" type="checkbox" value="1" class="h-5 w-5 rounded border-gray-500 text-brand-green focus:ring-2 focus:ring-brand-teal" @checked($filters['overdue'] ?? false)>
                    {{ __('activities.filters.overdue') }}
                </label>
            </div>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-primary-button>{{ __('common.actions.filter') }}</x-primary-button>
                <x-text-link :href="route('activities.index')">{{ __('common.actions.clear') }}</x-text-link>
            </div>
        </form>
    </x-card>

    <x-activity-table :activities="$activities" :show-team="auth()->user()->team_id === null" />

    {{ $activities->links() }}
</x-app-layout>
