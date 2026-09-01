<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCommentRead;
use App\Models\WorkOrderUpdate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class TaskCommentPresenter
{
    public function timestamp(?CarbonInterface $value): ?string
    {
        return $value?->copy()
            ->setTimezone(TodayWorkspace::BUSINESS_TIMEZONE)
            ->locale('th')
            ->translatedFormat('j M Y H:i');
    }

    public function comment(WorkOrderUpdate $comment, User $viewer, array $readers = []): array
    {
        return [
            'id' => $comment->id,
            'author_id' => $comment->user_id,
            'author' => $comment->user?->name ?? 'ไม่ระบุ',
            'avatar_url' => $comment->user?->profile_image ? route('media.profile', $comment->user) : null,
            'note' => $comment->note,
            'at' => $this->timestamp($comment->created_at),
            'is_comment' => (bool) $comment->is_comment,
            'is_mine' => (int) $comment->user_id === (int) $viewer->id,
            'readers' => $readers,
        ];
    }

    public function receipts(WorkOrder $task): array
    {
        return $this->receiptsForTasks(collect([$task]))->get((string) $task->job_id, []);
    }

    public function receiptsForTasks(Collection $tasks): Collection
    {
        $tasks = $tasks->filter()->keyBy(fn (WorkOrder $task) => (string) $task->job_id);
        if ($tasks->isEmpty()) {
            return collect();
        }

        $reads = WorkOrderCommentRead::with('user')
            ->whereIn('work_order_id', $tasks->keys())
            ->get()
            ->groupBy(fn (WorkOrderCommentRead $read) => (string) $read->work_order_id);

        return $tasks->map(function (WorkOrder $task) use ($reads): array {
            $eligibleReads = $reads->get((string) $task->job_id, collect())
                ->filter(fn (WorkOrderCommentRead $read) => $read->user
                    && $read->user->is_active
                    && Gate::forUser($read->user)->allows('viewComments', $task));

            return $task->updates
                ->where('is_comment', true)
                ->mapWithKeys(function (WorkOrderUpdate $comment) use ($eligibleReads): array {
                    $readers = $eligibleReads
                        ->filter(fn (WorkOrderCommentRead $read) => (int) $read->last_read_update_id >= (int) $comment->id)
                        ->reject(fn (WorkOrderCommentRead $read) => (int) $read->user_id === (int) $comment->user_id)
                        ->map(fn (WorkOrderCommentRead $read) => [
                            'id' => $read->user_id,
                            'name' => $read->user->name,
                            'avatar_url' => $read->user->profile_image ? route('media.profile', $read->user) : null,
                            'read_at' => $this->timestamp($read->updated_at),
                        ])
                        ->values()
                        ->all();

                    return [(string) $comment->id => $readers];
                })
                ->all();
        });
    }
}
