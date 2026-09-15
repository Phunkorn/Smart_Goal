<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * บันทึกงานประจำวันหนึ่งรายการ (งานประจำ / งานนอกสถานที่)
 *
 * ตั้งใจไม่มี scope สำหรับสิทธิ์การมองเห็นอยู่ในโมเดล เพื่อให้กติกาว่าใครเห็นอะไร
 * มีแหล่งเดียวคือ App\Policies\WorkLogPolicy (สำหรับรายการเดี่ยว) และ
 * App\Services\WorkLogQueryService (สำหรับ query ระดับ SQL) การใส่ scope ไว้ที่นี่
 * ด้วยจะกลายเป็นแหล่งความจริงที่สอง ซึ่งจะเพี้ยนออกจากกันเมื่อกติกาเปลี่ยน
 *
 * duration_minutes, started_at, ended_at และ auto_closed_at ต้องเขียนผ่าน
 * App\Services\WorkLogService เท่านั้น ไม่ผูกค่าจาก request โดยตรง
 *
 * open_timer_owner_id เป็นคอลัมน์ที่เหลือจากระบบจับเวลาที่ถูกถอดออกไปแล้ว
 * คอลัมน์ยังอยู่เพื่อไม่ให้ข้อมูลเดิมหาย และถูกเคลียร์โดย
 * WorkLogService::closeLeftoverTimers() เท่านั้น ห้ามเขียนค่าใหม่ลงไปอีก
 */
class WorkLog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'department_id',
        'work_log_category_id',
        'work_log_template_id',
        'work_order_list_id',
        'job_id',
        'kind',
        'status',
        'source',
        'title',
        'details',
        'location',
        'requester_name',
        'work_date',
        'planned_start_at',
        'planned_end_at',
        'started_at',
        'ended_at',
        'duration_minutes',
        'late_start_reason',
        'late_completion_reason',
        'has_issue',
        'issue_details',
        'shared_from_work_log_id',
        'skip_reason',
        'skipped_at',
        'unfinished_reason',
        'absent_marked_by',
        'absent_marked_at',
        'cutoff_closed_at',
        'explained_at',
        'open_timer_owner_id',
        'auto_closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'skipped_at' => 'datetime',
            'auto_closed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'has_issue' => 'boolean',
            'absent_marked_at' => 'datetime',
            'cutoff_closed_at' => 'datetime',
            'explained_at' => 'datetime',
        ];
    }

    /** ผู้ร่วมงานที่ระบุตอนกดเริ่มว่าเจ้าของรายการนี้ไม่มา (สถานะ absent) */
    public function absentMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'absent_marked_by');
    }

    /**
     * รายการต้นฉบับของคนที่สร้างงานนอกสถานที่ แล้วเพิ่มเจ้าของรายการนี้เข้าร่วม
     */
    public function sharedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shared_from_work_log_id');
    }

    /**
     * สำเนาของผู้ร่วมงาน — ดู WorkLogParticipantService::syncSharedCopies()
     */
    public function sharedCopies(): HasMany
    {
        return $this->hasMany(self::class, 'shared_from_work_log_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkLogCategory::class, 'work_log_category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkLogTemplate::class, 'work_log_template_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    /**
     * งานโครงการที่ผูกไว้ (ถ้ามี)
     *
     * ใช้ withTrashed() เพราะ work_orders เป็น soft delete การลบงานไม่ควรทำให้
     * บันทึกเวลาทำงานที่เกิดขึ้นจริงหายไปด้วย ฝั่งแสดงผลต้องรองรับกรณีงานถูกลบ
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'job_id', 'job_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkLogAttachment::class);
    }

    /**
     * คนอื่นที่ทำงานชิ้นนี้ด้วยกัน
     *
     * ไม่รวมเจ้าของบันทึก (user_id) ซึ่งเป็นคนละแนวคิดกัน เจ้าของคือผู้บันทึกและ
     * เป็นผู้เดียวที่แก้ไขได้ ส่วนผู้ร่วมงานคือคนที่อยู่หน้างานด้วยกัน
     *
     * ต่างจากผู้ร่วมงานของงานโครงการตรงที่ไม่มีสถานะรออนุมัติ เพราะบันทึกงาน
     * ประจำวันเป็นการบันทึกสิ่งที่เกิดขึ้นไปแล้ว ไม่ใช่การมอบหมายงาน
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_log_participants', 'work_log_id', 'user_id')
            ->withPivot(['added_by'])
            ->withTimestamps();
    }
}
