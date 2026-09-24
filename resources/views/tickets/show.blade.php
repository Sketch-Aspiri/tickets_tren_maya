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
        <div class="flex items-center justify-between gap-4">
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

    {{-- Datos --}}
    <x-card :title="__('tickets.show.details')">
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <x-status-badge :status="$ticket->status" />
            <x-priority-badge :priority="$ticket->priority" />
            @if ($ticket->isOverdue())
                <x-overdue-badge />
            @endif
        </div>

        @if ($ticket->isOverdue())
            <p class="mb-4 rounded-md border-l-4 border-red-600 bg-red-50 p-3 text-sm font-medium text-red-900">{{ __('tickets.show.overdue_hint') }}</p>
        @endif

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

        @can('take', $ticket)
            @if ($ticket->assignments->isEmpty() && ! $ticket->status->isFinal())
                <form method="POST" action="{{ route('tickets.take', $ticket) }}" class="mt-4">
                    @csrf
                    <p class="mb-2 text-sm text-gray-700">{{ __('tickets.actions.take_hint') }}</p>
                    <x-primary-button>{{ __('tickets.actions.take') }}</x-primary-button>
                </form>
            @endif
        @endcan

        @can('assign', $ticket)
            @if (! $ticket->status->isFinal())
                <form method="POST" action="{{ route('tickets.assignments.update', $ticket) }}" class="mt-5 grid gap-4 border-t border-brand-green/10 pt-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="responsible_id" :value="__('tickets.assign.responsible')" />
                        <x-select-input id="responsible_id" name="responsible_id" class="mt-1 block w-full" required>
                            <option value="">{{ __('tickets.assign.select_responsible') }}</option>
                            @foreach ($assignableUsers as $candidate)
                                <option value="{{ $candidate->id }}" @selected($selectedResponsible === $candidate->id)>{{ $candidate->name }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('responsible_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="collaborator_ids" :value="__('tickets.assign.collaborators')" />
                        <x-select-input id="collaborator_ids" name="collaborator_ids[]" class="mt-1 block w-full" multiple size="4" aria-describedby="collaborators_hint">
                            @foreach ($assignableUsers as $candidate)
                                <option value="{{ $candidate->id }}" @selected(in_array($candidate->id, $selectedCollaborators, true))>{{ $candidate->name }}</option>
                            @endforeach
                        </x-select-input>
                        <p id="collaborators_hint" class="mt-1 text-xs text-gray-600">{{ __('tickets.assign.collaborators_hint') }}</p>
                        <x-input-error :messages="$errors->get('collaborator_ids')" class="mt-2" />
                        <x-input-error :messages="$errors->get('collaborator_ids.*')" class="mt-2" />
                    </div>
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

    {{-- Acciones de estado: solo las que este usuario puede ejecutar (la autorizacion real esta en Policy + TicketService). --}}
    @if (count($transitions) > 0)
        <x-card :title="__('tickets.actions.title')">
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($transitions as $target)
                    @php
                        $needsComment = $ticket->status->requiresCommentWhenMovingTo($target);
                        $formId = 'transition_'.$target->value;
                        $actionLabel = __('tickets.actions.transition.'.$target->actionLabelKeyFrom($ticket->status));
                    @endphp
                    <form method="POST" action="{{ route('tickets.transition', $ticket) }}" class="rounded-md border border-brand-green/10 p-3">
                        @csrf
                        <input type="hidden" name="status" value="{{ $target->value }}">
                        <x-input-label :for="$formId.'_comment'" :value="__('tickets.actions.comment_label')" />
                        <textarea id="{{ $formId }}_comment" name="comment" rows="2" maxlength="{{ config('tickets.comment_max_length') }}" @required($needsComment) aria-describedby="{{ $formId }}_hint" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal"></textarea>
                        <p id="{{ $formId }}_hint" class="mb-3 mt-1 text-xs text-gray-600">{{ $needsComment ? __('tickets.actions.comment_required_hint') : __('tickets.actions.comment_optional_hint') }}</p>
                        @if ($target === \App\Enums\TicketStatus::Cancelled || ($ticket->status === \App\Enums\TicketStatus::InReview && $target === \App\Enums\TicketStatus::InProgress))
                            <x-danger-button>{{ $actionLabel }}</x-danger-button>
                        @else
                            <x-primary-button>{{ $actionLabel }}</x-primary-button>
                        @endif
                    </form>
                @endforeach
            </div>
        </x-card>
    @endif

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
