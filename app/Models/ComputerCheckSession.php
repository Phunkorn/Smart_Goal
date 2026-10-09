<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * รอบการตรวจเช็กคอมพิวเตอร์หนึ่งรอบ = แบบฟอร์มกระดาษหนึ่งใบ
 *
 * ตั้งใจไม่มี scope สำหรับสิทธิ์การมองเห็นอยู่ในโมเดล ด้วยเหตุผลเดียวกับ WorkLog
 * คือให้กติกาว่าใครเห็นอะไรมีแหล่งเดียวคือ App\Policies\ComputerCheckSessionPolicy
 *
 * started_at, completed_at และ status ต้องเขียนผ่าน
 * App\Services\ComputerCheckService เท่านั้น ไม่ผูกค่าจาก request โดยตรง
 * เพราะเวลาที่ใช้ตรวจถูกนำไปเขียนเป็นเวลาของบันทึกงานประจำวันต่อ
 */
class ComputerCheckSession extends Model
{
    protected $fillable = [
        'work_log_id',
        'department_id',
        'check_date',
        'shift',
        'checker_1_id',
        'checker_2_id',
        'started_at',
        'completed_at',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'check_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(ComputerCheckResult::class, 'computer_check_session_id');
    }

    public function workLog(): BelongsTo
    {
        return $this->belongsTo(WorkLog::class, 'work_log_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function checkerOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_1_id');
    }

    public function checkerTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_2_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** ผู้ตรวจทั้งสองคนของรอบนี้ — ใช้ตัดสินสิทธิ์แก้ไขและแสดงชื่อบนแบบตรวจ */
    public function checkerIds(): array
    {
        return array_values(array_filter([
            $this->checker_1_id === null ? null : (int) $this->checker_1_id,
            $this->checker_2_id === null ? null : (int) $this->checker_2_id,
        ]));
    }

    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('check_date')->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * รอบของวัน+กะ "ของแผนกหนึ่ง" — แหล่งความจริงเดียวของการค้นหารอบ
     *
     * ต้องมีแผนกเสมอ เพราะสิทธิ์ใช้งานฟังก์ชันนี้เป็นรายแผนก
     * (App\Support\ComputerCheckAccess) ใบตรวจจึงเป็นของแผนก ไม่ใช่ของทั้งระบบ
     * ถ้าค้นหาแบบไม่ระบุแผนก แผนกที่กดเริ่มรอบเป็นคนที่สองจะได้ใบของแผนกแรก
     * กลับมา ซึ่งตัวเองไม่ได้เป็นผู้ตรวจ จึงติ๊กผลไม่ได้เลยและไม่มีข้อความบอกว่าทำไม
     */
    public function scopeForDayShift(
        Builder $query,
        string $date,
        string $shift,
        int|string|null $departmentId
    ): Builder {
        return $query
            ->whereDate('check_date', $date)
            ->where('shift', $shift)
            ->where('department_id', $departmentId);
    }
}
