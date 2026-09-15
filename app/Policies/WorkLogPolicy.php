<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkLog;

/**
 * สิทธิ์ของ "บันทึกงานประจำวัน"
 *
 * กติกาที่ตกลงกันไว้: เจ้าของบันทึก + หัวหน้าแผนกของเจ้าของ + admin "มองเห็น" ได้
 *
 * ตั้งใจไม่มี admin bypass บน update()/delete()
 * ---------------------------------------------------------------
 * สิทธิ์ที่ตกลงกันคือการ "มองเห็น" ไม่ใช่การ "แก้ไข" บันทึกงานประจำวันเป็นบันทึก
 * ของเจ้าตัวว่าวันนั้นทำอะไรไปบ้าง การให้ admin หรือหัวหน้าเข้าไปแก้ได้ จะทำให้
 * บันทึกนั้นเชื่อถือไม่ได้ในฐานะประวัติการทำงานของบุคคล และ CLAUDE.md ก็ห้ามเพิ่ม
 * admin bypass เมื่อกฎทางธุรกิจไม่ได้ระบุไว้ชัดเจน
 * ถ้าภายหลังธุรกิจต้องการให้แก้ไขแทนกันได้ ให้เพิ่มเป็น ability ใหม่ที่ตั้งชื่อ
 * ตรงตามเจตนา พร้อมบันทึก audit ไม่ใช่เติม role check ลงในสองเมธอดนี้
 *
 * เรื่อง department snapshot
 * ---------------------------------------------------------------
 * logDepartmentId() อ่าน work_logs.department_id ก่อน แล้วค่อย fallback ไปที่
 * แผนกปัจจุบันของเจ้าของ แปลว่าบันทึกเก่าของคนที่ย้ายแผนกจะยังอยู่กับหัวหน้าคนเดิม
 * ซึ่งถูกต้องแล้ว เพราะงานนั้นเกิดขึ้นตอนที่เขายังอยู่แผนกนั้นจริง ๆ
 * อย่าเปลี่ยนเป็นการ lookup แผนกปัจจุบันอย่างเดียว เพราะจะเปลี่ยนว่าใครเห็นอะไร
 * ย้อนหลังทั้งระบบ
 */
class WorkLogPolicy
{
    /**
     * เปิดหน้าบันทึกงานประจำวันได้หรือไม่
     *
     * viewer เป็น read-only ของงานโครงการ และตามกติกาของระบบต้องไม่เป็นเจ้าของงาน
     * ไม่เป็นผู้รับผิดชอบ และไม่ถูกมอบหมายงาน จึงไม่มีบันทึกงานประจำวันเป็นของตัวเอง
     */
    public function viewAny(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    public function view(User $user, WorkLog $log): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        return $user->id === $log->user_id
            || $user->role === 'admin'
            || $user->overseesDepartment($this->logDepartmentId($log));
    }

    /**
     * ดูไทม์ไลน์ของคนอื่นในหน้าเดียวกัน (พารามิเตอร์ ?user= ของหน้าบันทึกงาน)
     *
     * แยกจาก view() เพราะตอนเลือกดูทั้งวันยังไม่มีรายการใดให้ตรวจ ต้องตัดสินจาก
     * ตัวเจ้าของวันโดยตรง
     *
     * วิธีเรียกที่ถูกต้อง: ต้องระบุคลาสไว้ข้างหน้าเสมอ เพราะ Gate เลือก policy จาก
     * argument ตัวแรก ถ้าส่ง User เปล่า ๆ มันจะไปหา UserPolicy แล้วตอบ false เงียบ ๆ
     *
     *     Gate::authorize('viewDay', [WorkLog::class, $owner]);
     */
    public function viewDay(User $viewer, User $owner): bool
    {
        if ($viewer->role === 'viewer') {
            return false;
        }

        return $viewer->id === $owner->id
            || $viewer->role === 'admin'
            || $viewer->overseesDepartment($owner->department_id);
    }

    /**
     * ปฏิทินแผนกเป็นมุมมองสรุปร่วมกัน ไม่ใช่สิทธิ์เปิด Timeline ของคนอื่น
     *
     * พนักงานทุกคนในแผนกเดียวกันเห็นชื่อ เวลา และประเภทงานบนปฏิทินได้ แต่
     * viewDay(), update() และ delete() ยังคงกติกาเดิม จึงใช้สิทธิ์นี้เพื่อเปิด
     * รายละเอียดหรือแก้บันทึกของเพื่อนร่วมงานไม่ได้
     */
    public function viewCalendarDay(User $viewer, User $owner): bool
    {
        if ($viewer->role === 'viewer') {
            return false;
        }

        return $viewer->role === 'admin'
            || $viewer->id === $owner->id
            || ($viewer->department_id !== null
                && (int) $viewer->department_id === (int) $owner->department_id);
    }

    /**
     * ใช้ department snapshot ของ WorkLog เพื่อไม่ย้ายประวัติเก่าไปตามแผนก
     * ปัจจุบันของเจ้าของโดยไม่ตั้งใจ
     */
    public function viewCalendar(User $viewer, WorkLog $log): bool
    {
        if ($viewer->role === 'viewer') {
            return false;
        }

        return $viewer->role === 'admin'
            || $viewer->id === $log->user_id
            || ($viewer->department_id !== null
                && (int) $viewer->department_id === (int) $this->logDepartmentId($log));
    }

    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * แก้ไขได้เฉพาะเจ้าของ (ดูเหตุผลที่ไม่มี admin bypass บนหัวคลาส)
     */
    public function update(User $user, WorkLog $log): bool
    {
        return $user->role !== 'viewer'
            && $user->id === $log->user_id
            && $log->status !== 'cancelled';
    }

    /**
     * ลบได้เฉพาะเจ้าของ (ดูเหตุผลที่ไม่มี admin bypass บนหัวคลาส)
     */
    public function delete(User $user, WorkLog $log): bool
    {
        return $user->role !== 'viewer' && $user->id === $log->user_id;
    }

    /**
     * เปิดรายงานปฏิบัติงานได้หรือไม่
     *
     * ทุก role ยกเว้น viewer เปิดได้ แต่ "เห็นข้อมูลของใคร" เป็นคนละเรื่องกัน:
     *
     *   - พนักงาน — เห็นเฉพาะของตัวเอง
     *   - หัวหน้าแผนก — เห็นทั้งแผนกของตัวเอง
     *   - admin — เห็นทุกแผนก
     *
     * ขอบเขตนั้นถูกบังคับที่ ReportController::forcedOwnerId() และ
     * forcedDepartmentId() ซึ่งส่งเป็นตัวกรองที่ผู้ใช้แก้ผ่าน query string ไม่ได้
     * ability นี้จึงตอบแค่ว่า "เข้าหน้านี้ได้ไหม" ไม่ใช่ "เห็นได้ถึงไหน"
     *
     * จงใจไม่รวม viewer ต่างจาก ReportController::authorizeAdminReports() ของ
     * รายงานโครงการที่เปิดให้ viewer ดูได้ เพราะ viewer ไม่มีบันทึกงานประจำวัน
     * เป็นของตัวเอง และข้อมูลนี้ละเอียดกว่าภาพรวมผลงานขององค์กร
     */
    public function viewReport(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * แผนกที่ใช้ตัดสินสิทธิ์ของหัวหน้าแผนก
     *
     * รูปแบบเดียวกับ WorkOrderPolicy::destinationDepartmentId()
     */
    private function logDepartmentId(WorkLog $log): ?int
    {
        return $log->department_id ?: $log->user?->department_id;
    }
}
