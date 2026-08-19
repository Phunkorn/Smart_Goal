<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Carbon\CarbonInterface;

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

    public function markRead(SystemNotification $notification, bool $read = true): void
    {
        $notification->update(['read_at' => $read ? now() : null, 'is_read' => $read]);
    }

    public function target(SystemNotification $notification, User $viewer): string
    {
        $task = $notification->workOrder;
        if (! $task || ! Gate::forUser($viewer)->allows('view', $task)) return route('notifications.index');

        $query = ['open_task' => $task->job_id];
        if ($notification->category === 'comment') $query['task_tab'] = 'updates';

        if ($viewer->role === 'admin' && $task->user?->role === 'user' && $task->user?->department_id) {
            return route('admin.work-board.member', [
                'department' => $task->user->department_id,
                'user' => $task->user_id,
            ] + $query);
        }
        if ($viewer->role === 'viewer') return route('tasks.show', $task->job_id);

        return route('mytasks.index', $query);
    }
}
