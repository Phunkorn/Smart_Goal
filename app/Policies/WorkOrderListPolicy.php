<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrderList;

/**
 * รวม authorization logic ของ WorkOrderList ที่แต่เดิมกระจายอยู่เป็น
 * abort_unless()/abort_if() ตรงๆ ใน MyTaskController (รวมถึง private helper
 * canManageList() ในอดีต)
 */
class WorkOrderListPolicy
{
    /**
     * สร้างรายการใหม่ (MyTaskController::storeList)
     */
    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * แสดง/ซ่อนรายการ (MyTaskController::toggleList) — เดิมเช็คแค่ความเป็นเจ้าของ
     * list เท่านั้น ไม่มีการยกเว้นให้ admin หรือกันบทบาท viewer เหมือน endpoint อื่น
     * จึงคงพฤติกรรมเดิมไว้ตรงๆ ห้ามเพิ่มเงื่อนไข role === 'admin' หรือ viewer เข้ามา
     */
    public function toggle(User $user, WorkOrderList $list): bool
    {
        return $list->user_id === $user->id;
    }

    /**
     * แก้ไขชื่อ/ลบรายการ (MyTaskController::updateList, destroyList — เดิมคือ
     * canManageList())
     */
    public function manage(User $user, WorkOrderList $list): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        return $user->role === 'admin' || (int) $list->user_id === (int) $user->id;
    }
}
