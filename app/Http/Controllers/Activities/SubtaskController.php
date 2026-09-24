<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\DeleteSubtaskRequest;
use App\Http\Requests\Activities\MarkSubtaskDoneRequest;
use App\Http\Requests\Activities\StoreSubtaskRequest;
use App\Http\Requests\Activities\UpdateSubtaskRequest;
use App\Models\Activity;
use App\Models\Subtask;
use App\Services\SubtaskService;
use Illuminate\Http\RedirectResponse;

class SubtaskController extends Controller
{
    public function __construct(private readonly SubtaskService $subtasks) {}

    public function store(StoreSubtaskRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('create', [Subtask::class, $activity]);

        $this->subtasks->add($request->user(), $activity, (string) $request->validated('title'), $request->assignee());

        return $this->back($activity, 'subtask-added');
    }

    public function update(UpdateSubtaskRequest $request, Activity $activity, Subtask $subtask): RedirectResponse
    {
        $this->authorize('update', $subtask);

        $this->subtasks->update($request->user(), $subtask, (string) $request->validated('title'), $request->assignee());

        return $this->back($activity, 'subtask-updated');
    }

    public function done(MarkSubtaskDoneRequest $request, Activity $activity, Subtask $subtask): RedirectResponse
    {
        $this->authorize('markDone', $subtask);

        $this->subtasks->setDone($request->user(), $subtask, $request->isDone());

        return $this->back($activity, 'subtask-updated');
    }

    public function destroy(DeleteSubtaskRequest $request, Activity $activity, Subtask $subtask): RedirectResponse
    {
        $this->authorize('delete', $subtask);

        $this->subtasks->remove($request->user(), $subtask);

        return $this->back($activity, 'subtask-removed');
    }

    private function back(Activity $activity, string $status): RedirectResponse
    {
        return redirect()->route('activities.show', $activity)->withFragment('subtareas')->with('status', $status);
    }
}
