<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemNotification extends Model
{
    public const TYPE_CATEGORIES = [
        'submitted_for_review' => 'review',
        'review_approved' => 'review',
        'review_returned' => 'review',
        'task_reopened' => 'review',
        'self_closed' => 'review',
        'task_comment' => 'comment',
        'deadline_due_today' => 'deadline',
        'deadline_overdue' => 'deadline',
        'task_assigned' => 'task',
        'admin_created_task' => 'task',
        'same_department_assignment' => 'task',
        'cross_department_pending' => 'task',
        'assignment_approved' => 'task',
        'assignment_rejected' => 'task',
        'admin_approval' => 'task',
        'collaborator_added' => 'task',
        'collaborator_removed' => 'task',
        'collaborator_approval_request' => 'task',
        'collaborator_approved' => 'task',
        'collaborator_rejected' => 'task',
        'project_task_request_submitted' => 'task',
        'project_task_request_approved' => 'task',
        'project_task_request_rejected' => 'task',
        // แชร์งาน — อยู่หมวด task เพราะปลายทางคือการเป็นผู้ร่วมงานใน WorkOrder เหมือนกัน
        'share_join_requested' => 'task',
        'share_join_awaiting_head' => 'task',
        'share_join_approved' => 'task',
        'share_join_rejected' => 'task',
        'delete_request' => 'task',
        'delete_request_rejected' => 'task',
        'task_deleted' => 'task',
        'meeting_scheduled' => 'meeting',
        // บันทึกงานประจำวัน — แยกหมวดของตัวเองเพราะไม่ผูกกับ WorkOrder เลย
        // การยัดไว้ในหมวด task จะทำให้ตัวกรอง "เกี่ยวกับงาน" ปนงานสองระบบเข้าด้วยกัน
        'work_log_participant_added' => 'worklog',
        'work_log_routine_assigned' => 'worklog',
        'work_log_routine_start_late' => 'worklog',
        'work_log_routine_overdue' => 'worklog',
        'work_log_routine_day_pending' => 'worklog',
        'system' => 'system',
    ];

    protected $fillable = [
        'user_id',
        'actor_user_id',
        'work_order_id',
        'work_order_list_id',
        'type',
        'category',
        'title',
        'message',
        'data',
        'dedupe_key',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id', 'job_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeDropdownEligible(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('read_at')->orWhere('created_at', '>=', now()->subDays(7)));
    }

    public function scopeCenterEligible(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('read_at')->orWhere('created_at', '>=', now()->subDays(90)));
    }

    protected static function booted(): void
    {
        static::creating(function (SystemNotification $notification): void {
            $notification->category ??= self::categoryForType($notification->type);
            if (! $notification->work_order_list_id && $notification->work_order_id) {
                $notification->work_order_list_id = WorkOrder::where('job_id', $notification->work_order_id)->value('work_order_list_id');
            }
            $notification->is_read = (bool) $notification->read_at;
        });
    }

    public static function categoryForType(string $type): string
    {
        return self::TYPE_CATEGORIES[$type] ?? 'system';
    }
}
