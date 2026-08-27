<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'assigned_by',
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
        'paused_at',
        'late_at',
        'submitted_for_review_by',
        'submitted_for_review_at',
        'final_approved_by',
        'final_approved_at',
        'review_return_reason',
    ];

    protected function casts(): array
    {
        return [
            'job_start_at' => 'datetime',
            'job_due_at' => 'datetime',
            'job_completed_at' => 'datetime',
            'paused_at' => 'datetime',
            'late_at' => 'datetime',
            'approved_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'final_approved_at' => 'datetime',
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

    public function reviewSubmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_for_review_by');
    }

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_approved_by');
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
            ->withPivot('added_by', 'decided_by', 'status', 'responded_at')
            ->withTimestamps();
    }

    public function scopeInvolving(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where(function (Builder $approved) use ($user): void {
                $approved->where('approval_status', 'approved')
                    ->where(function (Builder $participant) use ($user): void {
                        $participant->where('user_id', $user->id)
                            ->orWhere('created_by', $user->id)
                            ->orWhere('leader_user_id', $user->id)
                            ->orWhereHas('collaborators', fn (Builder $collaborators) => $collaborators
                                ->where('users.id', $user->id)
                                ->where('work_order_collaborators.status', 'accepted'));
                    });
            })->orWhere(function (Builder $unapproved) use ($user): void {
                $unapproved->where('approval_status', '!=', 'approved')
                    ->where(function (Builder $requester) use ($user): void {
                        $requester->where('created_by', $user->id)
                            ->orWhere('assigned_by', $user->id)
                            ->orWhere('leader_user_id', $user->id);
                    });
            });
        });
    }

    public function scopeVisibleInProjectsFor(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->involving($user)
                ->orWhere(function (Builder $projectTasks) use ($user): void {
                    $projectTasks->where('approval_status', 'approved')
                        ->whereIn('work_order_list_id', WorkOrder::query()
                            ->select('work_order_list_id')
                            ->where('approval_status', 'approved')
                            ->whereNotNull('work_order_list_id')
                            ->whereHas('collaborators', fn (Builder $collaborators) => $collaborators
                                ->where('users.id', $user->id)
                                ->where('work_order_collaborators.status', 'accepted')));
                });
        });
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
