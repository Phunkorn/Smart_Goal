<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderListTaskRequest extends Model
{
    /** จำนวนคำขอที่ผู้ใช้หนึ่งคนเปิดค้างได้ต่อหนึ่งโปรเจกต์ */
    public const MAX_PENDING_PER_REQUESTER_PROJECT = 5;

    /** จำนวนครั้งสูงสุดที่ผู้ใช้หนึ่งคนยิง endpoint ส่งคำขอได้ในหนึ่งนาที */
    public const SUBMIT_RATE_LIMIT_PER_MINUTE = 10;

    public const SUBMIT_RATE_LIMITER = 'project-task-requests';

    protected $fillable = [
        'work_order_list_id',
        'requester_id',
        'decided_by',
        'work_order_id',
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
}
