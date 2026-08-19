<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCommentRead;
use App\Models\WorkOrderUpdate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskCommentService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function post(WorkOrder $task, User $author, string $message): WorkOrderUpdate
    {
        return DB::transaction(function () use ($task, $author, $message) {
            $comment = $task->updates()->create([
                'user_id' => $author->id,
                'progress' => (int) $task->job_progress,
                'note' => $message,
                'is_comment' => true,
            ]);

            $recipientIds = collect([$task->user_id, $task->created_by])
                ->merge($task->collaborators->filter(fn ($user) => $user->pivot?->status === 'accepted')->pluck('id'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === (int) $author->id);

            $this->notifications->notify($recipientIds, 'task_comment', 'ความคิดเห็นใหม่ในงาน',
                Str::limit($author->name.' แสดงความคิดเห็นในงาน “'.$task->job_topic.'”', 1000, ''),
                $task, $author, ['comment_id' => $comment->id]);

            return $comment->load('user');
        });
    }

    public function markRead(WorkOrder $task, User $user): int
    {
        return DB::transaction(function () use ($task, $user) {
            $latestId = (int) $task->updates()->where('is_comment', true)->max('id');
            WorkOrderCommentRead::updateOrCreate(
                ['work_order_id' => $task->job_id, 'user_id' => $user->id],
                ['last_read_update_id' => $latestId ?: null]
            );

            if ($latestId) {
                $ids = SystemNotification::where('user_id', $user->id)->where('work_order_id', $task->job_id)
                    ->where('type', 'task_comment')->whereNull('read_at')->get()
                    ->filter(fn ($notice) => (int) data_get($notice->data, 'comment_id') <= $latestId)->pluck('id');
                SystemNotification::whereIn('id', $ids)->update(['is_read' => true, 'read_at' => now()]);
            }

            return $latestId;
        });
    }

    public function unreadCounts(Collection $taskIds, User $user): Collection
    {
        if ($taskIds->isEmpty()) return collect();

        return WorkOrderUpdate::query()->selectRaw('work_order_id, COUNT(*) AS aggregate')
            ->whereIn('work_order_id', $taskIds)->where('is_comment', true)
            ->whereNotExists(function ($query) use ($user) {
                $query->selectRaw('1')->from('work_order_comment_reads')
                    ->whereColumn('work_order_comment_reads.work_order_id', 'work_order_updates.work_order_id')
                    ->where('work_order_comment_reads.user_id', $user->id)
                    ->whereColumn('work_order_comment_reads.last_read_update_id', '>=', 'work_order_updates.id');
            })->groupBy('work_order_id')->pluck('aggregate', 'work_order_id');
    }
}
