<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCommentRead;
use App\Models\WorkOrderUpdate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
            // ส่งเฉพาะ URL ที่ผ่านการตรวจสิทธิ์ ห้ามส่ง file_path จริงออกไปที่ JSON
            'images' => $comment->relationLoaded('attachments')
                ? $comment->attachments->map(fn ($image) => [
                    'url' => route('media.comment-attachments.show', $image),
                    'name' => $image->original_name,
                ])->values()->all()
                : [],
            'pinned' => $comment->pinned_at !== null,
            'pinned_at' => $this->timestamp($comment->pinned_at),
            'reply_to' => $comment->relationLoaded('replyTo') && $comment->replyTo
                ? [
                    'id' => $comment->replyTo->id,
                    'author' => $comment->replyTo->user?->name ?? 'ไม่ระบุ',
                    'note' => Str::limit((string) $comment->replyTo->note, 140),
                    /*
                     * รูปของข้อความต้นทางต้องมาด้วย ไม่งั้นการตอบกลับคอมเมนต์ที่มีแต่รูป
                     * จะได้บล็อกอ้างถึงที่มีแค่ชื่อคน ผู้อ่านไม่รู้ว่ากำลังพูดถึงรูปไหน
                     * ใช้ route เดียวกับรูปในฟองแชท จึงผ่านการตรวจสิทธิ์ของ MediaController เหมือนกัน
                     */
                    'images' => $comment->replyTo->relationLoaded('attachments')
                        ? $comment->replyTo->attachments->map(fn ($image) => [
                            'url' => route('media.comment-attachments.show', $image),
                            'name' => $image->original_name,
                        ])->values()->all()
                        : [],
                ]
                : null,
            'mentions' => $comment->relationLoaded('mentions')
                ? $comment->mentions->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all()
                : [],
        ];
    }

    /**
     * ไทม์ไลน์ของงานหนึ่งใบสำหรับโมดัลรายละเอียดงาน (แท็บ "อัปเดต" และ "กิจกรรม")
     *
     * เดิมทุกงานในหน้า "งานของฉัน" ถูกสร้างก้อนนี้ฝังมาตั้งแต่โหลดหน้า ซึ่งเป็นส่วนใหญ่ของ
     * เวลาที่ server ใช้สร้าง HTML ตอนนี้ถูกขอทีละใบเมื่อผู้ใช้เปิดงานนั้นจริง (TaskCommentController::timeline)
     * รูปแบบแต่ละรายการต้องเหมือนเดิมทุกคีย์ เพราะ task-timeline.js อ่านชุดเดียวกัน
     *
     * ผู้เรียกต้อง eager-load updates.user, updates.attachments, updates.replyTo.user,
     * updates.replyTo.attachments, updates.mentions และ activityLogs.user มาก่อน
     *
     * @return array{updates: list<array<string, mixed>>, activity: list<array<string, mixed>>}
     */
    public function timeline(WorkOrder $task, User $viewer): array
    {
        $canViewComments = Gate::forUser($viewer)->allows('viewComments', $task);
        $receipts = $this->receipts($task);

        return [
            // อัปเดตแบบเก่าที่ไม่ใช่คอมเมนต์ (is_comment = false) ยังแสดงเหมือนเดิม คอมเมนต์ต้องมีสิทธิ์อ่าน
            'updates' => $task->updates
                ->filter(fn (WorkOrderUpdate $update) => ! $update->is_comment || $canViewComments)
                ->map(fn (WorkOrderUpdate $update) => $this->comment($update, $viewer, $receipts[(string) $update->id] ?? []))
                ->values()
                ->all(),
            'activity' => $task->activityLogs
                ->map(fn ($log) => [
                    'author' => $log->user?->name ?? 'ระบบ',
                    'avatar_url' => $log->user?->profile_image ? route('media.profile', $log->user) : null,
                    'note' => $log->description,
                    'at' => $this->timestamp($log->created_at),
                    'is_mine' => (int) $log->user_id === (int) $viewer->id,
                ])
                ->values()
                ->all(),
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
