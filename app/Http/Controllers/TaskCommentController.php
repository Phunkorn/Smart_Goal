<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\TaskCommentService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    public function store(Request $request, WorkOrder $task, TaskCommentService $comments): JsonResponse
    {
        $task->loadMissing(['collaborators', 'user', 'creator']);
        $this->authorize('comment', $task);
        $request->merge(['message' => trim((string) $request->input('message'))]);
        $validated = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $comment = $comments->post($task, $request->user(), $validated['message']);

        return response()->json(['ok' => true, 'comment' => [
            'id' => $comment->id, 'author' => $comment->user?->name ?? 'ไม่ระบุ',
            'avatar_url' => $comment->user?->profile_image ? route('media.show', ['path' => $comment->user->profile_image]) : null,
            'note' => $comment->note, 'at' => $comment->created_at->locale('th')->translatedFormat('j M Y H:i'),
        ]], 201);
    }

    public function markRead(Request $request, WorkOrder $task, TaskCommentService $comments, NotificationService $notifications): JsonResponse
    {
        $task->loadMissing('collaborators');
        $this->authorize('view', $task);
        $latestId = $comments->markRead($task, $request->user());
        return response()->json([
            'ok' => true,
            'latest_comment_id' => $latestId,
            'unread_count' => $notifications->unreadCount($request->user()),
        ]);
    }
}
