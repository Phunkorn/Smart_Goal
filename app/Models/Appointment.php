<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * นัดหมายของผู้ใช้หนึ่งคน
 *
 * นัดหมายเชิญผู้ร่วมได้และมีรอบการตอบรับของตัวเอง แต่เป็นคนละ domain กับการประชุม
 * จึงใช้ตาราง service และ policy ของตัวเองทั้งหมด (ดู AppointmentAttendee)
 * ห้ามนำสถานะ RSVP ของการประชุมมาใช้กับแถวนี้
 *
 * created_by และ department_id ไม่อยู่ใน $fillable โดยตั้งใจ ทั้งคู่ถูกกำหนดจาก
 * ผู้ใช้ที่ล็อกอินอยู่ใน App\Services\AppointmentService เท่านั้น เหมือนที่
 * Announcement ทำกับ department_id — แผนกเจ้าของต้องไม่มาจากฟอร์มได้
 *
 * visibility อยู่ใน $fillable เพราะเป็นตัวเลือกของผู้สร้างจริง ๆ ("ส่วนตัว" หรือ
 * "แผนก") แต่ค่าถูกจำกัดด้วย validation ที่ AppointmentController เสมอ
 *
 * ใครเห็น/แก้/ลบนัดหมายใบไหนมีแหล่งเดียวคือ App\Policies\AppointmentPolicy
 */
class Appointment extends Model
{
    use SoftDeletes;

    /** เห็นได้เฉพาะผู้สร้างกับผู้ที่ถูกเชิญ — พฤติกรรมเดิมของนัดหมายทุกใบ */
    public const VISIBILITY_PERSONAL = 'personal';

    /** ขึ้นบนปฏิทินของแผนกเจ้าของ ให้ทุกคนในแผนกนั้นเห็น */
    public const VISIBILITY_DEPARTMENT = 'department';

    /** ป้ายที่ผู้ใช้เห็น — ใช้ร่วมกันทั้งฟอร์ม ปฏิทิน และ validation */
    public const VISIBILITY_LABELS = [
        self::VISIBILITY_PERSONAL => 'ส่วนตัว',
        self::VISIBILITY_DEPARTMENT => 'แผนก',
    ];

    protected $fillable = [
        'title',
        'appointment_date',
        'starts_at_time',
        'ends_at_time',
        'location',
        'map_url',
        'description',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
        ];
    }

    /** แผนกเจ้าของนัดหมาย — null ได้เมื่อผู้สร้างยังไม่ถูกจัดแผนก หรือแผนกถูกลบ */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function isDepartmentScoped(): bool
    {
        return $this->visibility === self::VISIBILITY_DEPARTMENT;
    }

    /** ผู้บันทึกนัดหมาย — รวมบัญชีที่ถูกลบแล้ว เพื่อให้นัดหมายเก่ายังบอกเจ้าของได้ */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * สถานะตอบรับของผู้ร่วมอยู่บนแถว pivot ไม่ใช่ตารางแยก จึงต้องดึงมาด้วยทุกครั้ง
     * มิฉะนั้นหน้าจอจะไม่รู้ว่าใครตอบรับแล้วและใครยังรอตอบ (ดู AppointmentAttendee)
     */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'appointment_attendees')
            ->using(AppointmentAttendee::class)
            ->withPivot(['status', 'responded_at', 'decline_reason'])
            ->withTimestamps();
    }

    public function attendeeResponseEvents(): HasMany
    {
        return $this->hasMany(AppointmentAttendeeResponseEvent::class);
    }

    /** นัดหมายที่ตกอยู่ในช่วงวันที่ขอ — ใช้โดย MeetingAppointmentCalendarQuery */
    public function scopeWithinDateRange(Builder $query, string $fromDate, string $toDate): Builder
    {
        return $query->whereBetween('appointment_date', [$fromDate, $toDate]);
    }

    /**
     * นัดหมายที่ผู้ใช้คนนี้เห็นได้ — ผู้สร้าง, ผู้ที่ถูกเชิญ,
     * หรือนัดหมายที่ตั้งขอบเขตเป็น "แผนก" ของแผนกเดียวกับผู้ใช้
     *
     * ต้องตรงกับ AppointmentPolicy::view() เป๊ะ ๆ
     *
     * ขอบเขตแผนกไม่ใช่การเปิดนัดหมายทั้งระบบ
     * ---------------------------------------------------------------
     * เห็นได้เฉพาะนัดหมายที่ผู้สร้าง "เลือกเอง" ว่าให้เป็นของแผนก (visibility)
     * นัดหมายทุกใบที่มีอยู่ก่อนหน้านี้เป็น 'personal' จึงไม่มีใบไหนถูกเปิดเผย
     * ย้อนหลังเพราะการเปลี่ยนปฏิทินเป็นระดับแผนก
     *
     * ไม่มีทางผ่านของ admin หรือหัวหน้าแผนกให้เห็นนัดหมายส่วนตัวของคนอื่น —
     * นัดหมายยังไม่ใช่ข้อมูลกำกับดูแลเหมือนการประชุม ขอบเขตที่เพิ่มคือ "แผนกเดียวกัน"
     * ไม่ใช่ "บทบาทสูงกว่า" ผู้ใช้ที่ไม่มีแผนกจึงไม่ได้เห็นอะไรเพิ่มขึ้นเลย
     */
    public function scopeVisibleTo(Builder $query, User|int $viewer): Builder
    {
        $viewerId = $viewer instanceof User ? $viewer->id : $viewer;
        $viewerDepartmentId = $viewer instanceof User
            ? $viewer->department_id
            : User::whereKey($viewerId)->value('department_id');

        return $query->where(function (Builder $visible) use ($viewerId, $viewerDepartmentId): void {
            $visible->where('created_by', $viewerId)
                ->orWhereHas('attendees', fn (Builder $attendees) => $attendees->whereKey($viewerId));

            // department_id ที่เป็น null ต้องไม่จับคู่กับ null ของใคร ไม่งั้นผู้ใช้ที่ยังไม่มีแผนก
            // จะเห็นนัดหมายของทุกคนที่ยังไม่มีแผนกเหมือนกัน
            if ($viewerDepartmentId) {
                $visible->orWhere(fn (Builder $departmentScoped) => $departmentScoped
                    ->where('visibility', self::VISIBILITY_DEPARTMENT)
                    ->where('department_id', $viewerDepartmentId));
            }
        });
    }
}
