<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreAttachmentRequest;
use App\Models\Activity;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

class ActivityAttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function store(StoreAttachmentRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('attach', $activity);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->attachments->store($request->user(), $activity, $file);

        return redirect()->route('activities.show', $activity)->withFragment('adjuntos')->with('status', 'attachment-added');
    }
}
