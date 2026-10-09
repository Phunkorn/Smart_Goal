<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งเหตุการณ์ในประวัติการตอบรับเข้าร่วมประชุมของผู้เข้าร่วมหนึ่งคน
 *
 * เพิ่มได้แต่ไม่แก้ไขและไม่ลบ การ "เชิญอีกครั้ง" จะรีเซ็ตสถานะปัจจุบันบน MeetingAttendee
 * กลับเป็น pending แต่คำตอบครั้งก่อนและเหตุผลที่ให้ไว้ยังอ่านย้อนหลังได้จากที่นี่
 */
class MeetingAttendeeResponseEvent extends Model
{
    public const EVENT_INVITED = 'invited';

    public const EVENT_REINVITED = 'reinvited';

    public const EVENT_ACCEPTED = 'accepted';

    public const EVENT_DECLINED = 'declined';

    public $timestamps = false;

    protected $fillable = [
        'meeting_id',
        'user_id',
        'actor_user_id',
        'event',
        'decline_reason',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    public function scopeForMeeting(Builder $query, Meeting|int $meeting): Builder
    {
        return $query->where('meeting_id', $meeting instanceof Meeting ? $meeting->id : $meeting);
    }

    public function scopeInChronologicalOrder(Builder $query): Builder
    {
        return $query->orderBy('occurred_at')->orderBy('id');
    }
}
