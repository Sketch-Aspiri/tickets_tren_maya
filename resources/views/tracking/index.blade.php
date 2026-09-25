@php
    $cards = $report['cards'];
    $closing = $report['closing'];
    $workload = $report['workload'];
    $maxOpen = max(1, (int) collect($workload['rows'])->max('open'));
    $trendCaption = $report['trend']['granularity'] === 'week' ? __('tracking.charts.trend_caption_week') : __('tracking.charts.trend_caption_day');
    $scopeText = match (true) {
        $report['scope']['team_id'] !== null => __('tracking.scope.team', ['team' => $report['scope']['team_name'] ?? '#'.$report['scope']['team_id']]),
        $report['scope']['team_names'] !== [] => __('tracking.scope.teams', ['teams' => implode(', ', $report['scope']['team_names'])]),
        default => __('tracking.scope.global'),
    };
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('tracking.title') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('tracking.title') }}</h1>
                <p class="text-sm text-gray-700">{{ $scopeText }}</p>
            </div>
        </div>
    </x-slot>

    <x-card>
        <form method="GET" action="{{ route('tracking.index') }}" aria-label="{{ __('tracking.filters.legend') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-1">
                <x-input-label for="from" :value="__('tracking.filters.from')" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters->from" max="{{ \App\Support\LocalTime::today() }}" />
                <x-input-error :messages="$errors->get('from')" class="mt-1" />
            </div>

            <div class="lg:col-span-1">
                <x-input-label for="to" :value="__('tracking.filters.to')" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters->to" max="{{ \App\Support\LocalTime::today() }}" />
                <x-input-error :messages="$errors->get('to')" class="mt-1" />
            </div>

            @if ($teams->isNotEmpty())
                <div class="lg:col-span-1">
                    <x-input-label for="team_id" :value="__('tracking.filters.team')" />
                    <x-select-input id="team_id" name="team_id" class="mt-1 block w-full">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}" @selected($filters->teamId === $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('team_id')" class="mt-1" />
                </div>
            @endif

            @if ($employees->isNotEmpty())
                <div class="lg:col-span-1">
                    <x-input-label for="user_id" :value="__('tracking.filters.employee')" />
                    <x-select-input id="user_id" name="user_id" class="mt-1 block w-full">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected($filters->userId === $employee->id)>{{ $employee->name }}</option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('user_id')" class="mt-1" />
                </div>
            @endif

            <div class="lg:col-span-1">
                <x-input-label for="category_id" :value="__('tracking.filters.category')" />
                <x-select-input id="category_id" name="category_id" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters->categoryId === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('category_id')" class="mt-1" />
            </div>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-1">
                <x-primary-button>{{ __('tracking.filters.apply') }}</x-primary-button>
                <x-text-link :href="route('tracking.index')">{{ __('common.actions.clear') }}</x-text-link>
            </div>
        </form>
    </x-card>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-kpi-card :label="__('tracking.cards.open')" :value="$cards['open']['total']" :tickets="$cards['open']['tickets']" :activities="$cards['open']['activities']" :note="__('tracking.cards.snapshot_note')" />
        <x-kpi-card :label="__('tracking.cards.in_review')" :value="$cards['in_review']['total']" :tickets="$cards['in_review']['tickets']" :activities="$cards['in_review']['activities']" :note="__('tracking.cards.snapshot_note')" />
        <x-kpi-card :label="__('tracking.cards.overdue')" :value="$cards['overdue']['total']" :tickets="$cards['overdue']['tickets']" :activities="$cards['overdue']['activities']" :note="__('tracking.cards.snapshot_note')" tone="danger" />
        <x-kpi-card :label="__('tracking.cards.completed')" :value="$cards['completed']['total']" :tickets="$cards['completed']['tickets']" :activities="$cards['completed']['activities']" :note="__('tracking.cards.period_note')" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="lg:col-span-2">
            <x-chart-card :title="__('tracking.charts.trend')" :caption="$trendCaption" :config="$charts['trend']" />
        </div>
        <x-chart-card :title="__('tracking.charts.status')" :caption="__('tracking.charts.status_caption')" :config="$charts['status']" />
        <x-chart-card :title="__('tracking.charts.priority')" :caption="__('tracking.charts.priority_caption')" :config="$charts['priority']" />
    </div>

    <x-card :title="__('tracking.sections.workload')">
        <p class="mb-3 text-sm text-gray-700">{{ __('tracking.workload.note') }}</p>

        @if ($workload['rows'] === [])
            <p class="text-sm text-gray-700">{{ __('tracking.workload.empty') }}</p>
        @else
            <x-table>
                <thead>
                    <tr>
                        <th scope="col" class="px-3 py-2">{{ __('tracking.workload.person') }}</th>
                        <th scope="col" class="hidden px-3 py-2 text-right sm:table-cell">{{ __('tracking.workload.tickets') }}</th>
                        <th scope="col" class="hidden px-3 py-2 text-right sm:table-cell">{{ __('tracking.workload.activities') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.workload.open') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.workload.in_review') }}</th>
                        <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.workload.overdue') }}</th>
                        <th scope="col" class="hidden px-3 py-2 md:table-cell">{{ __('tracking.workload.load') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-green/10">
                    @foreach ($workload['rows'] as $row)
                        <tr>
                            <th scope="row" class="px-3 py-2 text-left font-medium">{{ $row['name'] }}</th>
                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ $row['tickets_open'] }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ $row['activities_open'] }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $row['open'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['in_review'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $row['overdue'] > 0 ? 'font-semibold text-red-700' : '' }}">{{ $row['overdue'] }}</td>
                            <td class="hidden px-3 py-2 md:table-cell">
                                <x-progress-bar :percent="intdiv($row['open'] * 100, $maxOpen)" :label="__('tracking.workload.load').': '.$row['name']" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>

            @if ($workload['total_people'] > count($workload['rows']))
                <p class="mt-2 text-xs text-gray-600">{{ __('tracking.workload.truncated', ['shown' => count($workload['rows']), 'total' => $workload['total_people']]) }}</p>
            @endif
        @endif
    </x-card>

    <x-card :title="__('tracking.sections.closing')">
        <p class="mb-3 text-sm text-gray-700">{{ __('tracking.closing.note') }}</p>

        @if ($closing['closed'] === 0)
            <p class="text-sm text-gray-700">{{ __('tracking.closing.empty') }}</p>
        @else
            <p class="mb-4 text-sm text-gray-800">
                {{ __('tracking.closing.overall') }}:
                <strong class="text-lg text-brand-green"><x-duration :seconds="$closing['average_seconds']" /></strong>
                ({{ __('tracking.closing.closed') }}: {{ $closing['closed'] }})
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-semibold text-gray-800">{{ __('tracking.closing.by_category') }}</h3>
                    <x-table>
                        <thead>
                            <tr>
                                <th scope="col" class="px-3 py-2">{{ __('tracking.closing.category') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.closing.closed') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.closing.average') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-green/10">
                            @foreach ($closing['by_category'] as $row)
                                <tr>
                                    <th scope="row" class="px-3 py-2 text-left font-medium">{{ $row['name'] ?? __('tracking.closing.no_category') }}</th>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['closed'] }}</td>
                                    <td class="px-3 py-2 text-right"><x-duration :seconds="$row['average_seconds']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-gray-800">{{ __('tracking.closing.by_team') }}</h3>
                    <x-table>
                        <thead>
                            <tr>
                                <th scope="col" class="px-3 py-2">{{ __('tracking.closing.team') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.closing.closed') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ __('tracking.closing.average') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-green/10">
                            @foreach ($closing['by_team'] as $row)
                                <tr>
                                    <th scope="row" class="px-3 py-2 text-left font-medium">{{ $row['name'] }}</th>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['closed'] }}</td>
                                    <td class="px-3 py-2 text-right"><x-duration :seconds="$row['average_seconds']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
                </div>
            </div>
        @endif
    </x-card>

    <p class="text-xs text-gray-600">
        {{ __('tracking.generated_at', ['when' => \Illuminate\Support\Carbon::parse($report['generated_at'])->timezone(config('app.display_timezone'))->format('d/m/Y H:i')]) }}
    </p>
</x-app-layout>
