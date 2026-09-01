<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Models\WorkOrder;
use App\Support\TaskCommentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeSyncController extends Controller
{
    public function __invoke(Request $request, NotificationService $notifications, TaskCommentPresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['required', 'integer', 'min:0'],
            'task_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $payload = $notifications->syncFeed($request->user(), (int) $validated['after']);
        $task = isset($validated['task_id'])
            ? WorkOrder::with(['collaborators', 'updates.user'])->find($validated['task_id'])
            : null;
        $payload['comment_receipts'] = $task && $request->user()->can('viewComments', $task)
            ? ['task_id' => $task->job_id, 'receipts' => $presenter->receipts($task)]
            : null;

        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, private');
    }
}
