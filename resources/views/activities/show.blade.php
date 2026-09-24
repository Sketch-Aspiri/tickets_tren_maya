@php
    /** @var \App\Models\Activity $activity */
    $user = auth()->user();
    $isFinal = $activity->status->isFinal();
    $isTemplate = $activity->isTemplate();
    $responsibleAssignment = $activity->assignments->firstWhere('role', \App\Enums\AssignmentRole::Responsable);
    $collaboratorIds = $activity->assignments->where('role', \App\Enums\AssignmentRole::Colaborador)->pluck('user_id')->all();
    $selectedResponsible = (int) old('responsible_id', $responsibleAssignment?->user_id);
    $selectedCollaborators = array_map('intval', (array) old('collaborator_ids', $collaboratorIds));
    $maxKb = (int) config('tickets.attachments.max_kilobytes');
    $percent = $activity->progressPercent();
@endphp

<x-app-layout>
    <x-slot name="title">{{ $activity->folio }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="font-mono text-xs text-gray-700">{{ $activity->folio }}</p>
                <h1 class="truncate text-xl font-semibold leading-tight text-brand-green">{{ $activity->title }}</h1>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @can('update', $activity)
                    <x-primary-button :href="route('activities.edit', $activity)">{{ __('common.actions.edit') }}</x-primary-button>
                @endcan
                @can('viewAny', \App\Models\Activity::class)
                    <x-text-link :href="route('activities.index')">{{ __('activities.show.back') }}</x-text-link>
                @else
                    <x-text-link :href="route('tickets.pending')">{{ __('common.nav.pending') }}</x-text-link>
                @endcan
            </div>
        </div>
    </x-slot>

    {{-- Datos --}}
    <x-card :title="__('activities.show.details')">
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <x-status-badge :status="$activity->status" />
            <x-priority-badge :priority="$activity->priority" />
            @if ($activity->isOverdue())
                <x-overdue-badge />
            @endif
            <x-activity-kind-badge :activity="$activity" />
        </div>

        @if ($activity->isOverdue())
            <p class="mb-4 rounded-md border-l-4 border-red-600 bg-red-50 p-3 text-sm font-medium text-red-900">{{ __('activities.show.overdue_hint') }}</p>
        @endif

        @if ($isTemplate)
            <p class="mb-4 rounded-md border-l-4 border-sky-600 bg-sky-50 p-3 text-sm text-sky-950">{{ __('activities.show.template_hint') }}</p>
        @endif

        @if ($activity->isInstance() && $activity->occurrence_date)
            <p class="mb-4 text-sm text-gray-800">
                {{ __('activities.show.instance_of', ['date' => $activity->occurrence_date->format('d/m/Y')]) }}
                @if ($activity->parent)
                    @can('view', $activity->parent)
                        · <x-text-link :href="route('activities.show', $activity->parent)">{{ $activity->parent->folio }}</x-text-link>
                    @endcan
                @endif
            </p>
        @endif

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-600">{{ __('tickets.columns.category') }}</dt><dd class="font-medium">{{ $activity->category?->name ?? __('activities.form.no_category') }}</dd></div>
            <div><dt class="text-gray-600">{{ __('activities.columns.team') }}</dt><dd class="font-medium">{{ $activity->team->name }}</dd></div>
            <div><dt class="text-gray-600">{{ __('activities.show.start_date') }}</dt><dd class="font-medium">{{ $activity->start_date?->format('d/m/Y') ?? __('activities.show.no_date') }}</dd></div>
            <div><dt class="text-gray-600">{{ __('activities.columns.due_date') }}</dt><dd class="font-medium">{{ $activity->due_date?->format('d/m/Y') ?? __('activities.show.no_date') }}</dd></div>
            <div><dt class="text-gray-600">{{ __('activities.show.created_by') }}</dt><dd class="font-medium">{{ $activity->creator->name }}</dd></div>
            <div><dt class="text-gray-600">{{ __('activities.show.created_at') }}</dt><dd><x-local-datetime :value="$activity->created_at" /></dd></div>
            @if ($activity->completed_at)
                <div><dt class="text-gray-600">{{ __('activities.show.completed_at') }}</dt><dd><x-local-datetime :value="$activity->completed_at" /></dd></div>
            @endif
        </dl>

        @if ($recurrence)
            <h3 class="mb-1 mt-5 text-sm font-semibold text-gray-800">{{ __('activities.show.recurrence_rule') }}</h3>
            <p class="text-sm text-gray-900">
                {{ __('activities.recurrence.summary.'.$recurrence->frequency->value, ['interval' => $recurrence->interval]) }}
                @if ($recurrence->daysOfWeek !== [])
                    · {{ __('activities.show.recurrence_days', ['days' => collect($recurrence->daysOfWeek)->map(fn (int $day): string => __('activities.recurrence.days.'.$day))->implode(', ')]) }}
                @endif
                @if ($recurrence->dayOfMonth !== null)
                    · {{ __('activities.show.recurrence_day_of_month', ['day' => $recurrence->dayOfMonth]) }}
                @endif
                · {{ $recurrence->endsAt !== null ? __('activities.show.recurrence_ends', ['date' => \Carbon\CarbonImmutable::parse($recurrence->endsAt)->format('d/m/Y')]) : __('activities.show.recurrence_no_end') }}
            </p>
        @endif

        <h3 class="mb-1 mt-5 text-sm font-semibold text-gray-800">{{ __('activities.show.description') }}</h3>
        {{-- Contenido de usuario: siempre escapado; whitespace-pre-line conserva los saltos de linea sin interpretar HTML. --}}
        <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $activity->description }}</p>

        @unless ($isTemplate)
            <h3 class="mb-1 mt-5 text-sm font-semibold text-gray-800">{{ __('activities.show.progress') }}</h3>
            <x-progress-bar :percent="$percent" class="max-w-md" />
            <p class="mt-1 text-xs text-gray-700">{{ __('activities.show.progress_summary', ['done' => $activity->subtasksDoneCount(), 'total' => $activity->subtasksTotalCount()]) }}</p>
        @endunless
    </x-card>

    {{-- Asignados --}}
    <x-card :title="__('activities.assign.title')">
        @if ($activity->assignments->isEmpty())
            <p class="text-sm text-gray-700">{{ __('activities.show.no_assignees') }}</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($activity->assignments as $assignment)
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-gray-900">{{ $assignment->user->name }}</span>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-brand-green ring-1 ring-inset ring-brand-teal/50">{{ $assignment->role->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @can('assign', $activity)
            @if (! $isFinal)
                <form method="POST" action="{{ route('activities.assignments.update', $activity) }}" class="mt-5 grid gap-4 border-t border-brand-green/10 pt-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="responsible_id" :value="__('activities.assign.responsible')" />
                        <x-select-input id="responsible_id" name="responsible_id" class="mt-1 block w-full" required>
                            <option value="">{{ __('activities.assign.select_responsible') }}</option>
                            @foreach ($assignableUsers as $candidate)
                                <option value="{{ $candidate->id }}" @selected($selectedResponsible === $candidate->id)>{{ $candidate->name }}</option>
                            @endforeach
                        </x-select-input>
                        <x-input-error :messages="$errors->get('responsible_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="collaborator_ids" :value="__('activities.assign.collaborators')" />
                        <x-select-input id="collaborator_ids" name="collaborator_ids[]" class="mt-1 block w-full" multiple size="4" aria-describedby="collaborators_hint">
                            @foreach ($assignableUsers as $candidate)
                                <option value="{{ $candidate->id }}" @selected(in_array($candidate->id, $selectedCollaborators, true))>{{ $candidate->name }}</option>
                            @endforeach
                        </x-select-input>
                        <p id="collaborators_hint" class="mt-1 text-xs text-gray-600">{{ __('activities.assign.collaborators_hint') }}</p>
                        <x-input-error :messages="$errors->get('collaborator_ids')" class="mt-2" />
                        <x-input-error :messages="$errors->get('collaborator_ids.*')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-primary-button>{{ __('activities.assign.submit') }}</x-primary-button>
                    </div>
                </form>
            @endif
        @endcan
    </x-card>

    {{-- Subtareas: los controles que se muestran dependen de lo que este usuario puede hacer (calculado una vez en
         el controlador); cada accion se vuelve a autorizar en su ruta y en SubtaskService. --}}
    <x-card :title="__('activities.subtasks.title')" id="subtareas">
        @if ($isTemplate)
            <p class="mb-3 text-sm text-gray-700">{{ __('activities.subtasks.template_hint') }}</p>
        @endif
        @if ($isFinal && $activity->subtasks->isNotEmpty())
            <p class="mb-3 text-sm text-gray-700">{{ __('activities.subtasks.closed_hint') }}</p>
        @endif

        @forelse ($activity->subtasks as $subtask)
            @php
                $canMark = ! $isFinal && ! $isTemplate
                    && ($subtaskAbilities['markAll'] || ($subtaskAbilities['work'] && (int) $subtask->assigned_to === (int) $user->id));
                $canEdit = ! $isFinal && $subtaskAbilities['manage'];
            @endphp
            <div class="border-b border-brand-green/10 py-3 last:border-0">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="break-words font-medium {{ $subtask->done ? 'text-gray-600 line-through' : 'text-gray-900' }}">{{ $subtask->title }}</p>
                        <p class="text-xs text-gray-700">
                            <span class="font-semibold">{{ $subtask->done ? __('activities.subtasks.done') : __('activities.subtasks.pending') }}</span>
                            @if ($subtask->assignee)
                                · {{ __('activities.subtasks.assigned_to', ['name' => $subtask->assignee->name]) }}
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($canMark)
                            <form method="POST" action="{{ route('activities.subtasks.done', [$activity, $subtask]) }}">
                                @csrf
                                <input type="hidden" name="done" value="{{ $subtask->done ? '0' : '1' }}">
                                <x-secondary-button type="submit">{{ $subtask->done ? __('activities.subtasks.mark_undone') : __('activities.subtasks.mark_done') }}</x-secondary-button>
                            </form>
                        @endif
                        @if ($canEdit)
                            <form method="POST" action="{{ route('activities.subtasks.destroy', [$activity, $subtask]) }}" x-data="confirmSubmit" data-confirm="{{ __('activities.subtasks.remove_confirm') }}" x-on:submit="onSubmit">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('activities.subtasks.remove') }}</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($canEdit)
                    <details class="mt-1">
                        <summary class="inline-flex min-h-[44px] cursor-pointer items-center text-sm font-medium text-brand-teal underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('activities.subtasks.edit') }}</summary>
                        <form method="POST" action="{{ route('activities.subtasks.update', [$activity, $subtask]) }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                            @csrf
                            @method('PUT')
                            <div>
                                <x-input-label :for="'subtask_title_'.$subtask->id" :value="__('activities.subtasks.title_label')" />
                                <x-text-input :id="'subtask_title_'.$subtask->id" name="title" type="text" class="mt-1 block w-full" :value="$subtask->title" maxlength="255" required />
                            </div>
                            <div>
                                <x-input-label :for="'subtask_assignee_'.$subtask->id" :value="__('activities.subtasks.assignee')" />
                                <x-select-input :id="'subtask_assignee_'.$subtask->id" name="assigned_to" class="mt-1 block w-full">
                                    <option value="">{{ __('activities.subtasks.no_assignee') }}</option>
                                    @foreach ($assignableUsers as $candidate)
                                        <option value="{{ $candidate->id }}" @selected((int) $subtask->assigned_to === $candidate->id)>{{ $candidate->name }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                            <div class="sm:col-span-2">
                                <x-primary-button>{{ __('activities.subtasks.save') }}</x-primary-button>
                            </div>
                        </form>
                    </details>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-700">{{ __('activities.show.no_subtasks') }}</p>
        @endforelse

        @if (! $isFinal && $subtaskAbilities['manage'])
            <form method="POST" action="{{ route('activities.subtasks.store', $activity) }}" class="mt-4 grid gap-3 border-t border-brand-green/10 pt-4 sm:grid-cols-2">
                @csrf
                <div>
                    <x-input-label for="new_subtask_title" :value="__('activities.subtasks.add')" />
                    <x-text-input id="new_subtask_title" name="title" type="text" class="mt-1 block w-full" :value="old('title')" maxlength="255" required />
                    <x-input-error :messages="$errors->get('title')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="new_subtask_assignee" :value="__('activities.subtasks.assignee')" />
                    <x-select-input id="new_subtask_assignee" name="assigned_to" class="mt-1 block w-full">
                        <option value="">{{ __('activities.subtasks.no_assignee') }}</option>
                        @foreach ($assignableUsers as $candidate)
                            <option value="{{ $candidate->id }}" @selected((int) old('assigned_to') === $candidate->id)>{{ $candidate->name }}</option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('assigned_to')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-primary-button>{{ __('activities.subtasks.submit') }}</x-primary-button>
                </div>
            </form>
        @endif
    </x-card>

    {{-- Acciones de estado: solo las que este usuario puede ejecutar (la autorizacion real esta en Policy + ActivityService). --}}
    @if (count($transitions) > 0)
        <x-card :title="__('activities.actions.title')">
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($transitions as $target)
                    @php
                        $needsComment = $activity->status->requiresCommentWhenMovingTo($target);
                        $formId = 'transition_'.$target->value;
                        $actionLabel = __('activities.actions.transition.'.$target->actionLabelKeyFrom($activity->status));
                    @endphp
                    <form method="POST" action="{{ route('activities.transition', $activity) }}" class="rounded-md border border-brand-green/10 p-3">
                        @csrf
                        <input type="hidden" name="status" value="{{ $target->value }}">
                        <x-input-label :for="$formId.'_comment'" :value="__('activities.actions.comment_label')" />
                        <textarea id="{{ $formId }}_comment" name="comment" rows="2" maxlength="{{ config('tickets.comment_max_length') }}" @required($needsComment) aria-describedby="{{ $formId }}_hint" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal"></textarea>
                        <p id="{{ $formId }}_hint" class="mb-3 mt-1 text-xs text-gray-600">{{ $needsComment ? __('activities.actions.comment_required_hint') : __('activities.actions.comment_optional_hint') }}</p>
                        @if ($target === \App\Enums\TicketStatus::Cancelled || ($activity->status === \App\Enums\TicketStatus::InReview && $target === \App\Enums\TicketStatus::InProgress))
                            <x-danger-button>{{ $actionLabel }}</x-danger-button>
                        @else
                            <x-primary-button>{{ $actionLabel }}</x-primary-button>
                        @endif
                    </form>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-history-panel :item="$activity" :created-label="__('activities.show.history_created')" />

    <x-comments-panel :item="$activity" :store-url="route('activities.comments.store', $activity)" />

    <x-attachments-panel :item="$activity" :store-url="route('activities.attachments.store', $activity)" :hint="__('activities.attachment.hint', ['max' => intdiv($maxKb, 1024), 'count' => config('tickets.attachments.max_per_ticket')])" />

    @can('delete', $activity)
        <x-card>
            <form method="POST" action="{{ route('activities.destroy', $activity) }}" x-data="confirmSubmit" data-confirm="{{ __('activities.show.delete_confirm') }}" x-on:submit="onSubmit">
                @csrf
                @method('DELETE')
                <x-danger-button>{{ __('activities.actions.delete') }}</x-danger-button>
            </form>
        </x-card>
    @endcan
</x-app-layout>
