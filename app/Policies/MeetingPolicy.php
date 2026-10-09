<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;

class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'user', 'viewer'], true);
    }

    /**
     * เปิดรายละเอียดประชุมใบนี้ได้หรือไม่
     *
     * ต้องครอบคลุมทุกใบที่ MeetingQueryService::calendarScopedQuery() ส่งขึ้นปฏิทิน
     * ไม่งั้นผู้ใช้จะเห็นรายการบนปฏิทินแล้วกดเข้าไปเจอ 403
     *
     * ทางผ่าน "คนในแผนกของผู้จัด" ถูกเพิ่มพร้อมปฏิทินระดับแผนก — การประชุมเป็นงาน
     * ของทีม ไม่ใช่ข้อมูลส่วนตัว (ต่างจากนัดหมาย ดู AppointmentPolicy)
     * ส่วนผู้ที่ถูกเชิญข้ามแผนกยังเข้าได้ทางเงื่อนไข "เป็นผู้เข้าร่วม" ตามเดิม
     * และยังไม่เห็นประชุมอื่นของแผนกนั้น เพราะเงื่อนไขนี้เทียบแผนกของ "ผู้อ่าน" เอง
     *
     * การเห็นไม่เท่ากับสิทธิ์แก้ไข — update()/delete() ไม่ถูกแตะ
     */
    public function view(User $user, Meeting $meeting): bool
    {
        return in_array($user->role, ['admin', 'viewer'], true)
            || ($user->isDepartmentHead() && (
                $meeting->creator()->where('department_id', $user->department_id)->exists()
                || $meeting->attendees()->where('department_id', $user->department_id)->exists()
            ))
            || (int) $meeting->created_by === (int) $user->id
            || $meeting->attendees()->whereKey($user->id)->exists()
            || $this->isInOrganizerDepartment($user, $meeting);
    }

    /**
     * อยู่ในแผนกเดียวกับผู้จัดประชุมหรือไม่
     *
     * ผู้ใช้ที่ยังไม่ถูกจัดแผนก (department_id เป็น null) ต้องไม่จับคู่กับผู้จัดที่ยัง
     * ไม่ถูกจัดแผนกเหมือนกัน จึงตรวจว่ามีแผนกจริงก่อนเทียบ
     */
    private function isInOrganizerDepartment(User $user, Meeting $meeting): bool
    {
        if (! $user->department_id) {
            return false;
        }

        return $meeting->creator()->where('department_id', $user->department_id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'user'], true);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->role === 'admin'
            || ($user->role === 'user' && (int) $meeting->created_by === (int) $user->id);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    /**
     * ตอบรับหรือแจ้งไม่ว่างได้เฉพาะผู้ที่ถูกเชิญเข้าประชุมใบนี้ และตอบแทนตัวเองเท่านั้น
     * (route ไม่รับรหัสผู้ตอบ จึงไม่มีทางยิงเปลี่ยนสถานะของคนอื่น)
     *
     * ตอบได้เฉพาะตอนที่ยังเป็น pending คำตอบที่ให้ไปแล้วเปลี่ยนเองไม่ได้ ต้องให้ผู้สร้างประชุม
     * เชิญอีกครั้งซึ่งรีเซ็ตกลับเป็น pending (ดู MeetingAttendeeResponseService)
     */
    public function respondToInvitation(User $user, Meeting $meeting): bool
    {
        return $user->role !== 'viewer'
            && $meeting->attendees()
                ->whereKey($user->id)
                ->wherePivot('status', MeetingAttendee::STATUS_PENDING)
                ->exists();
    }

    /**
     * คำตอบของผู้เข้าร่วมทุกคนเป็นข้อมูลของผู้สร้างประชุมคนเดียว ไม่เปิดให้ผู้เข้าร่วมคนอื่น
     * หรือผู้ที่เพียงแค่ "ดูประชุมได้" เห็นว่าใครปฏิเสธด้วยเหตุผลอะไร
     *
     * ไม่มีทางผ่านของ admin หรือหัวหน้าแผนกโดยเจตนา — ทั้งสองบทบาทยังดูรายละเอียดประชุม
     * ได้ตามปกติผ่าน view() แต่คำตอบรายบุคคลไม่ใช่ข้อมูลกำกับดูแล
     */
    public function viewAttendeeResponses(User $user, Meeting $meeting): bool
    {
        return (int) $meeting->created_by === (int) $user->id;
    }

    /** เชิญอีกครั้งได้เฉพาะผู้สร้างประชุม และเฉพาะกับผู้ที่แจ้งไม่ว่างไว้แล้ว */
    public function reinviteAttendee(User $user, Meeting $meeting, User $meetingAttendee): bool
    {
        return $this->viewAttendeeResponses($user, $meeting)
            && $meeting->attendees()
                ->whereKey($meetingAttendee->id)
                ->wherePivot('status', MeetingAttendee::STATUS_DECLINED)
                ->exists();
    }
}
