<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ประกาศแชร์งานหนึ่งใบ
 *
 * ตัวประกาศไม่ให้สิทธิ์อะไรกับใครเลย มันเป็นแค่ป้ายที่บอกว่างานใบนี้เปิดรับ
 * ผู้ร่วมงานอยู่ สิทธิ์จริงเกิดตอนคำขอถูกอนุมัติแล้วกลายเป็นแถวใน
 * work_order_collaborators ผ่าน App\Services\CollaboratorInvitationService
 */
class WorkOrderShare extends Model
{
    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_ORGANIZATION = 'organization';

    protected $fillable = [
        'work_order_id',
        'shared_by',
        'department_id',
        'scope',
        'status',
        'note',
        'closed_at',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id', 'job_id');
    }

    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(WorkOrderShareRequest::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * ประกาศที่ผู้ใช้คนนี้มีสิทธิ์เห็น
     *
     * ขอบเขต 'department' เทียบกับ department_id ที่เป็นสแนปช็อตในแถว ไม่ใช่แผนก
     * ปัจจุบันของผู้แชร์ ส่วน 'organization' เปิดให้พนักงานทุกคนเห็น
     *
     * viewer ไม่เคยเห็นประกาศเลย เพราะเป็นสิทธิ์อ่านอย่างเดียวและเป็นผู้ร่วมงาน
     * ไม่ได้อยู่แล้ว (ดู CollaboratorInvitationService::invite())
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->role === 'viewer') {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($viewer): void {
            $visible->where('scope', self::SCOPE_ORGANIZATION)
                ->orWhere(function (Builder $sameDepartment) use ($viewer): void {
                    $sameDepartment->where('scope', self::SCOPE_DEPARTMENT)
                        ->where('department_id', $viewer->department_id);
                });
        });
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
