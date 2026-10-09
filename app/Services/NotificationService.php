<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Meeting;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderListTaskRequest;
use App\Models\WorkOrderShareRequest;
use App\Models\WorkOrderUpdate;
use App\Services\Telegram\TelegramOutbox;
use App\Support\ApprovalPresenter;
use App\Support\ProjectRoles;
use App\Support\TaskCommentPresenter;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class NotificationService
{
    /** จำนวนการแจ้งเตือนต่อหนึ่งหน้าของศูนย์การแจ้งเตือน */
    public const CENTER_PAGE_SIZE = 10;

    public function __construct(
        private readonly TaskCommentPresenter $commentPresenter,
        private readonly TelegramOutbox $telegram,
    ) {}

    private function create(User|int $recipient, string $type, string $title, ?string $message = null, ?WorkOrder $task = null, ?User $actor = null, array $data = [], ?string $dedupeKey = null, array $metadata = []): SystemNotification
    {
        $attributes = [
            'user_id' => $recipient instanceof User ? $recipient->id : $recipient,
            'actor_user_id' => $actor?->id,
            'work_order_id' => $metadata['work_order_id'] ?? $task?->job_id,
            'work_order_list_id' => $metadata['work_order_list_id'] ?? $task?->work_order_list_id,
            'type' => $type,
            'category' => SystemNotification::categoryForType($type),
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
            'dedupe_key' => $dedupeKey,
        ];

        $notification = $dedupeKey
            ? SystemNotification::firstOrCreate(['user_id' => $attributes['user_id'], 'dedupe_key' => $dedupeKey], $attributes)
            : SystemNotification::create($attributes);

        $this->queueTelegram($notification, $recipient);

        return $notification;
    }

    /**
     * ส่งต่อการแจ้งเตือนฉบับเดียวกันออกทาง Telegram
     *
     * เสียบไว้ที่ create() ซึ่งเป็นคอขวดที่การแจ้งเตือนทุกชนิดผ่าน จึงไม่ต้องแก้จุดเรียก
     * กว่าสามสิบแห่งใน controllers และ services และตัวกรองผู้รับเดิม (ผู้ใช้ที่ยังใช้งานอยู่,
     * ไม่ใช่ viewer, ไม่ใช่ตัวผู้ทำรายการเอง และต้องมีสิทธิ์ดูงาน) ยังบังคับใช้เหมือนเดิมทั้งหมด
     *
     * ต้องเช็ค wasRecentlyCreated เสมอ เพราะเส้นทางที่มี dedupe_key ใช้ firstOrCreate()
     * ซึ่งคืนแถวเดิมเมื่อเคยแจ้งไปแล้ว ถ้าไม่เช็คจะยิงซ้ำทุกครั้งที่งานเลยกำหนดถูกสแกนใหม่
     *
     * ห้ามให้ข้อผิดพลาดของช่องทางเสริมทำให้การแจ้งเตือนในระบบล้มเหลว จึงกลืน exception ทิ้ง
     */
    private function queueTelegram(SystemNotification $notification, User|int $recipient): void
    {
        if (! $notification->wasRecentlyCreated) {
            return;
        }

        try {
            $user = $recipient instanceof User ? $recipient : User::find($recipient);

            if (! $user || ! $user->receivesTelegramNotifications()) {
                return;
            }

            $this->telegram->enqueue($notification, $user, $this->target($notification, $user));
        } catch (Throwable $exception) {
            Log::warning('Telegram enqueue failed', [
                'system_notification_id' => $notification->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function notify(Collection|array $recipients, string $type, string $title, ?string $message = null, ?WorkOrder $task = null, ?User $actor = null, array $data = [], ?string $dedupePrefix = null): Collection
    {
        return User::whereIn('id', collect($recipients)->map(fn ($recipient) => $recipient instanceof User ? $recipient->id : $recipient)->filter()->unique())
            ->where('is_active', true)->get()
            ->reject(fn (User $user) => $user->role === 'viewer')
            ->reject(fn (User $user) => $actor && (int) $user->id === (int) $actor->id)
            ->filter(fn (User $user) => ! $task || Gate::forUser($user)->allows('view', $task))
            ->map(fn (User $user) => $this->create($user, $type, $title, $message, $task, $actor, $data, $dedupePrefix ? $dedupePrefix.':'.$user->id : null));
    }

    /**
     * คำขออนุมัติต้องตรวจสิทธิ์อนุมัติของผู้รับโดยตรง เพราะหัวหน้าแผนกปลายทาง
     * อาจยังไม่มีสิทธิ์ดูงานของแผนกต้นทางก่อนตัดสินคำขอ
     */
    public function notifyApprovalRequest(Collection|array $recipients, string $type, string $title, string $message, WorkOrder $task, User $actor, ?User $candidate = null, ?string $dedupePrefix = null): Collection
    {
        return User::whereIn('id', collect($recipients)->map(fn ($recipient) => $recipient instanceof User ? $recipient->id : $recipient)->filter()->unique())
            ->where('is_active', true)->get()
            ->reject(fn (User $user) => $user->role === 'viewer')
            ->reject(fn (User $user) => (int) $user->id === (int) $actor->id)
            ->filter(fn (User $user) => Gate::forUser($user)->allows('approve', $task))
            ->map(fn (User $user) => $this->create(
                $user,
                $type,
                $title,
                $message,
                $task,
                $actor,
                $candidate ? ['candidate_user_id' => $candidate->id] : [],
                $dedupePrefix ? $dedupePrefix.':'.$user->id : null
            ));
    }

    public function notifyRemovedParticipant(User $recipient, string $type, string $title, ?string $message, WorkOrder $task, User $actor, array $data = []): ?SystemNotification
    {
        if (! $recipient->is_active || $recipient->role === 'viewer' || (int) $recipient->id === (int) $actor->id) {
            return null;
        }

        return $this->create($recipient, $type, $title, $message, $task, $actor, $data);
    }

    public function notifyDetached(Collection|array $recipients, string $type, string $title, ?string $message, ?User $actor = null, array $data = [], array $metadata = [], ?string $dedupePrefix = null): Collection
    {
        return User::whereIn('id', collect($recipients)->map(fn ($recipient) => $recipient instanceof User ? $recipient->id : $recipient)->filter()->unique())
            ->where('is_active', true)->where('role', '!=', 'viewer')->get()
            ->reject(fn (User $user) => $actor && (int) $user->id === (int) $actor->id)
            ->map(fn (User $user) => $this->create($user, $type, $title, $message, null, $actor, $data,
                $dedupePrefix ? $dedupePrefix.':'.$user->id : null, $metadata));
    }

    public function notifyTaskMembers(WorkOrder $task, string $type, string $title, string $message, User $actor): void
    {
        $task->loadMissing('collaborators');

        $recipientIds = collect([$task->user_id, $task->created_by, $task->leader_user_id])
            ->merge($task->collaborators->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($userId) => (int) $userId === (int) $actor->id)
            ->values();

        $this->notify(
            $recipientIds,
            $type,
            Str::limit(strip_tags($title), 120, ''),
            Str::limit(strip_tags($message), 1000, ''),
            $task,
            $actor
        );
    }

    /**
     * ผู้ที่ต้องติดตามงานใบนี้ = หัวหน้าแผนกปลายทาง
     *
     * เดิมชื่อ notifyTaskAdmins() และส่งหา admin ทุกคนแบบไม่มีเงื่อนไขด้วย ทำให้ admin
     * ได้รับแจ้งเตือนทุกครั้งที่ลูกทีมมอบหมายงานกันเองภายในแผนก ซึ่งเป็นงานประจำวันที่
     * admin ไม่เกี่ยวข้องและไม่มีอะไรต้องลงมือ
     *
     * admin เป็นผู้ดูแลระบบ ไม่ใช่ผู้ดูแลงาน จะถูกแจ้งเตือนเฉพาะเรื่องที่มีแต่ admin ทำได้
     * (คำขอลบงาน — ดู WorkOrderPolicy::delete) หรือเรื่องที่ไม่มีหัวหน้าแผนกรับผิดชอบ
     * (ดู departmentApprovalRecipientIds) เท่านั้น
     *
     * ถ้าแผนกยังไม่มีหัวหน้า รายชื่อจะว่างและไม่มีใครได้รับ ซึ่งถูกต้อง เพราะการมอบหมายงาน
     * ภายในแผนกเป็นเรื่องที่ทั้งผู้สั่งและผู้รับรู้กันอยู่แล้ว ไม่ใช่คำขอที่ต้องมีคนตัดสิน
     *
     * @param  array<int>  $excludeIds  คนที่ได้รับแจ้งเตือนฉบับของตัวเองไปแล้ว เช่นผู้รับงาน
     */
    public function notifyTaskOverseers(WorkOrder $task, string $type, string $title, string $message, User $actor, ?string $dedupePrefix = null, array $excludeIds = []): void
    {
        $recipientIds = collect($this->departmentHeadIds($this->taskDepartmentId($task)))
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, array_map('intval', $excludeIds), true))
            ->unique()
            ->all();

        $this->notify(
            $recipientIds,
            $type,
            Str::limit(strip_tags($title), 120, ''),
            Str::limit(strip_tags($message), 1000, ''),
            $task,
            $actor,
            [],
            $dedupePrefix
        );
    }

    /**
     * แผนกที่งานนี้สังกัด — ใช้เกณฑ์เดียวกับ WorkOrderPolicy::destinationDepartmentId()
     * เพื่อไม่ให้ "แผนกที่ได้รับแจ้งเตือน" กับ "แผนกที่มีสิทธิ์ดูงาน" หลุดจากกัน
     */
    /**
     * หัวหน้าแผนกที่รับผิดชอบงานชิ้นนี้
     *
     * เปิดเป็น public เพราะเหตุการณ์ที่หัวหน้าต้องรู้ไม่ได้อยู่ในบริการนี้ทั้งหมด
     * เช่นงานเลยกำหนดถูกสร้างจาก NotificationMaintenanceService และคำขอลบงานจาก TaskController
     *
     * @return array<int>
     */
    public function departmentHeadIdsForTask(WorkOrder $task): array
    {
        return $this->departmentHeadIds($this->taskDepartmentId($task));
    }

    private function taskDepartmentId(WorkOrder $task): ?int
    {
        return $task->department_id ? (int) $task->department_id : $task->user?->department_id;
    }

    /** @return array<int> */
    private function departmentHeadIds(?int $departmentId): array
    {
        if (! $departmentId) {
            return [];
        }

        return User::query()
            ->where('role', 'user')
            ->where('is_active', true)
            ->where('is_department_head', true)
            ->where('department_id', $departmentId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * รหัสแผนกที่มีหัวหน้าแผนกใช้งานอยู่จริง
     *
     * ใช้ตอบคำถามว่า "งานใบนี้มีคนรับผิดชอบอยู่แล้วหรือยัง" ซึ่งเป็นเกณฑ์เดียวกับที่
     * departmentApprovalRecipientIds() ใช้ตัดสินว่าจะส่งคำขอไปหาหัวหน้าหรือตกไปหา admin
     *
     * คิวคำขออนุมัติของ admin กรองด้วยค่านี้ เพื่อให้ "สิ่งที่ admin เห็นในคิว" ตรงกับ
     * "สิ่งที่ admin ถูกแจ้งเตือน" พอดี — เดิมไม่ตรงกัน admin จึงเห็นคำขอทั้งระบบรวมทั้งที่
     * หัวหน้าแผนกถืออยู่แล้ว
     *
     * @return array<int>
     */
    public function departmentIdsWithActiveHead(): array
    {
        return User::query()
            ->where('role', 'user')
            ->where('is_active', true)
            ->where('is_department_head', true)
            ->whereNotNull('department_id')
            ->distinct()
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int> */
    public function departmentApprovalRecipientIds(?int $departmentId): array
    {
        if (! $departmentId) {
            return User::query()->where('role', 'admin')->where('is_active', true)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $headIds = $this->departmentHeadIds($departmentId);

        return $headIds !== []
            ? $headIds
            : User::query()->where('role', 'admin')->where('is_active', true)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * แจ้งหัวหน้าแผนกต้นสังกัดว่าลูกทีมเข้าร่วมโปรเจกต์ของแผนกอื่นแล้ว
     *
     * เป็นการ "รับทราบและติดตาม" ไม่ใช่คำขอ จึงไม่ผ่าน notifyApprovalRequest() และไม่มี
     * ปุ่มอนุมัติหรือปฏิเสธที่ปลายทาง การเข้าร่วมเกิดขึ้นเสร็จแล้วก่อนแจ้งเตือนฉบับนี้ออก
     *
     * ไม่มี fallback ไปหา admin เมื่อแผนกยังไม่มีหัวหน้า — ต่างจาก
     * departmentApprovalRecipientIds() ที่ต้องหาคนตัดสินให้ได้เสมอ เพราะที่นี่ไม่มีอะไร
     * ให้ตัดสิน ถ้าไม่มีหัวหน้าก็ไม่มีใครต้องรับทราบ และงานต้องเดินต่อได้ตามปกติ
     *
     * ส่งหาหัวหน้าทุกคนของแผนกนั้นเมื่อมีมากกว่าหนึ่งคน แต่ไม่ส่งให้ผู้เข้าร่วมเอง
     * เมื่อผู้เข้าร่วมคือหัวหน้าแผนกนั้น เพราะเขาได้ฉบับ "คุณถูกเพิ่มเข้าร่วมโปรเจกต์" ไปแล้ว
     */
    public function notifyCrossDepartmentParticipation(WorkOrder $task, User $participant, User $actor): Collection
    {
        $participant->loadMissing('department');
        $memberDepartment = $participant->department?->department_name ?? 'ไม่ระบุแผนก';
        $projectDepartment = ProjectRoles::projectDepartmentName($task) ?? 'แผนกอื่น';
        $heads = array_values(array_filter(
            $this->departmentHeadIds($participant->department_id),
            fn (int $headId) => $headId !== (int) $participant->id
        ));

        return $this->notify(
            $heads,
            'cross_department_participation',
            'พนักงานเข้าร่วมโปรเจกต์ข้ามแผนก',
            $participant->name.' · '.$memberDepartment.' ถูกเพิ่มเข้าร่วมโปรเจกต์ “'.$task->job_topic.'” ของแผนก'.$projectDepartment.' โดย '.$actor->name,
            $task,
            $actor,
            ['participant_user_id' => (int) $participant->id]
        );
    }

    public function notifyAssignmentCreated(WorkOrder $task, User $actor, User $assignee, bool $sameDepartment): void
    {
        if ($actor->role === 'admin') {
            $this->notify(
                [$assignee->id],
                'admin_created_task',
                'มีงานใหม่',
                $actor->name.' (ผู้ดูแลระบบ) มอบหมายงาน "'.$task->job_topic.'" ให้คุณ',
                $task,
                $actor,
                [],
                'assignment-created:'.$task->job_id.':recipient'
            );

            return;
        }

        if ($sameDepartment || $assignee->isDepartmentHead()) {
            $this->notify(
                [$assignee->id],
                'task_assigned',
                'มีงานใหม่',
                $actor->name.' มอบหมายงาน "'.$task->job_topic.'" ให้คุณ',
                $task,
                $actor,
                [],
                'assignment-created:'.$task->job_id.':recipient'
            );

            if ($sameDepartment) {
                // ผู้รับงานได้ฉบับ "มีงานใหม่" ไปแล้วด้านบน ไม่ต้องได้ฉบับสรุปของฝ่ายดูแลซ้ำอีก
                $this->notifyTaskOverseers(
                    $task,
                    'same_department_assignment',
                    'มีการมอบหมายงานภายในแผนก',
                    $actor->name.' มอบหมายงาน "'.$task->job_topic.'" ให้ '.$assignee->name,
                    $actor,
                    'assignment-created:'.$task->job_id.':overseers',
                    [$assignee->id]
                );
            }

            return;
        }

        $this->notifyApprovalRequest(
            $this->departmentApprovalRecipientIds($assignee->department_id),
            'cross_department_pending',
            'มีคำขอมอบหมายงานข้ามแผนกรอตรวจสอบ',
            $actor->name.' ต้องการมอบหมายงาน "'.$task->job_topic.'" ให้ '.$assignee->name.' (ต่างแผนก) กรุณาตรวจสอบและอนุมัติหรือปฏิเสธ',
            $task,
            $actor,
            null,
            'assignment-created:'.$task->job_id.':admins'
        );
    }

    public function notifyAssignmentDecision(WorkOrder $task, User $admin, string $decision): void
    {
        $task->loadMissing(['user', 'creator']);
        $admin->loadMissing('department');
        $requesterId = $task->assigned_by ?: $task->created_by;
        $approver = ApprovalPresenter::approverLabel($admin);

        if ($decision === 'approved') {
            $this->notify(
                [$task->user_id],
                'task_assigned',
                'ได้รับมอบหมายงานแล้ว',
                $approver.' อนุมัติงาน "'.$task->job_topic.'" และมอบหมายให้คุณแล้ว',
                $task,
                $admin,
                [],
                'assignment-decision:'.$task->job_id.':recipient'
            );

            $this->notify(
                [$requesterId],
                'assignment_approved',
                'อนุมัติการมอบหมายงานแล้ว',
                $approver.' อนุมัติการมอบหมายงาน "'.$task->job_topic.'" แล้ว',
                $task,
                $admin,
                [],
                'assignment-decision:'.$task->job_id.':requester'
            );

            return;
        }

        $this->notify(
            [$requesterId],
            'assignment_rejected',
            'ปฏิเสธการมอบหมายงาน',
            $approver.' ปฏิเสธการมอบหมายงาน "'.$task->job_topic.'"',
            $task,
            $admin,
            [],
            'assignment-decision:'.$task->job_id.':requester'
        );
    }

    public function notifyTaskDeleted(WorkOrder $task, string $message, User $actor): void
    {
        $task->loadMissing('collaborators');

        // งานหายไปจากแผนกต้องมีผู้รับผิดชอบรู้เสมอ และเกิดไม่บ่อยพอที่จะไม่กลายเป็นสิ่งรบกวน
        $recipientIds = collect([$task->user_id, $task->created_by, $task->leader_user_id])
            ->merge($task->collaborators->pluck('id'))
            ->merge($this->departmentHeadIdsForTask($task))
            ->filter()
            ->unique()
            ->reject(fn ($userId) => (int) $userId === (int) $actor->id)
            ->values();

        $this->notifyDetached(
            $recipientIds,
            'task_deleted',
            'งานถูกลบแล้ว',
            Str::limit(strip_tags($message), 1000, ''),
            $actor,
            ['deleted_work_order_id' => $task->job_id],
            ['work_order_list_id' => $task->work_order_list_id]
        );
    }

    public function displayCount(int $count): string
    {
        return $count > 99 ? '99+' : (string) $count;
    }

    public function unreadCount(User $user): int
    {
        return SystemNotification::forUser($user)->unread()->count();
    }

    public function dropdown(User $user): Collection
    {
        return SystemNotification::with(['actor', 'workOrder.user.department', 'project'])
            ->forUser($user)->dropdownEligible()->latest()->limit(15)->get();
    }

    public function latestId(User $user): int
    {
        return (int) SystemNotification::forUser($user)->max('id');
    }

    public function syncFeed(User $user, int $after, int $limit = 50): array
    {
        $items = SystemNotification::with(['actor', 'workOrder.user.department', 'project'])
            ->forUser($user)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $items->count() > $limit;
        $items = $items->take($limit)->values();
        // ต้องรวม task_comment_mention ด้วย ไม่ใช่แค่ task_comment ธรรมดา — คนที่ถูก @กล่าวถึง
        // ได้ notification คนละชนิดกับคนอื่นในงานเดียวกัน (ดู TaskCommentService::post()) ถ้าไม่รวม
        // event ของเขาจะไม่มี comment ติดมาด้วยเลย ทำให้ Task Workspace ที่เปิดอยู่ไม่อัปเดตสด
        // ต้องโหลดครบเท่ากับที่ TaskCommentPresenter::comment() ใช้ ไม่งั้นคนอื่นที่เห็นคอมเมนต์นี้
        // ผ่าน realtime (ไม่ใช่คนโพสต์เอง ซึ่งได้ก้อนข้อมูลตรงจาก TaskCommentController::store())
        // จะเห็นรูปหาย ข้อความที่ตอบกลับหาย หรือคนที่ถูก @กล่าวถึงหาย
        $commentIds = $items->whereIn('type', ['task_comment', 'task_comment_mention'])
            ->pluck('data')->map(fn ($data) => (int) data_get($data, 'comment_id'))
            ->filter()->unique();
        $comments = WorkOrderUpdate::with(['user', 'attachments', 'replyTo.user', 'replyTo.attachments', 'mentions'])
            ->whereIn('id', $commentIds)
            ->where('is_comment', true)->get()->keyBy('id');

        $events = $items->map(function (SystemNotification $notification) use ($comments, $user): array {
            $comment = $comments->get((int) data_get($notification->data, 'comment_id'));
            $canSeeTask = $notification->workOrder
                && Gate::forUser($user)->allows('view', $notification->workOrder);

            return [
                'id' => $notification->id,
                'type' => $notification->type,
                'category' => $notification->category,
                'title' => $notification->title,
                'message' => $notification->message,
                'url' => route('notifications.open', $notification),
                'task_id' => $canSeeTask ? $notification->work_order_id : null,
                'created_at' => $notification->created_at?->toIso8601String(),
                'relative_time' => $this->relativeTime($notification->created_at),
                'comment' => $canSeeTask && $comment && (int) $comment->work_order_id === (int) $notification->work_order_id
                    ? [
                        ...$this->commentPresenter->comment($comment, $user),
                    ]
                    : null,
            ];
        })->all();

        return [
            'cursor' => $items->last()?->id ?? $after,
            'has_more' => $hasMore,
            'unread_count' => $this->unreadCount($user),
            'events' => $events,
        ];
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return SystemNotification::with(['actor', 'workOrder.user.department', 'project'])
            ->forUser($user)->centerEligible()
            ->when(($filters['status'] ?? 'all') === 'unread', fn ($query) => $query->unread())
            ->when(in_array($filters['category'] ?? '', ['task', 'review', 'comment', 'deadline', 'meeting', 'appointment', 'worklog', 'system'], true), fn ($query) => $query->where('category', $filters['category']))
            ->when(! empty($filters['project']), fn ($query) => $query->where('work_order_list_id', $filters['project']))
            ->latest()->paginate(self::CENTER_PAGE_SIZE)->withQueryString();
    }

    public function groupLabel(CarbonInterface $createdAt, ?CarbonInterface $now = null): string
    {
        $today = ($now ?? now())->copy()->setTimezone('Asia/Bangkok')->startOfDay();
        $date = $createdAt->copy()->setTimezone('Asia/Bangkok')->startOfDay();
        $days = (int) $date->diffInDays($today);

        return match (true) {
            $date->isSameDay($today) => 'วันนี้',
            $days === 1 => 'เมื่อวาน',
            $days <= 7 => '7 วันที่ผ่านมา',
            $days <= 30 => '30 วันที่ผ่านมา',
            default => 'เก่ากว่านั้น',
        };
    }

    public function relativeTime(CarbonInterface $createdAt, ?CarbonInterface $now = null): string
    {
        $reference = $now ?? now();

        if ($createdAt->diffInSeconds($reference) < 60) {
            return 'เมื่อสักครู่';
        }

        return Str::replaceEnd('ก่อน', 'ที่แล้ว', $createdAt->copy()->locale('th')->diffForHumans($reference));
    }

    /**
     * คำขอเพิ่มงานที่ถูกอนุมัติหรือปฏิเสธแล้วไม่ใช่งานค้างของผู้พิจารณาอีกต่อไป
     * ถ้าไม่ปิดการแจ้งเตือน "มีคำขอเพิ่มงาน" ตรงนี้ ตัวนับกระดิ่งยังค้างอยู่
     * และผู้ใช้เข้าใจว่าคำขอยังรออนุมัติอยู่ทั้งที่กดพิจารณาไปแล้ว
     */
    public function resolveProjectTaskRequest(WorkOrderListTaskRequest $taskRequest): void
    {
        SystemNotification::query()
            ->where('type', 'project_task_request_submitted')
            ->where('work_order_list_id', $taskRequest->work_order_list_id)
            ->where('data->task_request_id', $taskRequest->id)
            ->unread()
            ->update(['read_at' => now(), 'is_read' => true]);
    }

    public function markRead(SystemNotification $notification, bool $read = true): void
    {
        $notification->update(['read_at' => $read ? now() : null, 'is_read' => $read]);
    }

    public function target(SystemNotification $notification, User $viewer): string
    {
        $task = $notification->workOrder;

        if ($viewer->role === 'admin' || $viewer->isDepartmentHead()) {
            if ($notification->type === 'cross_department_pending') {
                return route('admin.approvals.index', ['approval_queue' => 'assignment']);
            }

            /*
             * การรับทราบว่าลูกทีมไปร่วมโปรเจกต์ของแผนกอื่น พาไปหน้าติดตามของแผนกตัวเอง
             * ไม่ใช่คิวอนุมัติ — หัวหน้าเห็นภาพรวมทั้งทีมได้ที่เดียว และเปิดงานต่อจากที่นั่น
             * ตาม policy ปกติ (บางใบหัวหน้าต้นสังกัดดูได้เพราะมีลูกทีมอยู่ในงาน)
             */
            if ($notification->type === 'cross_department_participation') {
                return route('admin.approvals.index', ['approval_queue' => 'outgoing']);
            }
        }

        if (! $task) {
            // การประชุมไม่ผูกกับ WorkOrder เลย work_order_id จึงเป็น null เสมอ
            // ถ้าไม่ดักตรงนี้จะตกไป fallback ท้ายบล็อกแล้ววนกลับหน้าศูนย์การแจ้งเตือน
            // เทียบด้วย category ไม่ใช่รายชื่อ type เพื่อให้แจ้งเตือนประชุมชนิดใหม่ (การตอบรับ
            // เข้าร่วม — ดู MeetingAttendeeResponseService) ได้ปลายทางเดียวกันโดยไม่ต้องแก้ที่นี่
            if ($notification->category === 'meeting' && ($meeting = $this->notificationMeeting($notification))) {
                if (Gate::forUser($viewer)->allows('view', $meeting)) {
                    return route('meetings.show', $this->invitationRouteParameters($notification, ['meeting' => $meeting->id]));
                }
            }

            // นัดหมายไม่ผูกกับ WorkOrder เช่นกัน และเป็นคนละหน้าปลายทางกับการประชุม
            if ($notification->category === 'appointment' && ($appointment = $this->notificationAppointment($notification))) {
                if (Gate::forUser($viewer)->allows('view', $appointment)) {
                    return route('appointments.show', $this->invitationRouteParameters($notification, ['appointment' => $appointment->id]));
                }
            }

            // บันทึกงานประจำวันไม่ผูกกับ WorkOrder เช่นกัน และผู้รับต้องไปที่
            // ไทม์ไลน์ "ของตัวเอง" ในวันนั้น ไม่ใช่ของผู้แจ้ง เพราะแต่ละคนได้
            // รายการของตัวเองแยกแถวกัน (ดู WorkLogTemplate::participants())
            if ($notification->category === 'worklog') {
                if ($viewer->role === 'viewer') {
                    return route('notifications.index');
                }

                return route('daily-logs.index', array_filter([
                    'date' => $notification->data['work_date'] ?? null,
                ]));
            }

            // คำขอชั้นที่สองเป็นงานของหัวหน้าแผนก ปลายทางคือหน้าคำขออนุมัติหน้าเดิม
            if ($notification->type === 'share_join_awaiting_head') {
                return route('admin.approvals.index', ['approval_queue' => 'share']);
            }

            // แชร์งานไม่ผูกกับ WorkOrder เพราะผู้ขอยังไม่มีสิทธิ์ view() งานนั้นจนกว่า
            // คำขอจะผ่าน ปลายทางจึงเป็นหน้าแชร์งาน ไม่ใช่หน้างาน
            if (str_starts_with($notification->type, 'share_join_')) {
                return route('shares.index', [
                    'tab' => $notification->type === 'share_join_requested' ? 'incoming' : 'mine',
                ]);
            }

            if (str_starts_with($notification->type, 'project_task_request_')
                && $notification->project
                && Gate::forUser($viewer)->allows('view', $notification->project)) {
                return route('mytasks.index', [
                    'view' => 'board',
                    'task_request' => $notification->data['task_request_id'] ?? null,
                ]);
            }

            return route('notifications.index');
        }

        if (! Gate::forUser($viewer)->allows('view', $task)) {
            return route('notifications.index');
        }

        $query = ['open_task' => $task->job_id];
        if ($notification->category === 'comment') {
            $query['task_tab'] = 'updates';
        }

        if ($viewer->role === 'admin' && $task->user?->role === 'user' && $task->user?->department_id) {
            return route('admin.work-board.member', [
                'department' => $task->user->department_id,
                'user' => $task->user_id,
            ] + $query);
        }

        // งานของลูกทีมไม่ได้อยู่ในหน้า "งานของฉัน" ของหัวหน้า การส่งไป mytasks จึงเปิดงานไม่เจอ
        // ต้องพาไป Workspace ของสมาชิกคนนั้นซึ่งเป็นที่เดียวที่หัวหน้าเปิดงานนี้ได้จริง
        if ($task->user_id !== $viewer->id
            && $task->user?->role === 'user'
            && $viewer->overseesDepartment($task->user?->department_id)) {
            return route('work-board.member', [
                'department' => $task->user->department_id,
                'user' => $task->user_id,
                'workspace' => 1,
            ] + $query);
        }
        if ($viewer->role === 'viewer') {
            return route('tasks.show', $task->job_id);
        }

        return route('mytasks.index', $query);
    }

    public function targetUnavailable(SystemNotification $notification, User $viewer): bool
    {
        if ($notification->type === 'cross_department_pending') {
            return ! $notification->workOrder
                || ! Gate::forUser($viewer)->allows('approve', $notification->workOrder);
        }

        // หน้าติดตามงานข้ามแผนกของทีมเปิดได้เฉพาะ admin และหัวหน้าแผนก (WorkOrderPolicy::approve)
        if ($notification->type === 'cross_department_participation') {
            return $viewer->role !== 'admin' && ! $viewer->isDepartmentHead();
        }

        if (str_starts_with($notification->type, 'share_join_')) {
            $requestId = $notification->data['share_request_id'] ?? null;

            if (! $requestId || $viewer->role === 'viewer') {
                return true;
            }

            // คำขอชั้นที่สองพาไปหน้าคำขออนุมัติ ซึ่งเปิดได้เฉพาะ admin และหัวหน้าแผนก
            if ($notification->type === 'share_join_awaiting_head'
                && $viewer->role !== 'admin' && ! $viewer->isDepartmentHead()) {
                return true;
            }

            return ! WorkOrderShareRequest::query()->whereKey($requestId)->exists();
        }

        if (str_starts_with($notification->type, 'project_task_request_')) {
            $requestId = $notification->data['task_request_id'] ?? null;

            return ! $requestId
                || ! $notification->project
                || ! Gate::forUser($viewer)->allows('view', $notification->project)
                || ! WorkOrderListTaskRequest::query()
                    ->whereKey($requestId)
                    ->where('work_order_list_id', $notification->work_order_list_id)
                    // คำขอที่พิจารณาแล้วไม่มีการ์ดให้เจ้าของโปรเจกต์กดอีก ปลายทางจึงไม่มีอะไรให้ดู
                    ->when($notification->type === 'project_task_request_submitted', fn ($query) => $query->where('status', 'pending'))
                    ->exists();
        }

        if ($notification->category === 'meeting') {
            $meeting = $this->notificationMeeting($notification);

            return ! $meeting || ! Gate::forUser($viewer)->allows('view', $meeting);
        }

        if ($notification->category === 'appointment') {
            $appointment = $this->notificationAppointment($notification);

            return ! $appointment || ! Gate::forUser($viewer)->allows('view', $appointment);
        }

        if (! $notification->work_order_id) {
            return false;
        }

        return ! $notification->workOrder
            || ! Gate::forUser($viewer)->allows('view', $notification->workOrder);
    }

    /**
     * ตาราง system_notifications ไม่มีคอลัมน์ meeting_id รหัสประชุมจึงอยู่ใน data (JSON)
     * Meeting ไม่ใช้ SoftDeletes ประชุมที่ถูกลบแล้วจะคืน null ตามที่ต้องการ
     */
    private function notificationMeeting(SystemNotification $notification): ?Meeting
    {
        $meetingId = $notification->data['meeting_id'] ?? null;

        return $meetingId ? Meeting::find($meetingId) : null;
    }

    /** นัดหมายใช้ SoftDeletes ใบที่ถูกลบแล้วจะคืน null ตามที่ต้องการ */
    private function notificationAppointment(SystemNotification $notification): ?Appointment
    {
        $appointmentId = $notification->data['appointment_id'] ?? null;

        return $appointmentId ? Appointment::find($appointmentId) : null;
    }

    /**
     * แจ้งเตือนที่ "ขอคำตอบ" พาผู้ใช้ไปที่หน้ารายละเอียดพร้อมธง respond=1
     *
     * หน้ารายละเอียดจะเปิดกล่องยืนยันให้ทันทีเมื่อเห็นธงนี้และผู้ใช้ยังตอบได้จริง
     * (ดู meetings/show.blade.php) การกดเข้าหน้าเองไม่มีธงนี้จึงไม่มีกล่องเด้ง
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function invitationRouteParameters(SystemNotification $notification, array $parameters): array
    {
        $asksForAnswer = str_ends_with($notification->type, '_scheduled')
            || str_ends_with($notification->type, '_reinvited');

        return $asksForAnswer ? $parameters + ['respond' => 1] : $parameters;
    }
}
