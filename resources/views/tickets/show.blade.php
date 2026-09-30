@php
    /** @var \App\Models\Ticket $ticket */
    $responsibleAssignment = $ticket->assignments->firstWhere('role', \App\Enums\AssignmentRole::Responsable);
    $collaboratorIds = $ticket->assignments->where('role', \App\Enums\AssignmentRole::Colaborador)->pluck('user_id')->all();
    $selectedResponsible = (int) old('responsible_id', $responsibleAssignment?->user_id);
    $selectedCollaborators = array_map('intval', (array) old('collaborator_ids', $collaboratorIds));
    $maxKb = (int) config('tickets.attachments.max_kilobytes');
@endphp

<x-app-layout>
    <x-slot name="title">{{ $ticket->folio }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="font-mono text-xs text-gray-700">{{ $ticket->folio }}</p>
                <h1 class="truncate text-xl font-semibold leading-tight text-brand-green">{{ $ticket->title }}</h1>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @can('update', $ticket)
                    <x-primary-button :href="route('tickets.edit', $ticket)">{{ __('common.actions.edit') }}</x-primary-button>
                @endcan
                <x-text-link :href="route('tickets.index')">{{ __('tickets.show.back') }}</x-text-link>
            </div>
        </div>
    </x-slot>

    {{-- Estado y siguiente paso: flujo, accion principal (incluye "Tomar ticket" si esta en la bolsa) y acciones
         secundarias segun Policy (la autorizacion real esta en Policy + TicketService). --}}
    <x-card :title="__('workflow.title')" id="estado">
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <x-status-badge :status="$ticket->status" />
            <x-priority-badge :priority="$ticket->priority" />
            @if ($ticket->isOverdue())
                <x-overdue-badge />
            @endif
            @if ($ticket->assignments->isEmpty() && ! $ticket->status->isFinal())
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900">{{ __('tickets.show.bag') }}</span>
            @endif
        </div>

        @if ($ticket->isOverdue())
            <p class="mb-4 rounded-md border-l-4 border-red-600 bg-red-50 p-3 text-sm font-medium text-red-900">{{ __('tickets.show.overdue_hint') }}</p>
        @endif

        <x-workflow-stepper :status="$ticket->status" :rejection="$ticket->latestRejection()" class="mb-5" />

        <x-workflow-actions :item="$ticket" :transitions="$transitions" />
    </x-card>

    {{-- Datos --}}
    <x-card :title="__('tickets.show.details')">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-600">{{ __('tickets.columns.category') }}</dt><dd class="font-medium">{{ $ticket->category?->name ?? __('tickets.form.no_category') }}</dd></div>
            <div><dt class="text-gray-600">{{ __('tickets.columns.team') }}</dt><dd class="font-medium">{{ $ticket->team->name }}</dd></div>
            <div><dt class="text-gray-600">{{ __('tickets.columns.due_date') }}</dt><dd class="font-medium">{{ $ticket->due_date?->format('d/m/Y') ?? __('tickets.show.no_due_date') }}</dd></div>
            <div><dt class="text-gray-600">{{ __('tickets.show.created_by') }}</dt><dd class="font-medium">{{ $ticket->creator->name }}</dd></div>
            <div><dt class="text-gray-600">{{ __('tickets.show.created_at') }}</dt><dd><x-local-datetime :value="$ticket->created_at" /></dd></div>
            <div><dt class="text-gray-600">{{ __('tickets.show.source') }}</dt><dd class="font-medium">{{ $ticket->source->label() }}</dd></div>
            @if ($ticket->completed_at)
                <div><dt class="text-gray-600">{{ __('tickets.show.completed_at') }}</dt><dd><x-local-datetime :value="$ticket->completed_at" /></dd></div>
            @endif
        </dl>

        <h3 class="mb-1 mt-5 text-sm font-semibold text-gray-800">{{ __('tickets.show.description') }}</h3>
        {{-- Contenido de usuario: siempre escapado; whitespace-pre-line conserva los saltos de linea sin interpretar HTML. --}}
        <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $ticket->description }}</p>
    </x-card>

    {{-- Asignados --}}
    <x-card :title="__('tickets.assign.title')">
        @if ($ticket->assignments->isEmpty())
            <p class="text-sm text-gray-700">{{ __('tickets.show.bag') }}</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($ticket->assignments as $assignment)
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-gray-900">{{ $assignment->user->name }}</span>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-brand-green ring-1 ring-inset ring-brand-teal/50">{{ $assignment->role->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @can('assign', $ticket)
            @if (! $ticket->status->isFinal())
                <form method="POST" action="{{ route('tickets.assignments.update', $ticket) }}" class="mt-5 grid gap-4 border-t border-brand-green/10 pt-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <x-assignee-picker :users="$assignableUsers" :selected-responsible="$selectedResponsible" :selected-collaborators="$selectedCollaborators" :responsible-label="__('tickets.assign.responsible')" :select-responsible="__('tickets.assign.select_responsible')" :collaborators-label="__('tickets.assign.collaborators')" id-prefix="assign" />
                    <div class="sm:col-span-2">
                        <x-primary-button>{{ __('tickets.assign.submit') }}</x-primary-button>
                    </div>
                </form>

                @if ($ticket->assignments->isNotEmpty())
                    <form method="POST" action="{{ route('tickets.assignments.destroy', $ticket) }}" class="mt-2" x-data="confirmSubmit" data-confirm="{{ __('tickets.assign.release_confirm') }}" x-on:submit="onSubmit">
                        @csrf
                        @method('DELETE')
                        <x-text-link type="submit">{{ __('tickets.assign.release') }}</x-text-link>
                    </form>
                @endif
            @endif
        @endcan
    </x-card>

    <x-history-panel :item="$ticket" :created-label="__('tickets.show.history_created')" />

    <x-comments-panel :item="$ticket" :store-url="route('tickets.comments.store', $ticket)" />

    <x-attachments-panel :item="$ticket" :store-url="route('tickets.attachments.store', $ticket)" :hint="__('tickets.attachment.hint', ['max' => intdiv($maxKb, 1024), 'count' => config('tickets.attachments.max_per_ticket')])" />

    @can('delete', $ticket)
        <x-card>
            <form method="POST" action="{{ route('tickets.destroy', $ticket) }}" x-data="confirmSubmit" data-confirm="{{ __('tickets.show.delete_confirm') }}" x-on:submit="onSubmit">
                @csrf
                @method('DELETE')
                <x-danger-button>{{ __('tickets.actions.delete') }}</x-danger-button>
            </form>
        </x-card>
    @endcan
</x-app-layout>
