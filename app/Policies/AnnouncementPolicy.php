<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * สิทธิ์ของ "ประกาศ"
 *
 * กติกาที่ตกลงกันไว้
 * ---------------------------------------------------------------
 * - เมนูจัดการประกาศมีให้เฉพาะหัวหน้าแผนก (role = user + is_department_head)
 * - หัวหน้าแก้ไขและลบได้เฉพาะประกาศที่ตัวเองสร้าง และต้องยังเป็นหัวหน้าของ
 *   แผนกที่ประกาศนั้นสังกัดอยู่ หัวหน้าที่ถูกปลดหรือย้ายแผนกจึงเสียสิทธิ์ทันที
 *   แต่ประกาศที่อยู่ในช่วงแสดงยังแสดงต่อตามเดิม
 * - admin ไม่มีสิทธิ์ใด ๆ กับประกาศตามที่เจ้าของระบบกำหนด (ไม่ใช่การลืมใส่)
 * - viewer เป็น read-only ทั้งระบบ และไม่เป็นผู้รับประกาศ
 *
 * ทำไมไม่มี before()
 * ---------------------------------------------------------------
 * ตามแนวทางของ WorkspaceBoardPolicy และ WorkOrderPolicy สิทธิ์แต่ละข้อเขียนครบ
 * ในเมธอดเดียว และที่นี่ตั้งใจไม่มี admin bypass เลย
 */
class AnnouncementPolicy
{
    /** เปิดหน้าศูนย์ประกาศ */
    public function viewAny(User $user): bool
    {
        return $user->isDepartmentHead();
    }

    public function create(User $user): bool
    {
        return $user->isDepartmentHead();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->isOwnedByActingHead($user, $announcement);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->isOwnedByActingHead($user, $announcement);
    }

    private function isOwnedByActingHead(User $user, Announcement $announcement): bool
    {
        return $user->isDepartmentHead()
            && (int) $announcement->created_by === (int) $user->id
            && $user->overseesDepartment($announcement->department_id);
    }
}
