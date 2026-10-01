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
    /**
     * @param  array<int, int>  $mentionIds  รายชื่อ user id ที่ผู้เขียนกล่าวถึงด้วย @ — ฝั่ง client
     *                                       ส่งมาตามที่ผู้ใช้เลือกจากรายการคนในงานเท่านั้น แต่ต้องกรอง
     *                                       ซ้ำที่นี่อีกชั้นเทียบกับ mentionCandidates() เสมอ ห้ามเชื่อ client ตรง ๆ
     * @param  bool  $mentionAll  ผู้เขียนพิมพ์ "@all" — กล่าวถึงทุกคนที่กล่าวถึงได้ในงานนี้ (ชุดเดียวกับ
     *                            mentionCandidates() ซึ่งกรอง viewer และบัญชีปิดใช้งานออกไปแล้ว) แทนที่
     *                            $mentionIds ทั้งหมด ไม่รวมกัน เพราะ @all ครอบคลุมทุกคนอยู่แล้ว
     */
    public function post(WorkOrder $task, User $author, string $message, array $images = [], ?WorkOrderUpdate $replyTo = null, array $mentionIds = [], bool $mentionAll = false): WorkOrderUpdate
    {
        return DB::transaction(function () use ($task, $author, $message, $images, $replyTo, $mentionIds, $mentionAll) {
            $comment = $task->updates()->create([
                'user_id' => $author->id,
                'note' => $message,
                'is_comment' => true,
                'reply_to_id' => $replyTo?->id,
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

            $candidateIds = $this->mentionCandidates($task)->pluck('id')->map(fn ($id) => (int) $id);
            $eligibleMentionIds = ($mentionAll ? $candidateIds : $candidateIds->intersect(collect($mentionIds)->map(fn ($id) => (int) $id)))
                ->reject(fn ($id) => $id === (int) $author->id)
                ->values();

            if ($eligibleMentionIds->isNotEmpty()) {
                $comment->mentions()->sync($eligibleMentionIds);
            }

            WorkOrderCommentRead::updateOrCreate(
                ['work_order_id' => $task->job_id, 'user_id' => $author->id],
                ['last_read_update_id' => $comment->id]
            );

            $audienceIds = $this->audienceIds($task)->reject(fn ($id) => $id === (int) $author->id);
            $generalRecipientIds = $audienceIds->diff($eligibleMentionIds);

            if ($generalRecipientIds->isNotEmpty()) {
                $this->notifications->notify($generalRecipientIds, 'task_comment', 'ความคิดเห็นใหม่ในงาน',
                    Str::limit($author->name.' แสดงความคิดเห็นในงาน “'.$task->job_topic.'”', 1000, ''),
                    $task, $author, ['comment_id' => $comment->id]);
            }

            if ($eligibleMentionIds->isNotEmpty()) {
                $this->notifications->notify($eligibleMentionIds, 'task_comment_mention', 'มีคนกล่าวถึงคุณในความคิดเห็น',
                    Str::limit($author->name.' กล่าวถึงคุณในความคิดเห็นของงาน “'.$task->job_topic.'”', 1000, ''),
                    $task, $author, ['comment_id' => $comment->id]);
            }

            return $comment->load(['user', 'attachments', 'replyTo.user', 'mentions']);
        });
    }

    /**
     * ปักหมุดคอมเมนต์ — ปักได้ทีละ 1 ข้อความต่องาน ปักข้อความใหม่จะเลิกปักข้อความเดิมอัตโนมัติ
     */
    public function pin(WorkOrder $task, WorkOrderUpdate $comment, User $actor): WorkOrderUpdate
    {
        return DB::transaction(function () use ($task, $comment, $actor) {
            WorkOrderUpdate::query()
                ->where('work_order_id', $task->job_id)
                ->where('is_comment', true)
                ->where('id', '!=', $comment->id)
                ->whereNotNull('pinned_at')
                ->update(['pinned_at' => null, 'pinned_by' => null]);

            $comment->update(['pinned_at' => now(), 'pinned_by' => $actor->id]);

            return $comment->fresh(['user', 'attachments', 'replyTo.user', 'mentions']);
        });
    }

    public function unpin(WorkOrderUpdate $comment): WorkOrderUpdate
    {
        $comment->update(['pinned_at' => null, 'pinned_by' => null]);

        return $comment->fresh(['user', 'attachments', 'replyTo.user', 'mentions']);
    }

    /**
     * คนที่ @กล่าวถึงได้ในงานนี้ — ชุดเดียวกับผู้รับแจ้งเตือนคอมเมนต์ (audienceIds) แต่กรองซ้ำ
     * ให้เหลือเฉพาะบัญชีที่เปิดใช้งานและไม่ใช่ viewer เพราะ viewer ต้องไม่ถูกกล่าวถึงหรือรับแจ้งเตือน
     */
    public function mentionCandidates(WorkOrder $task): Collection
    {
        $ids = $this->audienceIds($task);

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('role', '!=', 'viewer')
            ->orderBy('name')
            ->get(['id', 'name', 'profile_image']);
    }

    private function audienceIds(WorkOrder $task): Collection
    {
        /*
         * สิทธิ์เห็นงานระดับโปรเจกต์ไม่เท่ากับการเป็นผู้รับการแจ้งเตือนของทุกงานในโปรเจกต์
         * แจ้งเฉพาะคนที่อยู่ในงานใบที่ถูกคอมเมนต์จริง เพื่อไม่รบกวนผู้ร่วมงานของใบอื่น
         */
        $task->loadMissing('collaborators');
        $participantIds = collect([
            $task->user_id,
            $task->created_by,
            $task->leader_user_id,
        ])->merge(
            $task->collaborators
                ->filter(fn (User $user) => $user->pivot?->status === 'accepted')
                ->pluck('id')
        );

        /*
         * ไม่รวม admin
         *
         * เดิมใส่ admin ทุกคนเป็นผู้รับแจ้งเตือนคอมเมนต์ของงานโปรเจกต์ ทั้งที่คอมเมนต์คือ
         * บทสนทนาระหว่างคนที่ทำงานใบนั้นจริง ๆ ไม่ใช่เรื่องที่ผู้ดูแลระบบต้องลงมือ
         * admin ที่เข้าไปอยู่ในงานจริง (เป็นเจ้าของ ผู้สร้าง หรือผู้ร่วมงาน) ยังได้รับตามปกติ
         * เพราะจะถูกนับอยู่ใน $participantIds อยู่แล้ว
         */
        return $participantIds
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
                // ต้องรวม task_comment_mention ด้วย ไม่ใช่แค่ task_comment ธรรมดา ไม่งั้นคนที่ถูก
                // @กล่าวถึงเปิดแท็บอัปเดตอ่านคอมเมนต์ไปแล้ว แต่ตัวเลขแจ้งเตือนฉบับ "กล่าวถึงคุณ"
                // จะค้างไม่มีวันหายไปเพราะเป็น notification คนละ type กัน
                $ids = SystemNotification::where('user_id', $user->id)->where('work_order_id', $task->job_id)
                    ->whereIn('type', ['task_comment', 'task_comment_mention'])->whereNull('read_at')->get()
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
