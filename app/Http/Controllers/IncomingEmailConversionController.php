<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Priority;
use App\Enums\RecurrenceFrequency;
use App\Http\Requests\Emails\ConvertIncomingEmailRequest;
use App\Models\Activity;
use App\Models\IncomingEmail;
use App\Models\Team;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\CategoryService;
use App\Services\IncomingEmailReviewService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Formulario de conversión de un correo entrante en Actividad: reutiliza `activities._form` (el MISMO
 * parcial de `activities.create`/`activities.edit`) con una Activity SIN GUARDAR, pre-llenada con el
 * asunto y el cuerpo del correo, y `showRecurrence: false` (una actividad convertida desde correo nunca
 * es recurrente: `ConvertIncomingEmailRequest` no acepta ese campo).
 */
class IncomingEmailConversionController extends Controller
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly AssignmentService $assignments,
    ) {}

    public function create(Request $request, IncomingEmail $incomingEmail): View
    {
        $this->authorize('convert', $incomingEmail);

        // Mismo patron que ActivityController::create() para una actividad nueva (new Activity(['priority'
        // => Priority::Medium])), con titulo/descripcion pre-llenados desde el correo de origen.
        $activity = new Activity([
            'priority' => Priority::Medium,
            'title' => mb_substr($incomingEmail->subject, 0, 255),
            'description' => mb_substr($incomingEmail->body, 0, 5000),
        ]);

        return view('incoming-emails.convert', [
            'email' => $incomingEmail,
            'activity' => $activity,
            'priorities' => Priority::cases(),
            'categories' => $this->categories->selectable($request->user(), $activity->category_id),
            'frequencies' => RecurrenceFrequency::cases(),
            'teams' => $this->teamOptions($request->user(), $incomingEmail),
            'assignableUsers' => $this->assignments->assignableUsers($request->user()),
            'rule' => null,
            'showRecurrence' => false,
        ]);
    }

    public function store(
        ConvertIncomingEmailRequest $request,
        IncomingEmail $incomingEmail,
        IncomingEmailReviewService $reviewService,
    ): RedirectResponse {
        $activity = $reviewService->convert($request->user(), $incomingEmail, [
            ...$request->validated(),
            'collaborator_ids' => $request->collaboratorIds(),
        ]);

        return redirect()->route('activities.show', $activity)->with('status', 'incoming-email-converted');
    }

    /**
     * Solo equipos de la coordinacion del correo (y dentro del alcance de quien convierte). Quien no tiene alcance
     * global y solo puede usar un equipo no necesita elegir: la lista queda vacia y se usa ese.
     *
     * @return Collection<int, Team>
     */
    private function teamOptions(User $actor, IncomingEmail $email): Collection
    {
        $teams = Team::query()
            ->where('coordination_id', $email->coordination_id)
            ->when(! $actor->seesAllTeams(), fn ($query) => $query->whereIn('id', $actor->workingTeamIds()))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $actor->seesAllTeams() || $teams->count() > 1 || ($teams->count() === 1 && count($actor->workingTeamIds()) > 1) ? $teams : new Collection;
    }
}
