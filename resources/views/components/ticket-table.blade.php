@props(['tickets', 'showTeam' => false, 'emptyMessage' => null])

{{-- Tabla de tickets compartida por el listado y "Mis pendientes". Los tickets ya vienen filtrados por alcance
     y con sus relaciones cargadas (category, team, assignments.user): sin consultas por fila. --}}
<x-table>
    <thead>
        <tr>
            <th class="px-4 py-3">{{ __('tickets.columns.folio') }}</th>
            <th class="px-4 py-3">{{ __('tickets.columns.title') }}</th>
            <th class="px-4 py-3">{{ __('tickets.columns.status') }}</th>
            <th class="hidden px-4 py-3 md:table-cell">{{ __('tickets.columns.priority') }}</th>
            <th class="hidden px-4 py-3 lg:table-cell">{{ __('tickets.columns.responsible') }}</th>
            @if ($showTeam)
                <th class="hidden px-4 py-3 xl:table-cell">{{ __('tickets.columns.team') }}</th>
            @endif
            <th class="hidden px-4 py-3 md:table-cell">{{ __('tickets.columns.due_date') }}</th>
            <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.view') }}</span></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-brand-green/10">
        @forelse ($tickets as $ticket)
            @php
                $responsible = $ticket->assignments->firstWhere('role', \App\Enums\AssignmentRole::Responsable);
            @endphp
            <tr class="{{ $ticket->isOverdue() ? 'bg-red-50/60' : '' }}">
                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-700 {{ $ticket->isOverdue() ? 'border-l-4 border-red-600' : 'border-l-4 border-transparent' }}">{{ $ticket->folio }}</td>
                <td class="px-4 py-3">
                    <a href="{{ route('tickets.show', $ticket) }}" class="inline-flex min-h-[44px] max-w-[11rem] items-center font-medium text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal sm:max-w-md">
                        <span class="line-clamp-2">{{ $ticket->title }}</span>
                    </a>
                    {{-- En pantallas angostas la prioridad y el vencimiento van bajo el titulo. --}}
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        @if (! $responsible && ! $ticket->status->isFinal())
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">{{ __('tickets.show.bag') }}</span>
                        @endif
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 md:hidden">
                        <x-priority-badge :priority="$ticket->priority" />
                        @if ($ticket->isOverdue())
                            <x-overdue-badge />
                        @endif
                    </div>
                </td>
                <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
                <td class="hidden px-4 py-3 md:table-cell"><x-priority-badge :priority="$ticket->priority" /></td>
                <td class="hidden px-4 py-3 lg:table-cell {{ $responsible ? '' : 'italic text-gray-700' }}">{{ $responsible?->user->name ?? __('tickets.show.bag') }}</td>
                @if ($showTeam)
                    <td class="hidden px-4 py-3 xl:table-cell">{{ $ticket->team->name }}</td>
                @endif
                <td class="hidden whitespace-nowrap px-4 py-3 md:table-cell {{ $ticket->isOverdue() ? 'font-semibold text-red-800' : '' }}">
                    {{ $ticket->due_date?->format('d/m/Y') ?? __('common.none') }}
                    @if ($ticket->isOverdue())
                        <x-overdue-badge class="ms-1" />
                    @endif
                </td>
                <td class="px-2 py-3 text-right sm:px-4">
                    <a href="{{ route('tickets.show', $ticket) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.view') }}</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ $showTeam ? 8 : 7 }}" class="p-0"><x-empty-state :title="$emptyMessage ?? __('common.empty')" /></td></tr>
        @endforelse
    </tbody>
</x-table>
