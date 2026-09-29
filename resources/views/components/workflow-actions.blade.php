@props(['item', 'transitions'])

@php
    use App\Enums\TicketStatus;
    use App\Models\Activity;
    use App\Models\Ticket;
    use App\Support\WorkflowSubject;

    /** @var Ticket|Activity $item */
    /** @var list<TicketStatus> $transitions  Ya filtradas por Policy en el controlador: solo lo que este usuario puede ejecutar. */
    $group = WorkflowSubject::group($item);
    $routePrefix = $group;
    $isTicket = $item instanceof Ticket;
    $status = $item->status;
    $maxComment = config('tickets.comment_max_length');
    $forward = null;
    $reject = null;
    $cancel = null;
    $reopen = null;

    foreach ($transitions as $target) {
        if ($target === TicketStatus::Cancelled) {
            $cancel = $target;
        } elseif ($status->isFinal()) {
            $reopen = $target;
        } elseif ($status === TicketStatus::InReview && $target === TicketStatus::InProgress) {
            $reject = $target;
        } else {
            $forward = $target;
        }
    }

    // Solo las actividades tienen subtareas: bloquean el paso a revision / completado.
    $pendingSubtasks = $item instanceof Activity && ! $item->isTemplate() ? max(0, $item->subtasksTotalCount() - $item->subtasksDoneCount()) : 0;
    $isBlocked = $forward !== null && $pendingSubtasks > 0 && in_array($forward, [TicketStatus::InReview, TicketStatus::Completed], true);
    $isTemplate = $item instanceof Activity && $item->isTemplate();

    // Bolsa: ticket sin asignar que este usuario puede tomar (la Policy decide; se repite el estado por claridad).
    $canTake = $isTicket && $item->assignments->isEmpty() && ! $status->isFinal() && auth()->user()->can('take', $item);

    $summaryClasses = 'inline-flex min-h-[44px] w-full cursor-pointer list-none items-center justify-center gap-2 rounded-lg border px-5 py-2 text-sm font-semibold shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 sm:w-auto [&::-webkit-details-marker]:hidden';
    $textareaClasses = 'mt-1 block min-h-[44px] w-full rounded-lg border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal';
    $hasCommentError = $errors->has('comment');
    $transitionUrl = route($routePrefix.'.transition', $item);
@endphp

