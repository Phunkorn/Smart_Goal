<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หนึ่งเหตุการณ์ในประวัติการตอบรับเข้าร่วมนัดหมายของผู้ร่วมหนึ่งคน
 *
 * เพิ่มได้แต่ไม่แก้ไขและไม่ลบ การ "เชิญอีกครั้ง" จะรีเซ็ตสถานะปัจจุบันบน
 * AppointmentAttendee กลับเป็น pending แต่คำตอบครั้งก่อนและเหตุผลที่ให้ไว้
 * ยังอ่านย้อนหลังได้จากที่นี่
 */
class AppointmentAttendeeResponseEvent extends Model
{
    public const EVENT_INVITED = 'invited';

    public const EVENT_REINVITED = 'reinvited';

    public const EVENT_ACCEPTED = 'accepted';

    public const EVENT_DECLINED = 'declined';

    public $timestamps = false;

    protected $fillable = [
        'appointment_id',
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    public function scopeForAppointment(Builder $query, Appointment|int $appointment): Builder
    {
        return $query->where('appointment_id', $appointment instanceof Appointment ? $appointment->id : $appointment);
    }

    public function scopeInChronologicalOrder(Builder $query): Builder
    {
        return $query->orderBy('occurred_at')->orderBy('id');
    }
}
