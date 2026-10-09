<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\User;
use App\Support\MeetingCalendarScope;

/**
 * นัดหมายเป็นเรื่องของผู้สร้างกับผู้ที่ถูกเชิญ — เว้นใบที่ผู้สร้างเลือกเปิดให้แผนก
 *
 * ต่างจาก MeetingPolicy::view() ที่เปิดให้ admin, viewer และหัวหน้าแผนกเห็นประชุม
 * ของลูกทีมได้ เพราะการประชุมเป็นงานของทีมที่ต้องกำกับดูแลได้ ส่วนนัดหมายเป็นเรื่อง
 * ส่วนตัวของคนที่เกี่ยวข้อง การเปิดให้ "บทบาทสูงกว่า" อ่านจึงยังไม่มีเหตุผลรองรับ
 * และยังไม่มีทางผ่านของ admin หรือหัวหน้าแผนกบนนัดหมายส่วนตัวของคนอื่น
 *
 * ขอบเขต "แผนก" ที่เพิ่มเข้ามาพร้อมปฏิทินระดับแผนก
 * ---------------------------------------------------------------
 * ตัวตัดสินด่านแรกคือ appointments.visibility ซึ่ง "ผู้สร้างเลือกเอง" ไม่ใช่บทบาท
 * ของผู้อ่าน นัดหมายทุกใบที่มีอยู่ก่อนฟีเจอร์นี้เป็น 'personal' (ดู migration
 * add_department_scope_to_appointments_table) จึงไม่มีใบไหนถูกเปิดเผยย้อนหลัง
 * และนัดหมายส่วนตัวของคนอื่นยังไม่มีทางผ่านของ admin หรือหัวหน้าแผนกเลย
 *
 * ด่านที่สองคือ "เปิดปฏิทินของแผนกเจ้าของได้หรือไม่" (MeetingCalendarScope::allows)
 * ซึ่งรวม admin ด้วย เพราะ admin เปิด Member Workspace ของทุกแผนกได้และหน้านั้น
 * ต้องแสดงปฏิทินของแผนกนั้นจริง ๆ ไม่ใช่ปฏิทินของ admin เอง
 * ด่านนี้จึงไม่ใช่ "บทบาทสูงกว่าอ่านได้ทุกอย่าง" — มันทำงานได้เฉพาะกับใบที่
 * เจ้าของกดแชร์ให้แผนกไว้แล้วเท่านั้น
 *
 * การเห็นข้ามแผนกไม่เท่ากับสิทธิ์แก้ไข — update()/delete()/viewAttendeeResponses()
 * ยังเป็นของผู้สร้างคนเดียวทั้งหมด
 *
 * viewer เป็นบทบาทอ่านอย่างเดียวและห้ามเป็นเจ้าของข้อมูล (CLAUDE.md) จึงสร้างไม่ได้
 * และตอบรับไม่ได้ แม้จะถูกผูกเป็นผู้ร่วมไว้ก็ตาม (ดู InvitationCandidates)
 */
class AppointmentPolicy
{
    /**
     * เปิดรายการ/ปฏิทินนัดหมายได้หรือไม่ — ทุกบทบาทที่อยู่ในระบบ
     *
     * viewer ถูกรวมด้วยเพราะปฏิทินเป็นปฏิทินของแผนก และ viewer ก็อยู่ในแผนก
     * การเปิดรายการไม่ได้เปิดข้อมูล: แถวที่เห็นจริงถูกจำกัดด้วย
     * Appointment::scopeVisibleTo() ซึ่ง viewer จะได้เฉพาะนัดหมายที่ถูกตั้งเป็น
     * ขอบเขต "แผนก" ของแผนกตัวเองเท่านั้น — สร้างไม่ได้ ถูกเชิญไม่ได้ (InvitationCandidates)
     * จึงไม่มีนัดหมายส่วนตัวของใครเข้าเกณฑ์ให้ viewer เห็นได้เลย
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'user', 'viewer'], true);
    }

    /**
     * ผู้สร้าง, ผู้ที่ถูกเชิญ, หรือผู้ที่เปิดปฏิทินของแผนกเจ้าของได้เมื่อนัดหมายถูก
     * ตั้งเป็นขอบเขต "แผนก"
     *
     * ต้องครอบคลุมทุกใบที่ขึ้นบนปฏิทินได้ ไม่งั้นปฏิทินจะขึ้นรายการที่กดเข้าไปแล้ว
     * เจอ 403 — ฝั่งคิวรีมีสองขอบเขตที่ต้องตรงกับที่นี่:
     *   - ปฏิทินของผู้ดูเอง → Appointment::scopeVisibleTo()
     *   - ปฏิทินของแผนก → Appointment::scopeSharedWithDepartment()
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isCreatedBy($user, $appointment)
            || $this->isInvited($user, $appointment)
            || $this->isSharedWithACalendarTheyCanOpen($user, $appointment);
    }

    public function create(User $user): bool
    {
        return $this->canOwnAppointments($user);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->isCreatedBy($user, $appointment);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }

    /**
     * ตอบรับหรือแจ้งไม่ว่างได้เฉพาะผู้ที่ถูกเชิญ และตอบแทนตัวเองเท่านั้น
     * (route ไม่รับรหัสผู้ตอบ จึงไม่มีทางยิงเปลี่ยนสถานะของคนอื่น)
     *
     * ตอบได้เฉพาะตอนที่ยังเป็น pending คำตอบที่ให้ไปแล้วเปลี่ยนเองไม่ได้ ต้องให้ผู้สร้าง
     * เชิญอีกครั้งซึ่งรีเซ็ตกลับเป็น pending — กติกาเดียวกับ MeetingPolicy
     */
    public function respondToInvitation(User $user, Appointment $appointment): bool
    {
        return $user->role !== 'viewer'
            && $appointment->attendees()
                ->whereKey($user->id)
                ->wherePivot('status', AppointmentAttendee::STATUS_PENDING)
                ->exists();
    }

