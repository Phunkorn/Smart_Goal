<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;

/**
 * แผนกไหนใช้ "ตรวจเช็กคอมพิวเตอร์ประจำวัน" ได้ — แหล่งความจริงเดียว
 *
 * ฟังก์ชันนี้เคยเปิดให้ทุกแผนกโดยอัตโนมัติ ทั้งที่มีแผนกเดียวที่เดินตรวจเครื่องจริง
 * ตอนนี้ admin เป็นผู้กำหนดรายแผนก (departments.computer_check_enabled)
 *
 * ทำไมเป็น Support ไม่ใช่ Policy
 * ---------------------------------------------------------------
 * นี่เป็น "ข้อเท็จจริงร่วม" แบบไม่มีสถานะ: แผนกหนึ่งเปิดใช้อยู่หรือไม่ ซึ่งถูกถามจาก
 * สามที่ที่ตอบเหมือนกันต้องตรงกันเสมอ — ComputerCheckSessionPolicy (บังคับสิทธิ์จริง),
 * Blade ที่ตัดสินว่าจะแสดงเมนูไหม และหน้าตั้งค่าของ admin
 * ส่วนการ "บังคับใช้" ยังอยู่ที่ ComputerCheckSessionPolicy ที่เดียวตามเดิม
 * การซ่อนเมนูใน Blade ไม่ใช่การให้สิทธิ์ (CLAUDE.md)
 *
 * แผนกที่ยังไม่ถูกกำหนดสิทธิ์คือ "ปิด" และผู้ใช้ที่ไม่มีแผนกก็คือปิดด้วย
 * ไม่มีทางผ่านของ role ใดที่นี่ รวมถึง admin — ฟังก์ชันนี้เป็นงานของแผนก
 * ไม่ใช่สิทธิ์ของบทบาท และ admin เปิดแผนกของตัวเองได้อยู่แล้วหากต้องใช้
 */
final class ComputerCheckAccess
{
    /** ผู้ใช้คนนี้อยู่ในแผนกที่เปิดใช้ฟังก์ชันนี้หรือไม่ */
    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // ถาม relation ที่โหลดมาแล้วก่อน เพื่อไม่ให้ทุกการตัดสินสิทธิ์ยิงคิวรีเพิ่มหนึ่งครั้ง
        if ($user->relationLoaded('department')) {
            return self::allowsDepartmentModel($user->department);
        }

        return self::allowsDepartment($user->department_id);
    }

    /** แผนกรหัสนี้เปิดใช้ฟังก์ชันนี้หรือไม่ — ใช้กับรอบตรวจเก่าที่เก็บแผนกเป็น snapshot */
    public static function allowsDepartment(int|string|null $departmentId): bool
    {
        if (! $departmentId) {
            return false;
        }

        return Department::query()
            ->whereKey($departmentId)
            ->where('computer_check_enabled', true)
            ->exists();
    }

    private static function allowsDepartmentModel(?Department $department): bool
    {
        return (bool) $department?->computer_check_enabled;
    }
}
