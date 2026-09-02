<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'department_id',
        'role',
        'is_department_head',
        'must_change_password',
        'is_active',
        'profile_image',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'is_department_head' => 'boolean',
        ];
    }

    public function isDepartmentHead(): bool
    {
        return $this->role === 'user'
            && $this->is_active
            && $this->is_department_head
            && $this->department_id !== null;
    }

    public function overseesDepartment(int|string|null $departmentId): bool
    {
        return $this->isDepartmentHead()
            && $departmentId !== null
            && (int) $this->department_id === (int) $departmentId;
    }

    public static function normalizeUsername(mixed $username): string
    {
        return Str::lower(trim((string) $username));
    }

    public function setUsernameAttribute(mixed $username): void
    {
        $this->attributes['username'] = self::normalizeUsername($username);
    }

    public function jobs()
    {
        return $this->hasMany(WorkOrder::class, 'user_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'user_id');
    }

    public function createdJobs(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'created_by');
    }

    public function leadingJobs(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'leader_user_id');
    }

    public function joinedJobs(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_collaborators', 'user_id', 'work_order_id')
            ->withPivot('added_by', 'decided_by', 'status', 'responded_at')
            ->withTimestamps();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function taskLists(): HasMany
    {
        return $this->hasMany(WorkOrderList::class);
    }

    public function requestedProjectTasks(): HasMany
    {
        return $this->hasMany(WorkOrderListTaskRequest::class, 'requester_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SystemNotification::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function createdMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'created_by');
    }

    public function attendedMeetings(): BelongsToMany
    {
        return $this->belongsToMany(Meeting::class, 'meeting_attendees')->withTimestamps();
    }

    public function trashLogs(): HasMany
    {
        return $this->hasMany(TrashLog::class, 'deleted_by');
    }
}
