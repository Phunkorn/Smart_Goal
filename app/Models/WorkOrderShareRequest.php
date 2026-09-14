<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * คำขอเข้าร่วมงานที่ถูกแชร์
 *
 * มีสองชั้นการอนุมัติ ชั้นแรกคือผู้แชร์ (status ในแถวนี้) ชั้นที่สองคือหัวหน้าแผนก
 * ของผู้ขอ ซึ่งเกิดเฉพาะคำขอข้ามแผนกและถูกจัดการโดยคิวคำขออนุมัติเดิมทั้งหมด
 * ผลของชั้นที่สองสะท้อนอยู่ใน collaborator_status
 */
class WorkOrderShareRequest extends Model
{
    protected $fillable = [
        'work_order_share_id',
        'requester_id',
        'status',
        'decided_by',
        'decided_at',
        'head_decided_by',
        'head_decided_at',
        'decision_reason',
        'collaborator_status',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'head_decided_at' => 'datetime',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(WorkOrderShare::class, 'work_order_share_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** หัวหน้าแผนกที่ตัดสินชั้นที่สอง — ว่างเมื่อคำขอจบตั้งแต่ชั้นผู้แชร์ */
    public function headDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_decided_by');
    }

    /** คำขอที่ผู้แชร์อนุมัติแล้วและกำลังรอหัวหน้าแผนกของผู้แชร์ตัดสิน */
    public function scopeAwaitingHead(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_head');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * ผู้แชร์อนุมัติแล้ว แต่ยังเข้าร่วมไม่ได้เพราะรอหัวหน้าแผนกของผู้แชร์อีกชั้น
     *
     * สถานะนี้คือสิ่งที่หน้าจอต้องพูดให้ชัด ไม่อย่างนั้นผู้ขอจะเห็นว่าอนุมัติแล้ว
     * แต่เปิดงานไม่เจอ
     */
    public function awaitsDepartmentHead(): bool
    {
        return $this->status === 'awaiting_head';
    }
}
