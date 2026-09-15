<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * แม่แบบงานประจำ — ระบบสร้างรายการของแต่ละวันจากแม่แบบนี้ให้อัตโนมัติ
 *
 * แม่แบบมีเจ้าของหนึ่งคน (user_id) ซึ่งเป็นคนเดียวที่แก้ไขหรือลบได้ และมี
 * "ผู้ร่วมงาน" ได้อีกหลายคน (work_log_template_participants) ซึ่งจะได้รายการ
 * ของแม่แบบนี้ในไทม์ไลน์ของตัวเองทุกวันที่ถึงกำหนดเช่นกัน
 *
 * รายการของแต่ละคนเป็นคนละแถวใน work_logs เสมอ เพราะ unique index
 * work_logs_template_day_unique คือ (template, user, วัน) การยืนยันว่าทำแล้ว
 * จึงเป็นของแต่ละคน ไม่ใช่การกดแทนกัน
 */
class WorkLogTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'work_log_category_id',
        'work_order_list_id',
        'job_id',
        'kind',
        'title',
        'details',
        'weekday_mask',
        'default_start_time',
        'default_duration_minutes',
        'starts_on',
        'ends_on',
        'last_materialized_on',
        'is_active',
        'accountable_from',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weekday_mask' => 'integer',
            'default_duration_minutes' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'last_materialized_on' => 'date',
            'is_active' => 'boolean',
            // เวลาที่เริ่มนับวันค้างย้อนหลัง (ดู RoutineAccountabilityService) ว่าง = ใช้ created_at
            'accountable_from' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkLogCategory::class, 'work_log_category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'job_id', 'job_id')->withTrashed();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /**
     * คนอื่นที่ต้องทำงานประจำนี้ด้วย
     *
     * แต่ละคนได้รายการของตัวเองในไทม์ไลน์ของตัวเอง และยืนยันว่าทำแล้วด้วยตัวเอง
     * เจ้าของแม่แบบยังเป็นคนเดียวที่แก้ไขหรือลบการตั้งค่าได้
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_log_template_participants', 'work_log_template_id', 'user_id')
            ->withPivot(['added_by'])
            ->withTimestamps();
    }

    /**
     * ช่วงเวลาที่ตั้งไว้ว่าต้องเข้าไปทำ เช่น "08:30 - 08:50"
     *
     * ประกอบจากเวลาเริ่มกับจำนวนนาทีที่ตั้งไว้ ไม่เก็บเวลาสิ้นสุดเป็นคอลัมน์แยก
     * เพื่อไม่ให้มีแหล่งความจริงสองที่ที่เพี้ยนออกจากกันได้
     */
    public function plannedWindowLabel(): ?string
    {
        if ($this->default_start_time === null) {
            return null;
        }

        $start = Carbon::createFromFormat('H:i:s', $this->normalizedStartTime());

        if ($this->default_duration_minutes === null) {
            return $start->format('H:i');
        }

        return $start->format('H:i').' - '.$start->copy()->addMinutes($this->default_duration_minutes)->format('H:i');
    }

    /**
     * เวลาเริ่มในรูปแบบ H:i:s เสมอ
     *
     * MySQL คืนค่าเป็น "08:30:00" ส่วน SQLite ที่ใช้ในเทสต์คืนค่าตามที่เขียนลงไป
     * ซึ่งอาจเป็น "08:30" การเทียบรูปแบบจึงต้องทำที่เดียวตรงนี้
     */
    public function normalizedStartTime(): string
    {
        $value = (string) $this->default_start_time;

        return mb_substr($value, 0, 5).':00';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
