<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderListTaskRequest;
use App\Models\WorkOrderUpdate;
use App\Support\TaskCommentPresenter;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private readonly TaskCommentPresenter $commentPresenter) {}

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

        return $dedupeKey
            ? SystemNotification::firstOrCreate(['user_id' => $attributes['user_id'], 'dedupe_key' => $dedupeKey], $attributes)
            : SystemNotification::create($attributes);
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
     * ผู้ดูแลของงานนี้ = ผู้ดูแลระบบ + หัวหน้าแผนกปลายทาง
     *
     * เดิมส่งเฉพาะ role = 'admin' หัวหน้าแผนกจึงไม่เคยรู้เลยว่าลูกทีมมอบหมายงานกันเอง
     * ทั้งที่เป็นคนที่ต้องติดตามภาระงานของแผนกโดยตรง
     *
     * @param  array<int>  $excludeIds  คนที่ได้รับแจ้งเตือนฉบับของตัวเองไปแล้ว เช่นผู้รับงาน
     */
    public function notifyTaskAdmins(WorkOrder $task, string $type, string $title, string $message, User $actor, ?string $dedupePrefix = null, array $excludeIds = []): void
    {
        $recipientIds = collect(User::where('role', 'admin')->pluck('id'))
            ->merge($this->departmentHeadIds($this->taskDepartmentId($task)))
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

    public function notifyAssignmentCreated(WorkOrder $task, User $actor, User $assignee, bool $sameDepartment): void
    {
        if ($actor->role === 'admin') {
            $this->notify(
                [$assignee->id],
                'admin_created_task',
                'มีงานใหม่',
                'ผู้ดูแลระบบมอบหมายงาน "'.$task->job_topic.'" ให้คุณ',
                $task,
                $actor,
                [],
                'assignment-created:'.$task->job_id.':recipient'
            );

            return;
        }

        if ($sameDepartment) {
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

            // ผู้รับงานได้ฉบับ "มีงานใหม่" ไปแล้วด้านบน ไม่ต้องได้ฉบับสรุปของฝ่ายดูแลซ้ำอีก
            $this->notifyTaskAdmins(
                $task,
                'same_department_assignment',
                'มีการมอบหมายงานภายในแผนก',
                $actor->name.' มอบหมายงาน "'.$task->job_topic.'" ให้ '.$assignee->name,
                $actor,
                'assignment-created:'.$task->job_id.':admins',
                [$assignee->id]
            );

            return;
        }

        $this->notify(
            $this->departmentApprovalRecipientIds($assignee->department_id),
            'cross_department_pending',
            'มีคำขอมอบหมายงานข้ามแผนกรอตรวจสอบ',
            $actor->name.' ต้องการมอบหมายงาน "'.$task->job_topic.'" ให้ '.$assignee->name.' (ต่างแผนก) กรุณาตรวจสอบและอนุมัติหรือปฏิเสธ',
            $task,
            $actor,
            [],
            'assignment-created:'.$task->job_id.':admins'
        );
    }

    public function notifyAssignmentDecision(WorkOrder $task, User $admin, string $decision): void
    {
        $task->loadMissing(['user', 'creator']);
        $requesterId = $task->assigned_by ?: $task->created_by;

        if ($decision === 'approved') {
            $this->notify(
                [$task->user_id],
                'task_assigned',
                'ได้รับมอบหมายงานแล้ว',
                'ผู้ดูแลระบบอนุมัติงาน "'.$task->job_topic.'" และมอบหมายให้คุณแล้ว',
                $task,
                $admin,
                [],
                'assignment-decision:'.$task->job_id.':recipient'
            );

            $this->notify(
                [$requesterId],
                'assignment_approved',
                'อนุมัติการมอบหมายงานแล้ว',
                'ผู้ดูแลระบบอนุมัติการมอบหมายงาน "'.$task->job_topic.'" แล้ว',
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
            'ผู้ดูแลระบบปฏิเสธการมอบหมายงาน "'.$task->job_topic.'"',
            $task,
            $admin,
            [],
            'assignment-decision:'.$task->job_id.':requester'
        );
    }

    public function notifyTaskDeleted(WorkOrder $task, string $message, User $actor): void
    {
        $task->loadMissing('collaborators');

        $recipientIds = collect([$task->user_id, $task->created_by, $task->leader_user_id])
            ->merge($task->collaborators->pluck('id'))
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
        $commentIds = $items->where('type', 'task_comment')
            ->pluck('data')->map(fn ($data) => (int) data_get($data, 'comment_id'))
            ->filter()->unique();
        $comments = WorkOrderUpdate::with('user')->whereIn('id', $commentIds)
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
            ->when(in_array($filters['category'] ?? '', ['task', 'review', 'comment', 'deadline', 'system'], true), fn ($query) => $query->where('category', $filters['category']))
            ->when(! empty($filters['project']), fn ($query) => $query->where('work_order_list_id', $filters['project']))
            ->latest()->paginate(25)->withQueryString();
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

    public function markRead(SystemNotification $notification, bool $read = true): void
    {
        $notification->update(['read_at' => $read ? now() : null, 'is_read' => $read]);
    }

    public function target(SystemNotification $notification, User $viewer): string
    {
        $task = $notification->workOrder;

        if (($viewer->role === 'admin' || $viewer->isDepartmentHead()) && in_array($notification->type, [
            'cross_department_pending',
            'collaborator_approval_request',
        ], true)) {
            return route('admin.approvals.index', [
                'approval_queue' => $notification->type === 'collaborator_approval_request'
                    ? 'collaborator'
                    : 'assignment',
            ]);
        }

        if (! $task) {
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
        if (str_starts_with($notification->type, 'project_task_request_')) {
            $requestId = $notification->data['task_request_id'] ?? null;

            return ! $requestId
                || ! $notification->project
                || ! Gate::forUser($viewer)->allows('view', $notification->project)
                || ! WorkOrderListTaskRequest::query()
                    ->whereKey($requestId)
                    ->where('work_order_list_id', $notification->work_order_list_id)
                    ->exists();
        }

        if (! $notification->work_order_id) {
            return false;
        }

        return ! $notification->workOrder
            || ! Gate::forUser($viewer)->allows('view', $notification->workOrder);
    }
}
