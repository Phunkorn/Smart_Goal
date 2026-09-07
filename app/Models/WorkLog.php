<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * บันทึกงานประจำวันหนึ่งรายการ (งานประจำ / งานแทรก / งานนอกสถานที่)
 *
 * ตั้งใจไม่มี scope สำหรับสิทธิ์การมองเห็นอยู่ในโมเดล เพื่อให้กติกาว่าใครเห็นอะไร
 * มีแหล่งเดียวคือ App\Policies\WorkLogPolicy (สำหรับรายการเดี่ยว) และ
 * App\Services\WorkLogQueryService (สำหรับ query ระดับ SQL) การใส่ scope ไว้ที่นี่
 * ด้วยจะกลายเป็นแหล่งความจริงที่สอง ซึ่งจะเพี้ยนออกจากกันเมื่อกติกาเปลี่ยน
 *
 * duration_minutes, started_at, ended_at, open_timer_owner_id และ auto_closed_at
 * ต้องเขียนผ่าน App\Services\WorkLogService เท่านั้น ไม่ผูกค่าจาก request โดยตรง
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
        'started_at',
        'ended_at',
        'duration_minutes',
        'open_timer_owner_id',
        'auto_closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'auto_closed_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
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

    public function isRunning(): bool
    {
        return $this->open_timer_owner_id !== null;
    }
}
