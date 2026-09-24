@props(['activities', 'showTeam' => false, 'emptyMessage' => null])

{{-- Tabla de actividades compartida por el listado y "Mis pendientes". Las actividades ya vienen filtradas por
     alcance y con sus relaciones y conteos de avance cargados (category, team, parent, assignments.user,
     subtasks_count, subtasks_done_count): sin consultas por fila. --}}
<x-table>
    <thead>
        <tr>
            <th class="px-4 py-3">{{ __('activities.columns.folio') }}</th>
            <th class="px-4 py-3">{{ __('activities.columns.title') }}</th>
            <th class="px-4 py-3">{{ __('activities.columns.status') }}</th>
            <th class="hidden px-4 py-3 md:table-cell">{{ __('activities.columns.priority') }}</th>
            <th class="hidden px-4 py-3 lg:table-cell">{{ __('activities.columns.responsible') }}</th>
            @if ($showTeam)
                <th class="hidden px-4 py-3 xl:table-cell">{{ __('activities.columns.team') }}</th>
            @endif
            <th class="hidden px-4 py-3 md:table-cell">{{ __('activities.columns.due_date') }}</th>
            <th class="hidden px-4 py-3 sm:table-cell">{{ __('activities.columns.progress') }}</th>
            <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.view') }}</span></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-brand-green/10">
        @forelse ($activities as $activity)
            @php
                $responsible = $activity->assignments->firstWhere('role', \App\Enums\AssignmentRole::Responsable);
            @endphp
            <tr>
                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-700">{{ $activity->folio }}</td>
                <td class="px-4 py-3">
                    <a href="{{ route('activities.show', $activity) }}" class="inline-flex min-h-[44px] max-w-[11rem] items-center font-medium text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal sm:max-w-md">
                        <span class="line-clamp-2">{{ $activity->title }}</span>
                    </a>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <x-activity-kind-badge :activity="$activity" />
                        {{-- En pantallas angostas la prioridad, el vencimiento y el avance van bajo el titulo. --}}
                        <x-priority-badge :priority="$activity->priority" class="md:hidden" />
                        @if ($activity->isOverdue())
                            <x-overdue-badge class="md:hidden" />
                        @endif
                    </div>
                    @unless ($activity->isTemplate())
                        <x-progress-bar :percent="$activity->progressPercent()" class="mt-1 max-w-[12rem] sm:hidden" />
                    @endunless
                </td>
                <td class="px-4 py-3"><x-status-badge :status="$activity->status" /></td>
                <td class="hidden px-4 py-3 md:table-cell"><x-priority-badge :priority="$activity->priority" /></td>
                <td class="hidden px-4 py-3 lg:table-cell {{ $responsible ? '' : 'italic text-gray-700' }}">{{ $responsible?->user->name ?? __('activities.show.no_assignees') }}</td>
                @if ($showTeam)
                    <td class="hidden px-4 py-3 xl:table-cell">{{ $activity->team->name }}</td>
                @endif
                <td class="hidden whitespace-nowrap px-4 py-3 md:table-cell">
                    {{ $activity->due_date?->format('d/m/Y') ?? __('common.none') }}
                    @if ($activity->isOverdue())
                        <x-overdue-badge class="ms-1" />
                    @endif
                </td>
                <td class="hidden px-4 py-3 sm:table-cell">
                    @if ($activity->isTemplate())
                        {{ __('common.none') }}
                    @else
                        <x-progress-bar :percent="$activity->progressPercent()" class="w-36" />
                    @endif
                </td>
                <td class="px-2 py-3 text-right sm:px-4">
                    <a href="{{ route('activities.show', $activity) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.view') }}</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ $showTeam ? 9 : 8 }}" class="px-4 py-6 text-center text-gray-600">{{ $emptyMessage ?? __('common.empty') }}</td></tr>
        @endforelse
    </tbody>
</x-table>
