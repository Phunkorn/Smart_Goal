<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $fillable = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'location',
        'map_url',
        'created_by',
        'work_order_list_id',
        'work_order_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * สถานะตอบรับของผู้เข้าร่วมอยู่บนแถว pivot ไม่ใช่ตารางแยก จึงต้องดึงมาด้วยทุกครั้ง
     * มิฉะนั้นหน้าจอจะไม่รู้ว่าใครตอบรับแล้วและใครยังรอตอบ (ดู MeetingAttendee)
     */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_attendees')
            ->using(MeetingAttendee::class)
            ->withPivot(['status', 'responded_at', 'decline_reason'])
            ->withTimestamps();
    }

    public function attendeeResponseEvents(): HasMany
    {
        return $this->hasMany(MeetingAttendeeResponseEvent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id', 'job_id');
    }
}