<div {{ $attributes->merge(['class' => 'space-y-4']) }}>
    {{-- Bolsa: la accion mas visible cuando el ticket esta sin asignar y este usuario puede tomarlo. --}}
    @if ($canTake)
        <form method="POST" action="{{ route('tickets.take', $item) }}" class="rounded-xl border-2 border-brand-green bg-brand-mist p-4">
            @csrf
            <p class="flex items-center gap-2 text-base font-semibold text-brand-green">
                <x-icon name="inbox" class="h-5 w-5" />
                {{ __('workflow.bag.title') }}
            </p>
            <p class="mb-3 mt-1 text-sm text-gray-800">{{ __('workflow.bag.hint') }}</p>
            <x-primary-button class="w-full gap-2 py-3 text-base sm:w-auto">
                <x-icon name="check" class="h-5 w-5" />
                {{ __('tickets.actions.take') }}
            </x-primary-button>
        </form>
    @endif

    {{-- Accion principal: el siguiente paso natural del flujo. Se envia sin comentario. --}}
    @if ($forward)
        <form method="POST" action="{{ $transitionUrl }}" class="space-y-2">
            @csrf
            <input type="hidden" name="status" value="{{ $forward->value }}">
            <p class="text-sm text-gray-800">{{ __('workflow.next_hint.'.$forward->value) }}</p>
            @if ($isBlocked)
                <x-primary-button class="w-full gap-2 disabled:cursor-not-allowed sm:w-auto" disabled aria-describedby="transition_blocked_hint">
                    <x-icon name="arrow-right" class="h-5 w-5" />
                    {{ __($group.'.actions.transition.'.$forward->actionLabelKeyFrom($status)) }}
                </x-primary-button>
                <p id="transition_blocked_hint" class="flex items-start gap-2 rounded-lg border-l-4 border-amber-600 bg-amber-50 p-3 text-sm text-amber-950">
                    <x-icon name="flag" class="mt-0.5 h-4 w-4" />
                    <span>
                        {{ __('workflow.blocked', ['count' => $pendingSubtasks]) }}
                        <a href="#subtareas" class="font-semibold underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('workflow.go_to_subtasks') }}</a>
                    </span>
                </p>
            @else
                <x-primary-button class="w-full gap-2 sm:w-auto">
                    <x-icon name="arrow-right" class="h-5 w-5" />
                    {{ __($group.'.actions.transition.'.$forward->actionLabelKeyFrom($status)) }}
                </x-primary-button>
            @endif
        </form>
    @elseif (count($transitions) === 0 && ! $canTake && ! $status->isFinal() && ! $isTemplate)
        <p class="text-sm text-gray-800">{{ __('workflow.waiting.'.$status->value) }}</p>
    @endif

    {{-- Acciones que exigen contexto: el comentario obligatorio aparece dentro del propio bloque expandible. --}}
    @if ($reject || $reopen || $cancel)
        @if ($forward || $canTake)
            <p class="border-t border-brand-green/10 pt-4 text-xs font-semibold uppercase tracking-wide text-gray-700">{{ __('workflow.other_actions') }}</p>
        @endif

        <div class="grid items-start gap-3 sm:grid-cols-2">
            @if ($reject)
                <details class="rounded-lg" @if ($hasCommentError) open @endif>
                    <summary class="{{ $summaryClasses }} border-amber-600 bg-white text-amber-950 hover:bg-amber-50 focus-visible:ring-amber-600">
                        <x-icon name="repeat" class="h-5 w-5" />
                        {{ __($group.'.actions.transition.reject') }}
                    </summary>
                    <form method="POST" action="{{ $transitionUrl }}" class="mt-2 rounded-lg border border-amber-600/40 bg-amber-50/50 p-3">
                        @csrf
                        <input type="hidden" name="status" value="{{ $reject->value }}">
                        <x-input-label for="transition_reject_comment" :value="__($group.'.actions.comment_label').' ('.__('workflow.required').')'" />
                        <textarea id="transition_reject_comment" name="comment" rows="3" maxlength="{{ $maxComment }}" required aria-describedby="transition_reject_hint" class="{{ $textareaClasses }}"></textarea>
                        <p id="transition_reject_hint" class="mb-3 mt-1 text-xs text-gray-700">{{ __('workflow.reject_hint') }}</p>
                        <x-danger-button class="w-full sm:w-auto">{{ __($group.'.actions.transition.reject') }}</x-danger-button>
                    </form>
                </details>
            @endif

            @if ($reopen)
                <details class="rounded-lg sm:col-span-2" @if ($hasCommentError) open @endif>
                    <summary class="{{ $summaryClasses }} border-brand-teal bg-white text-brand-green hover:bg-brand-mist focus-visible:ring-brand-teal">
                        <x-icon name="repeat" class="h-5 w-5" />
                        {{ __($group.'.actions.transition.pending') }}
                    </summary>
                    <form method="POST" action="{{ $transitionUrl }}" class="mt-2 rounded-lg border border-brand-green/15 bg-brand-mist/60 p-3">
                        @csrf
                        <input type="hidden" name="status" value="{{ $reopen->value }}">
                        <x-input-label for="transition_reopen_comment" :value="__($group.'.actions.comment_label').' ('.__('workflow.required').')'" />
                        <textarea id="transition_reopen_comment" name="comment" rows="3" maxlength="{{ $maxComment }}" required aria-describedby="transition_reopen_hint" class="{{ $textareaClasses }}"></textarea>
                        <p id="transition_reopen_hint" class="mb-3 mt-1 text-xs text-gray-700">{{ __('workflow.reopen_hint') }}</p>
                        <x-primary-button class="w-full sm:w-auto">{{ __($group.'.actions.transition.pending') }}</x-primary-button>
                    </form>
                </details>
            @endif

            @if ($cancel)
                <details class="rounded-lg">
                    <summary class="{{ $summaryClasses }} border-red-700 bg-white text-red-800 hover:bg-red-50 focus-visible:ring-red-700">
                        <x-icon name="close" class="h-5 w-5" />
                        {{ __($group.'.actions.transition.cancelled') }}
                    </summary>
                    <form method="POST" action="{{ $transitionUrl }}" x-data="confirmSubmit" data-confirm="{{ __('workflow.cancel_confirm') }}" x-on:submit="onSubmit" class="mt-2 rounded-lg border border-red-700/30 bg-red-50/50 p-3">
                        @csrf
                        <input type="hidden" name="status" value="{{ $cancel->value }}">
                        <x-input-label for="transition_cancel_comment" :value="__($group.'.actions.comment_label').' ('.__('workflow.optional').')'" />
                        <textarea id="transition_cancel_comment" name="comment" rows="2" maxlength="{{ $maxComment }}" aria-describedby="transition_cancel_hint" class="{{ $textareaClasses }}"></textarea>
                        <p id="transition_cancel_hint" class="mb-3 mt-1 text-xs text-gray-700">{{ __('workflow.cancel_hint') }}</p>
                        <x-danger-button class="w-full sm:w-auto">{{ __($group.'.actions.transition.cancelled') }}</x-danger-button>
                    </form>
                </details>
            @endif
        </div>
    @endif
</div>
