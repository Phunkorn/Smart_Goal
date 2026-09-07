<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkLogTemplate;

/**
 * สิทธิ์ของแม่แบบงานประจำ
 *
 * แม่แบบเป็นของรายบุคคลล้วน ๆ ในรอบนี้ ต่างจากตัวบันทึกงานที่หัวหน้าแผนกและ
 * admin มองเห็นได้ เพราะแม่แบบคือการตั้งค่าส่วนตัวว่าจะให้ระบบสร้างรายการอะไร
 * ให้ทุกเช้า ไม่ใช่ข้อมูลผลงานที่ต้องรายงานขึ้นไป
 *
 * เมื่อใดที่ต้องการแม่แบบระดับแผนก ให้เพิ่ม ability ใหม่พร้อมแก้โครงสร้างตาราง
 * (ดูหมายเหตุใน migration ของ work_log_templates) ไม่ใช่ผ่อนกติกาที่นี่
 */
class WorkLogTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    public function view(User $user, WorkLogTemplate $template): bool
    {
        return $user->role !== 'viewer' && $user->id === $template->user_id;
    }

    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    public function update(User $user, WorkLogTemplate $template): bool
    {
        return $user->role !== 'viewer' && $user->id === $template->user_id;
    }

    public function delete(User $user, WorkLogTemplate $template): bool
    {
        return $user->role !== 'viewer' && $user->id === $template->user_id;
    }
}
