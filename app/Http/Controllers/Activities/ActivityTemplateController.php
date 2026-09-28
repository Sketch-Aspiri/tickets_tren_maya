<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\RestoreActivityRequest;
use App\Models\Activity;
use App\Services\ActivityListingService;
use App\Services\ActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Plantillas de recurrencia por separado del listado principal de actividades (nunca aparecen ahí: su
 * `due_date` puede quedar vencido con estado Pendiente porque nunca "avanzan"). Mismo permiso de gestión
 * (`activities.manage`) que el listado principal; el borrado reutiliza `ActivityController::destroy` sin
 * cambios, aquí solo se añade la "papelera" (restaurar) que es exclusiva de plantillas.
 */
class ActivityTemplateController extends Controller
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly ActivityListingService $listing,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Activity::class);

        return view('activities.templates.index', [
            'templates' => $this->listing->templatesFor($request->user(), $request->boolean('trashed')),
            'trashed' => $request->boolean('trashed'),
        ]);
    }

    public function restore(RestoreActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('restore', $activity);

        $this->activities->restore($activity);

        return redirect()->route('activities.templates.index', ['trashed' => 1])->with('status', 'activity-restored');
    }
}
