<?php

namespace App\Support;

use App\Models\User;

/**
 * ชื่อบทบาทของผู้ใช้ตามที่แสดงต่อคน — แหล่งเดียวของทั้งระบบ
 *
 * "หัวหน้าแผนก" ไม่ใช่ค่าใน users.role แต่เป็นธงแยกต่างหาก (users.is_department_head)
 * ทุกหน้าที่แปลง role เป็นข้อความเองด้วย match หรือ array lookup จึงมองข้ามธงนี้ได้ง่าย
 * และเคยเกิดขึ้นจริงที่หน้า "ตั้งค่า" ซึ่งแสดงหัวหน้าแผนกเป็น "พนักงาน"
 * ทั้งที่แถบบนของหน้าเดียวกันแสดงว่า "หัวหน้าแผนก" อย่างถูกต้อง
 *
 * เมื่อจะแสดงบทบาทที่ใดก็ตาม ให้เรียกที่นี่ ห้ามเขียนตารางแปลงชุดใหม่
 */
final class RoleLabel
{
    public const ADMIN = 'ผู้ดูแลระบบ';

    public const VIEWER = 'ผู้เข้าชม';

    public const DEPARTMENT_HEAD = 'หัวหน้าแผนก';

    public const MEMBER = 'พนักงาน';

    public static function for(?User $user): string
    {
        if (! $user) {
            return self::MEMBER;
        }

        if ($user->isDepartmentHead()) {
            return self::DEPARTMENT_HEAD;
        }

        return match ($user->role) {
            'admin' => self::ADMIN,
            'viewer' => self::VIEWER,
            default => self::MEMBER,
        };
    }

    /**
     * ชื่อบทบาทพร้อมแผนก สำหรับที่ที่มีพื้นที่พอจะบอกทั้งสองอย่าง
     *
     * admin และ viewer ไม่ผูกกับแผนก (UserController บังคับ department_id เป็น null)
     * จึงไม่มีชื่อแผนกต่อท้ายให้โดยอัตโนมัติ
     */
    public static function withDepartment(?User $user, string $separator = ' · '): string
    {
        $label = self::for($user);
        $department = $user?->department?->department_name;

        return $department && ! in_array($user?->role, ['admin', 'viewer'], true)
            ? $label.$separator.$department
            : $label;
    }
}
