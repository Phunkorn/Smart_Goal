<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderUpdate extends Model
{
    protected $fillable = [
        'work_order_id',
        'user_id',
        'note',
        'is_comment',
        'reply_to_id',
        'pinned_at',
        'pinned_by',
    ];

    protected function casts(): array
    {
        return [
            'is_comment' => 'boolean',
            'pinned_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id', 'job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderUpdateAttachment::class, 'work_order_update_id');
    }

    /** ข้อความต้นทางที่คอมเมนต์นี้ตอบกลับแบบ quote (Facebook-style) ถ้ามี */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function pinnedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }

    /** คนที่ถูก @กล่าวถึงในคอมเมนต์นี้ จำกัดเฉพาะคนในงานเดียวกัน (ดู TaskCommentService::mentionCandidates()) */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_order_update_mentions', 'work_order_update_id', 'user_id')
            ->withTimestamps();
    }
}
