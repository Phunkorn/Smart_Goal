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
    /** @var array<int, array<int>> */
    private array $acceptedProjectIdsByUser = [];

    public function view(User $user, WorkOrderList $list): bool
    {
        if (in_array($user->role, ['admin', 'viewer'], true) || (int) $list->user_id === (int) $user->id) {
            return true;
        }

        return $list->workOrders()->involving($user)->exists();
    }

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
        return $user->role !== 'viewer' && $list->user_id === $user->id;
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

    /**
     * กติกาเปลี่ยนตามที่เจ้าของระบบกำหนด: ผู้ร่วมงาน (รวมถึงผู้ร่วมงานข้ามแผนก) ที่ได้รับ
     * การตอบรับเข้าโปรเจกต์แล้วสามารถเพิ่มงานหรืองานย่อยในโปรเจกต์นั้นได้ทันที โดยไม่ต้องรอ
     * เจ้าของโปรเจกต์อนุมัติ — เดิมเคยปิดกั้นผู้ร่วมงานข้ามแผนกไว้ที่นี่ (ดูประวัติ git)
     *
     * ทำได้อย่างปลอดภัยเพราะ ProjectTaskRequestController::store() ยังสร้างงานใหม่โดยให้
     * เจ้าของโปรเจกต์เป็น user_id/leader ของงานเสมอ (เหมือนเดิม) แล้วให้ผู้ขอเข้าร่วมเป็น
     * collaborator สถานะ accepted ทันที — WorkOrderApprovalResolver::resolve() มองว่านี่คือ
     * เจ้าของโปรเจกต์ "มอบหมายงานให้ตัวเอง" จึง approved ทันทีเสมอ ไม่ว่าผู้ขอจะต่างแผนก
     * หรือไม่ก็ตาม จึงไม่มีคิวอนุมัติข้ามแผนกที่ต้องรออีกต่อไป
     */
    public function requestTask(User $user, WorkOrderList $list): bool
    {
        $owner = $list->relationLoaded('user') ? $list->user : $list->user()->first();

        return $user->role !== 'viewer'
            && (int) $list->user_id !== (int) $user->id
            && $owner?->is_active
            && $owner->role !== 'viewer'
            && in_array((int) $list->id, $this->acceptedProjectIds($user), true);
    }

    /** @return array<int> */
    private function acceptedProjectIds(User $user): array
    {
        return $this->acceptedProjectIdsByUser[$user->id] ??= $user->joinedJobs()
            ->wherePivot('status', 'accepted')
            ->where('approval_status', 'approved')
            ->whereNotNull('work_order_list_id')
            ->distinct()
            ->pluck('work_order_list_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
