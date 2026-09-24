<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowTrackingPanelRequest;
use App\Services\DashboardMetricsService;
use App\Services\DashboardScope;
use App\Support\Dashboard\TrackingCharts;
use Illuminate\View\View;

/**
 * Panel de seguimiento (jefe: global; coordinador: su equipo). Solo lectura. La autorización corre en el
 * Form Request (antes de validar) y aquí se repite con la misma habilidad (defensa en profundidad).
 */
class TrackingPanelController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
        private readonly DashboardScope $scope,
    ) {}

    public function __invoke(ShowTrackingPanelRequest $request): View
    {
        $this->authorize('view-dashboard');

        $user = $request->user();
        $filters = $request->filters();
        $report = $this->metrics->report($user, $filters);

        return view('tracking.index', [
            'report' => $report,
            'charts' => TrackingCharts::build($report),
            'filters' => $filters,
            'teams' => $this->scope->selectableTeams($user),
            'employees' => $this->scope->selectableEmployees($user),
            'categories' => $this->scope->selectableCategories(),
        ]);
    }
}
