<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreCommentRequest;
use App\Models\Activity;
use App\Services\CommentService;
use Illuminate\Http\RedirectResponse;

class ActivityCommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    public function store(StoreCommentRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('comment', $activity);

        $this->comments->add($request->user(), $activity, (string) $request->validated('body'));

        return redirect()->route('activities.show', $activity)->withFragment('comentarios')->with('status', 'comment-added');
    }
}
