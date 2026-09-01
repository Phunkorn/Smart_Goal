<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\TaskCommentService;
use App\Services\NotificationService;
use App\Support\TaskCommentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    public function store(Request $request, WorkOrder $task, TaskCommentService $comments, TaskCommentPresenter $presenter): JsonResponse
    {
        $task->loadMissing(['collaborators', 'user', 'creator']);
        $this->authorize('comment', $task);
        $request->merge(['message' => trim((string) $request->input('message'))]);
        $validated = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $comment = $comments->post($task, $request->user(), $validated['message']);

        return response()->json([
            'ok' => true,
            'comment' => $presenter->comment($comment, $request->user()),
        ], 201);
    }

    public function markRead(Request $request, WorkOrder $task, TaskCommentService $comments, NotificationService $notifications, TaskCommentPresenter $presenter): JsonResponse
    {
        $task->loadMissing(['collaborators', 'updates.user']);
        $this->authorize('viewComments', $task);
        $latestId = $comments->markRead($task, $request->user());
        return response()->json([
            'ok' => true,
            'latest_comment_id' => $latestId,
            'unread_count' => $notifications->unreadCount($request->user()),
            'receipts' => $presenter->receipts($task),
        ]);
    }
}
