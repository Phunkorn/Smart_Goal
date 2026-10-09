<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * แถวผู้เข้าร่วมประชุมหนึ่งคน พร้อมสถานะตอบรับปัจจุบันของคนนั้น
 *
 * การถูกเพิ่มเข้าประชุมไม่เท่ากับตอบรับแล้ว แถวใหม่จึงเริ่มที่ pending เสมอ
 * (ค่า default ของคอลัมน์) และเปลี่ยนได้เฉพาะผ่าน MeetingAttendeeResponseService
 * เพื่อให้สถานะปัจจุบันกับประวัติใน MeetingAttendeeResponseEvent ไม่หลุดจากกัน
 */
class MeetingAttendee extends Model
{
    use AsPivot;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $table = 'meeting_attendees';

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