    /**
     * คำตอบของผู้ร่วมทุกคนเป็นข้อมูลของผู้สร้างนัดหมายคนเดียว ไม่เปิดให้ผู้ร่วมคนอื่น
     * เห็นว่าใครปฏิเสธด้วยเหตุผลอะไร
     */
    public function viewAttendeeResponses(User $user, Appointment $appointment): bool
    {
        return $this->isCreatedBy($user, $appointment);
    }

    /** เชิญอีกครั้งได้เฉพาะผู้สร้างนัดหมาย และเฉพาะกับผู้ที่แจ้งไม่ว่างไว้แล้ว */
    public function reinviteAttendee(User $user, Appointment $appointment, User $appointmentAttendee): bool
    {
        return $this->viewAttendeeResponses($user, $appointment)
            && $appointment->attendees()
                ->whereKey($appointmentAttendee->id)
                ->wherePivot('status', AppointmentAttendee::STATUS_DECLINED)
                ->exists();
    }

    private function isCreatedBy(User $user, Appointment $appointment): bool
    {
        return $this->canOwnAppointments($user)
            && (int) $appointment->created_by === (int) $user->id;
    }

    private function isInvited(User $user, Appointment $appointment): bool
    {
        return $appointment->attendees()->whereKey($user->id)->exists();
    }

    /**
     * นัดหมายใบนี้ถูกแชร์ให้แผนกที่ผู้อ่าน "เปิดปฏิทินของแผนกนั้นได้" หรือไม่
     *
     * เงื่อนไขแรกและเงื่อนไขที่ตัดสินจริงคือ visibility ซึ่งผู้สร้างเลือกเอง
     * นัดหมาย 'personal' ตกทันทีสำหรับทุกบทบาท ไม่มีทางผ่านของ admin หรือหัวหน้าแผนก
     * บนนัดหมายส่วนตัวของคนอื่น — กติกาเดิมของไฟล์นี้ยังอยู่ครบ
     *
     * ส่วน "ใครเปิดปฏิทินของแผนกไหนได้" ใช้กฎก้อนเดียวกับปฏิทินเอง
     * (MeetingCalendarScope::allows) จึงไม่มีทางที่ปฏิทินจะขึ้นรายการที่กดเข้าไป
     * แล้วเจอ 403 หรือกลับกัน คือกดได้แต่ไม่โผล่บนปฏิทิน
     * ผู้ใช้ที่ไม่มีแผนกยังไม่จับคู่กับนัดหมายที่ไม่มีแผนกเจ้าของ เพราะ allows()
     * ปฏิเสธ departmentId ที่เป็น null ตั้งแต่บรรทัดแรก
     *
     * ผลที่เพิ่มจากเดิม: admin เปิดนัดหมายที่ถูกแชร์ให้แผนกได้ทุกแผนก เพราะ
     * Member Workspace ของทุกแผนกเปิดได้อยู่แล้วและต้องแสดงปฏิทินของแผนกนั้น
     * (ข้อกำหนด "แยกสิทธิ์ดูปฏิทินออกจากสิทธิ์สร้าง/แก้ไข/ลบ")
     * ส่วนคนในแผนกเดียวกันยังเห็นเท่าเดิมทุกกรณี
     *
     * การมองเห็นเท่านั้น ไม่ได้ให้สิทธิ์แก้หรือลบ — update()/delete() ยังเป็นของ
     * ผู้สร้างคนเดียวตามเดิม และ viewAttendeeResponses() ก็ไม่เปิดตาม
     */
    private function isSharedWithACalendarTheyCanOpen(User $user, Appointment $appointment): bool
    {
        // ไม่เรียก canOwnAppointments() ที่นี่ — viewer เห็นปฏิทินของแผนกตัวเองได้
        // แต่ยังเป็นเจ้าของนัดหมายไม่ได้ ซึ่งคุมที่ create()/update() ตามเดิม
        if (! $appointment->isDepartmentScoped()) {
            return false;
        }

        return MeetingCalendarScope::allows($user, $appointment->department_id);
    }

    private function canOwnAppointments(User $user): bool
    {
        return in_array($user->role, ['admin', 'user'], true);
    }
}
