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

    /**
     * @param  array<int, array{path: string, original_name: string, file_type: string}>  $images
     *                                                                                             ไฟล์ที่ถูกเก็บลง storage เรียบร้อยแล้วโดยผู้เรียก
     *
     *   รับเป็นไฟล์ที่เก็บแล้ว ไม่ใช่ UploadedFile เพราะการเก็บไฟล์ต้องเกิดนอก
     *   transaction ถ้าเก็บข้างในแล้ว transaction ล้ม ไฟล์จะค้างบนดิสก์โดยไม่มี
     *   แถวอ้างถึง ผู้เรียกจึงเป็นผู้รับผิดชอบลบไฟล์กำพร้าเมื่อเมธอดนี้โยน
     */
    public function post(WorkOrder $task, User $author, string $message, array $images = []): WorkOrderUpdate
    {
        return DB::transaction(function () use ($task, $author, $message, $images) {
            $comment = $task->updates()->create([
                'user_id' => $author->id,
                'note' => $message,
                'is_comment' => true,
            ]);

            foreach ($images as $image) {
                $comment->attachments()->create([
                    'file_path' => $image['path'],
                    'original_name' => $image['original_name'],
                    'file_type' => $image['file_type'],
                    'byte_size' => $image['byte_size'] ?? 0,
                    'uploaded_by' => $author->id,
                ]);
            }

            WorkOrderCommentRead::updateOrCreate(
                ['work_order_id' => $task->job_id, 'user_id' => $author->id],
                ['last_read_update_id' => $comment->id]
            );

            $recipientIds = $this->audienceIds($task)
                ->reject(fn ($id) => $id === (int) $author->id);

            $this->notifications->notify($recipientIds, 'task_comment', 'ความคิดเห็นใหม่ในงาน',
                Str::limit($author->name.' แสดงความคิดเห็นในงาน “'.$task->job_topic.'”', 1000, ''),
                $task, $author, ['comment_id' => $comment->id]);

            return $comment->load(['user', 'attachments']);
        });
    }

    private function audienceIds(WorkOrder $task): Collection
    {
        $tasks = WorkOrder::query()
            ->with('collaborators')
            ->where('approval_status', 'approved')
            ->when(
                $task->work_order_list_id,
                fn ($query) => $query->where('work_order_list_id', $task->work_order_list_id),
                fn ($query) => $query->whereKey($task->job_id)
            )
            ->get();

        $participantIds = $tasks->flatMap(fn (WorkOrder $projectTask) => collect([
            $projectTask->user_id,
            $projectTask->created_by,
            $projectTask->leader_user_id,
        ])->merge(
            $projectTask->collaborators
                ->filter(fn (User $user) => $user->pivot?->status === 'accepted')
                ->pluck('id')
        ));

        return $participantIds
            ->merge(User::query()->where('role', 'admin')->where('is_active', true)->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
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
        if ($taskIds->isEmpty()) {
            return collect();
        }

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
