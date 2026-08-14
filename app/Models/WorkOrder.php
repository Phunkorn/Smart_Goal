<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $table = 'work_orders';   // <- แก้ตรงนี้

    protected $primaryKey = 'job_id';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'created_by',
        'leader_user_id',
        'department_id',
        'work_order_list_id',
        'job_topic',
        'job_details',
        'job_priority',
        'job_status',
        'approval_status',
        'approved_by',
        'approved_at',
        'delete_requested_by',
        'delete_requested_at',
        'delete_request_reason',
        'job_progress',
        'job_start_at',
        'job_due_at',
        'job_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'job_start_at' => 'datetime',
            'job_due_at' => 'datetime',
            'job_completed_at' => 'datetime',
            'approved_at' => 'datetime',
            'delete_requested_at' => 'datetime',

        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WorkOrder $workOrder): void {
            if ((int) $workOrder->job_status === 4) {
                $workOrder->job_progress = 100;
                $workOrder->job_completed_at ??= now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(JobImage::class, 'job_id', 'job_id');
    }

    public function attachments(): HasMany
    {
        return $this->images();
    }

    public function updates(): HasMany
    {
        return $this->hasMany(WorkOrderUpdate::class, 'work_order_id', 'job_id')->latest();
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('created_at');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(WorkOrderSubtask::class, 'work_order_id', 'job_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function deleteRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delete_requested_by');
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_order_collaborators', 'work_order_id', 'user_id')
            ->withPivot('added_by', 'status', 'responded_at')
            ->withTimestamps();
    }

    protected function progressFromSubtasks(): Attribute
    {
        return Attribute::get(function (): int {
            if ((int) $this->job_status === 4) {
                return 100;
            }

            $subtasks = $this->relationLoaded('subtasks')
                ? $this->subtasks
                : $this->subtasks()->get();

            if ($subtasks->isEmpty()) {
                return (int) $this->job_progress;
            }

            return (int) round(($subtasks->where('is_completed', true)->count() / $subtasks->count()) * 100);
        });
    }
}
