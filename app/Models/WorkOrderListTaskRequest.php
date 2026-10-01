<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เดิมคือคำขอที่ต้องรอเจ้าของโปรเจกต์อนุมัติ แต่ตอนนี้ ProjectTaskRequestController::store()
 * สร้าง WorkOrder ทันทีโดยไม่ผ่านตารางนี้แล้ว (ดูเหตุผลที่ WorkOrderListPolicy::requestTask())
 * โมเดล/ตารางนี้ยังอยู่เพื่อให้แถวประวัติเก่า (pending/approved/rejected) และการแจ้งเตือนเก่า
 * ที่อ้างถึงแถวเหล่านั้นยังใช้งานได้ตามปกติ
 */
class WorkOrderListTaskRequest extends Model
{
    /** จำนวนครั้งสูงสุดที่ผู้ใช้หนึ่งคนยิง endpoint เพิ่มงานได้ในหนึ่งนาที */
    public const SUBMIT_RATE_LIMIT_PER_MINUTE = 10;

    public const SUBMIT_RATE_LIMITER = 'project-task-requests';

    protected $fillable = [
        'work_order_list_id',
        'requester_id',
        'decided_by',
        'work_order_id',
        'parent_job_id',
        'status',
        'job_topic',
        'job_details',
        'job_priority',
        'job_start_at',
        'job_due_at',
        'decision_reason',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'job_priority' => 'integer',
            'job_start_at' => 'datetime',
            'job_due_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id', 'job_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'parent_job_id', 'job_id');
    }
}
