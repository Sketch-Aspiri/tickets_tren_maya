<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Models\Activity;
use App\Services\AssignmentService;
use Illuminate\Http\RedirectResponse;

/**
 * Asignar / reasignar una actividad: siempre un responsable y colaboradores (sin bolsa ni "tomar").
 */
class ActivityAssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments) {}

    public function update(AssignTicketRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('assign', $activity);

        $this->assignments->assign(
            $request->user(),
            $activity,
            (int) $request->validated('responsible_id'),
            $request->collaboratorIds(),
        );

        return redirect()->route('activities.show', $activity)->with('status', 'activity-assigned');
    }
}
