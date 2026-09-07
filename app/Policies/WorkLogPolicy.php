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
     * เริ่ม/หยุดจับเวลา — ต้องเป็นเจ้าของและรายการต้องยังไม่ปิด
     */
    public function manageTimer(User $user, WorkLog $log): bool
    {
        return $user->role !== 'viewer'
            && $user->id === $log->user_id
            && $log->status === 'open';
    }

    /**
     * ดูรายงานภาระงานปฏิบัติการ
     *
     * จงใจไม่รวม viewer ต่างจาก ReportController::authorizeAdminReports() ของ
     * รายงานโครงการที่เปิดให้ viewer ดูได้ เพราะบันทึกงานประจำวันเป็นข้อมูล
     * รายบุคคลที่ละเอียดกว่า ไม่ใช่ภาพรวมผลงานขององค์กร
     */
    public function viewReport(User $user): bool
    {
        return $user->role === 'admin' || $user->isDepartmentHead();
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
