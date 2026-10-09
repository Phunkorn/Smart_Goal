<?php

namespace App\Policies;

use App\Models\ComputerCheckSession;
use App\Models\User;
use App\Support\ComputerCheckAccess;

/**
 * สิทธิ์ของ "ตรวจเช็กคอมพิวเตอร์ประจำวัน"
 *
 * กติกา:
 *   - viewer เป็น read-only ของทั้งระบบ และตามกติกาใน CLAUDE.md ต้องไม่เป็น
 *     เจ้าของงานหรือผู้รับผิดชอบ จึงเป็นผู้ตรวจไม่ได้และเปิดหน้านี้ไม่ได้เลย
 *   - "เปิดหน้า/เริ่มรอบ" — ต้องอยู่ในแผนกที่ admin เปิดสิทธิ์ให้ใช้ฟังก์ชันนี้
 *   - "มองเห็นรอบใบหนึ่ง" — ผู้ตรวจทั้งสองคน + หัวหน้าแผนกของรอบนั้น + admin
 *   - "แก้ไข" — ผู้ตรวจทั้งสองคนเท่านั้น และเฉพาะรอบที่ยังไม่ปิด
 *
 * สิทธิ์รายแผนก
 * ---------------------------------------------------------------
 * ฟังก์ชันนี้เคยเปิดให้ทุกแผนกโดยอัตโนมัติ ตอนนี้ admin กำหนดรายแผนกที่หน้า
 * "จัดการแผนก" โดยคำตอบมาจาก App\Support\ComputerCheckAccess ที่เดียว
 * แผนกที่ยังไม่ได้กำหนดคือปิด และไม่มีทางผ่านของ role ใดบน viewAny()/create()
 * รวมถึง admin เพราะนี่เป็นงานของแผนก ไม่ใช่สิทธิ์ของบทบาท — admin ที่ต้องใช้จริง
 * เปิดสิทธิ์ให้แผนกของตัวเองได้อยู่แล้ว
 *
 * view() ยังคงทางผ่านของ admin และหัวหน้าแผนกไว้ตามเดิมโดยตั้งใจ รอบที่ถูกบันทึก
 * ไปแล้วเป็นหลักฐานการปฏิบัติงานที่ต้องตรวจย้อนหลังได้ แม้ภายหลังแผนกนั้นถูกปิดสิทธิ์
 *
 * ตั้งใจไม่มี admin bypass บน update()
 * ---------------------------------------------------------------
 * ด้วยเหตุผลเดียวกับ WorkLogPolicy: แบบตรวจคือบันทึกว่าคนสองคนเดินไปตรวจเครื่อง
 * จริงเมื่อไหร่ ถ้า admin หรือหัวหน้าเข้าไปติ๊กแทนได้ ใบตรวจนั้นจะเชื่อถือไม่ได้
 * ในฐานะหลักฐานการปฏิบัติงาน และ CLAUDE.md ห้ามเพิ่ม admin bypass เมื่อกฎทาง
 * ธุรกิจไม่ได้ระบุไว้ชัดเจน
 *
 * เรื่อง department snapshot
 * ---------------------------------------------------------------
 * sessionDepartmentId() อ่าน computer_check_sessions.department_id ก่อน แล้วค่อย
 * fallback ไปที่แผนกปัจจุบันของผู้ตรวจคนที่ 1 รูปแบบเดียวกับ WorkLogPolicy
 * เพื่อให้ใบเก่าของคนที่ย้ายแผนกยังอยู่กับหัวหน้าคนเดิมที่ดูแลอยู่ในตอนนั้น
 */
class ComputerCheckSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== 'viewer'
            && ComputerCheckAccess::allows($user);
    }

    public function create(User $user): bool
    {
        return $user->role !== 'viewer'
            && ComputerCheckAccess::allows($user);
    }

    public function view(User $user, ComputerCheckSession $session): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        return in_array((int) $user->id, $session->checkerIds(), true)
            || $user->role === 'admin'
            || $user->overseesDepartment($this->sessionDepartmentId($session));
    }

    /**
     * ติ๊กผลรายเครื่องของรอบนี้ได้หรือไม่
     *
     * ปิดรอบแล้วแก้ไม่ได้อีก — ใบที่ปิดแล้วถูกนำไปสร้างบันทึกงานประจำวันและเป็น
     * ข้อมูลของรายงานย้อนหลังไปแล้ว การแก้ทีหลังจะทำให้ตัวเลขที่หัวหน้าเห็นไป
     * แล้วเปลี่ยนโดยไม่มีร่องรอย
     */
    public function update(User $user, ComputerCheckSession $session): bool
    {
        return $user->role !== 'viewer'
            && ! $session->isCompleted()
            && in_array((int) $user->id, $session->checkerIds(), true);
    }

    /**
     * ปิดรอบ (กด "บันทึกผลการตรวจ") — สิทธิ์เดียวกับการติ๊กผล
     *
     * แยกเป็น ability ของตัวเองเพราะเป็นการกระทำคนละอย่าง และถ้าภายหลังธุรกิจ
     * ต้องการให้เฉพาะผู้ตรวจคนที่ 1 ปิดได้ จะแก้ที่นี่จุดเดียวโดยไม่กระทบการติ๊ก
     */
    public function complete(User $user, ComputerCheckSession $session): bool
    {
        return $this->update($user, $session);
    }

    private function sessionDepartmentId(ComputerCheckSession $session): ?int
    {
        return $session->department_id ?: $session->checkerOne?->department_id;
    }
}
