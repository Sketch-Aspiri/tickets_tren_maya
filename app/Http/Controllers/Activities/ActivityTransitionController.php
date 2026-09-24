<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Models\Activity;
use App\Services\ActivityService;
use Illuminate\Http\RedirectResponse;

class ActivityTransitionController extends Controller
{
    public function __construct(private readonly ActivityService $activities) {}

    public function store(TransitionTicketRequest $request, Activity $activity): RedirectResponse
    {
        $target = $request->target();
        $this->authorize('transition', [$activity, $target]);

        $this->activities->transition($request->user(), $activity, $target, $request->comment());

        return redirect()->route('activities.show', $activity)->with('status', 'activity-status-changed');
    }
}
