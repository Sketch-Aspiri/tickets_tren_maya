<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\RecurrenceFrequency;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\DeleteActivityRequest;
use App\Http\Requests\Activities\IndexActivitiesRequest;
use App\Http\Requests\Activities\StoreActivityRequest;
use App\Http\Requests\Activities\UpdateActivityRequest;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use App\Services\ActivityListingService;
use App\Services\ActivityService;
use App\Services\AssignmentService;
use App\Services\CategoryService;
use App\Support\RecurrenceRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly ActivityListingService $listing,
        private readonly CategoryService $categories,
        private readonly AssignmentService $assignments,
    ) {}

    public function index(IndexActivitiesRequest $request): View
    {
        $this->authorize('viewAny', Activity::class);

        $user = $request->user();

        return view('activities.index', [
            'activities' => $this->listing->paginate($user, $request->filters()),
            'filters' => $request->filters(),
            'statuses' => TicketStatus::cases(),
            'priorities' => Priority::cases(),
            'kinds' => ActivityListingService::KINDS,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            // Filtro por equipo: solo el jefe ve mas de un equipo.
            'teams' => $user->team_id === null ? Team::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'responsibles' => $this->assignments->assignableUsers($user),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Activity::class);

        return view('activities.create', $this->formData($request, new Activity(['priority' => Priority::Medium])));
    }

    public function store(StoreActivityRequest $request): RedirectResponse
    {
        $this->authorize('create', Activity::class);

        $activity = $this->activities->create($request->user(), [
            ...$request->validated(),
            'collaborator_ids' => $request->collaboratorIds(),
        ]);

        return redirect()->route('activities.show', $activity)->with('status', 'activity-created');
    }

    public function show(Request $request, Activity $activity): View
    {
        $this->authorize('view', $activity);

        $user = $request->user();
        $activity->loadCount(['subtasks', 'subtasks as subtasks_done_count' => fn ($subtask) => $subtask->where('done', true)])
            ->load([
                'category:id,name',
                'team:id,name',
                'creator:id,name',
                'parent:id,folio',
                'assignments' => fn ($query) => $query->orderBy('id'),
                'assignments.user:id,name',
                'subtasks' => fn ($query) => $query->orderBy('id'),
                'subtasks.assignee:id,name',
                'statusHistories' => fn ($query) => $query->orderBy('id'),
                'statusHistories.user:id,name',
                'comments' => fn ($query) => $query->orderBy('id'),
                'comments.user:id,name',
                'attachments' => fn ($query) => $query->orderBy('id'),
                'attachments.user:id,name',
            ]);

        $canAssign = $user->can('assign', $activity);

        return view('activities.show', [
            'activity' => $activity,
            'transitions' => $this->activities->availableTransitions($user, $activity),
            'assignableUsers' => $canAssign ? $this->assignments->assignableUsers($user) : collect(),
            'recurrence' => $activity->isTemplate() ? RecurrenceRule::fromArray((array) $activity->recurrence_rule) : null,
            'subtaskAbilities' => $this->subtaskAbilities($user, $activity),
        ]);
    }

    public function edit(Request $request, Activity $activity): View
    {
        $this->authorize('update', $activity);

        return view('activities.edit', $this->formData($request, $activity));
    }

    public function update(UpdateActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('update', $activity);

        $this->activities->update($activity, $request->activityData());

        return redirect()->route('activities.show', $activity)->with('status', 'activity-updated');
    }

    public function destroy(DeleteActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('delete', $activity);

        $this->activities->delete($activity);

        return redirect()->route('activities.index')->with('status', 'activity-deleted');
    }

    /**
     * Lo que este usuario puede hacer con las subtareas, calculado UNA vez (no por subtarea) para no disparar
     * consultas por fila. Ocultar controles es UX: cada accion se vuelve a autorizar en su ruta y en SubtaskService.
     *
     * @return array{manage: bool, markAll: bool, work: bool}
     */
    private function subtaskAbilities(User $user, Activity $activity): array
    {
        $canWork = $user->can('comment', $activity);
        $canManage = $user->can('delete', $activity);
        $isResponsible = $activity->assignments->contains(fn ($assignment): bool => (int) $assignment->user_id === (int) $user->getKey()
            && $assignment->role === AssignmentRole::Responsable);

        return [
            'manage' => $canManage,
            'markAll' => $canWork && ($canManage || $isResponsible),
            'work' => $canWork,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, Activity $activity): array
    {
        $creating = ! $activity->exists;

        return [
            'activity' => $activity,
            'priorities' => Priority::cases(),
            'categories' => $this->categories->selectable($activity->category_id),
            'frequencies' => RecurrenceFrequency::cases(),
            // Solo el jefe (sin equipo propio) elige el equipo de la actividad nueva.
            'teams' => $creating && $request->user()->team_id === null
                ? Team::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            // Responsable y colaboradores se eligen al crear; despues se cambian desde el detalle.
            'assignableUsers' => $creating ? $this->assignments->assignableUsers($request->user()) : collect(),
            'rule' => $activity->isTemplate() ? RecurrenceRule::fromArray((array) $activity->recurrence_rule)->toArray() : null,
        ];
    }
}
