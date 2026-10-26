<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'task_visibility_scope',
        'must_change_password',
        'is_active',
        'profile_image',
        'telegram_chat_id',
        'telegram_username',
        'telegram_linked_at',
        'telegram_notifications_enabled',
    ];

    /**
     * ค่าตั้งต้นของขอบเขตการมองเห็นงาน — ต้องมีที่ชั้นโมเดลด้วย ไม่ใช่แค่ default ของคอลัมน์
     *
     * default ของฐานข้อมูลมีผลตอน INSERT เท่านั้น instance ที่เพิ่งสร้างจึงถือค่า null
     * อยู่จนกว่าจะ refresh() และโค้ดที่อ่านค่าทันทีจะเจอ null ไม่ใช่ 'involved'
     * ประกาศไว้ที่นี่ทำให้ทุก instance ตอบเหมือนกันเสมอ
     */
    protected $attributes = [
        'task_visibility_scope' => 'involved',
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
            'telegram_chat_id' => 'integer',
            'telegram_linked_at' => 'datetime',
            'telegram_notifications_enabled' => 'boolean',
        ];
    }

    /**
     * รับแจ้งเตือนทาง Telegram ได้จริงหรือไม่
     *
     * viewer เป็นสิทธิ์อ่านอย่างเดียวและไม่เคยเป็นผู้รับการแจ้งเตือนในระบบอยู่แล้ว
     * (ดู NotificationService::notify) จึงกันไว้ที่นี่ด้วยเพื่อให้ช่องทางใหม่
     * ไม่กลายเป็นทางลัดข้ามกติกาเดิม
     */
    public function receivesTelegramNotifications(): bool
    {
        return $this->is_active
            && $this->role !== 'viewer'
            && $this->telegram_chat_id !== null
            && $this->telegram_notifications_enabled;
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

    /**
     * แผนกที่ admin อนุญาตให้ผู้ใช้คนนี้ "ดูงาน" ได้ — ใช้กับโหมด custom เท่านั้น
     *
     * เป็นสิทธิ์อ่านล้วน ๆ ไม่ใช่การเป็นสมาชิกแผนก แผนกต้นสังกัดยังอยู่ที่
     * users.department_id เพียงที่เดียว และความเป็น "ผู้มีส่วนร่วม" ของงานยังอ่านจาก
     * App\Support\TaskParticipation ที่เดียวตามเดิม ไม่ถูกแตะที่นี่
     *
     * โหมด own_department ไม่เก็บแถวที่นี่โดยตั้งใจ — ดู
     * App\Support\DepartmentTaskVisibility::departmentIdsFor()
     */
    public function visibleDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'user_task_visibility_departments')
            ->withTimestamps();
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

    /** Override สิทธิ์ agent ของ Smart Ticket — ดู App\Support\TicketAgentAccess */
    public function ticketAgent(): HasOne
    {
        return $this->hasOne(TicketAgent::class);
    }
}
