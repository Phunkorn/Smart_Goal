<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderListTaskRequest;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class NotificationService
{
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

    public function notifyTaskAdmins(WorkOrder $task, string $type, string $title, string $message, User $actor, ?string $dedupePrefix = null): void
    {
        $adminIds = User::where('role', 'admin')->pluck('id')->all();

        $this->notify(
            $adminIds,
            $type,
            Str::limit(strip_tags($title), 120, ''),
            Str::limit(strip_tags($message), 1000, ''),
            $task,
            $actor,
            [],
            $dedupePrefix
        );
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

            $this->notifyTaskAdmins(
                $task,
                'same_department_assignment',
                'มีการมอบหมายงานภายในแผนก',
                $actor->name.' มอบหมายงาน "'.$task->job_topic.'" ให้ '.$assignee->name,
                $actor,
                'assignment-created:'.$task->job_id.':admins'
            );

            return;
        }

        $this->notifyTaskAdmins(
            $task,
            'cross_department_pending',
            'มีคำขอมอบหมายงานข้ามแผนกรอตรวจสอบ',
            $actor->name.' ต้องการมอบหมายงาน "'.$task->job_topic.'" ให้ '.$assignee->name.' (ต่างแผนก) กรุณาตรวจสอบและอนุมัติหรือปฏิเสธ',
            $actor,
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

        if ($viewer->role === 'admin' && in_array($notification->type, [
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
