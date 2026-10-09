<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * แถวผู้ร่วมนัดหมายหนึ่งคน พร้อมสถานะตอบรับปัจจุบันของคนนั้น
 *
 * สะท้อน MeetingAttendee แบบ 1:1 แต่เป็นคนละ domain นัดหมายไม่ใช่การประชุม
 * จึงไม่ใช้ตารางและ service ร่วมกัน (ดู create_appointment_attendees_table)
 *
 * การถูกเพิ่มเข้านัดหมายไม่เท่ากับตอบรับแล้ว แถวใหม่จึงเริ่มที่ pending เสมอ
 * (ค่า default ของคอลัมน์) และเปลี่ยนได้เฉพาะผ่าน AppointmentAttendeeResponseService
 * เพื่อให้สถานะปัจจุบันกับประวัติใน AppointmentAttendeeResponseEvent ไม่หลุดจากกัน
 */
class AppointmentAttendee extends Model
{
    use AsPivot;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $table = 'appointment_attendees';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function isAwaitingResponse(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hasAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function hasDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINED;
    }
}
