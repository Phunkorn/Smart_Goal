<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\User;

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
 * ตัวตัดสินคือ appointments.visibility ซึ่ง "ผู้สร้างเลือกเอง" ไม่ใช่บทบาทของผู้อ่าน
 * นัดหมายทุกใบที่มีอยู่ก่อนฟีเจอร์นี้เป็น 'personal' (ดู migration
 * add_department_scope_to_appointments_table) จึงไม่มีใบไหนถูกเปิดเผยย้อนหลัง
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
     * ผู้สร้าง, ผู้ที่ถูกเชิญ, หรือคนในแผนกเจ้าของเมื่อนัดหมายถูกตั้งเป็นขอบเขต "แผนก"
     *
     * ต้องตรงกับ Appointment::scopeVisibleTo() เป๊ะ ๆ — ถ้าสองที่นี้ไม่ตรงกัน
     * ปฏิทินจะขึ้นรายการที่กดเข้าไปแล้วเจอ 403 หรือแย่กว่านั้นคือกลับกัน
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isCreatedBy($user, $appointment)
            || $this->isInvited($user, $appointment)
            || $this->isInOwningDepartment($user, $appointment);
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
     * อยู่ในแผนกเจ้าของของนัดหมายที่เปิดเป็นขอบเขต "แผนก" หรือไม่
     *
     * การมองเห็นเท่านั้น ไม่ได้ให้สิทธิ์แก้หรือลบ — update()/delete() ยังเป็นของ
     * ผู้สร้างคนเดียวตามเดิม และ viewAttendeeResponses() ก็ไม่เปิดตาม
     *
     * ผู้ใช้ที่ไม่มีแผนก (department_id เป็น null) ต้องไม่จับคู่กับนัดหมายที่ไม่มี
     * แผนกเจ้าของ จึงตรวจว่าทั้งสองค่ามีจริงก่อนเทียบ
     */
    private function isInOwningDepartment(User $user, Appointment $appointment): bool
    {
        // ไม่เรียก canOwnAppointments() ที่นี่ — viewer เห็นปฏิทินของแผนกตัวเองได้
        // แต่ยังเป็นเจ้าของนัดหมายไม่ได้ ซึ่งคุมที่ create()/update() ตามเดิม
        if (! $appointment->isDepartmentScoped()) {
            return false;
        }

        return (bool) $user->department_id
            && (int) $appointment->department_id === (int) $user->department_id;
    }

    private function canOwnAppointments(User $user): bool
    {
        return in_array($user->role, ['admin', 'user'], true);
    }
}
