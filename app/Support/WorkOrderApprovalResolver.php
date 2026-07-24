<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * คำนวณค่าที่เกี่ยวกับการอนุมัติงาน (approval_status, approved_by, approved_at,
 * leader_user_id) จาก actor (ผู้สร้าง/มอบหมายงาน) และ assignee (ผู้รับผิดชอบงาน)
 * ให้เป็น "แหล่งความจริงเดียว" (single source of truth) สำหรับทุก endpoint ที่สร้างงาน
 * (TaskController::store() และ MyTaskController::store()) เพื่อไม่ให้กติกาการอนุมัติ
 * เพี้ยนกันไปคนละทางเวลามีคนแก้ endpoint ใดเพียงจุดเดียว
 *
 * กติกา (สเปกกลางของระบบ):
 * - admin สร้างงานให้ใคร = approved ทันที (leader = ผู้รับมอบหมาย)
 * - user มอบหมายงานให้ตัวเอง หรือให้ user แผนกเดียวกัน = approved ทันที
 *   (leader = ผู้รับมอบหมาย ถ้าไม่ใช่ตัวเอง มิฉะนั้น leader = ผู้มอบหมายเอง)
 * - user มอบหมายงานให้ user ต่างแผนก = approval_status ต้องเป็น 'pending'
 *   (leader ยังไม่ถูกกำหนดเป็นผู้รับมอบหมาย จนกว่าจะอนุมัติ จึงใช้ผู้มอบหมายเป็น leader ชั่วคราว)
 */
class WorkOrderApprovalResolver
{
    /**
     * @return array{
     *     same_department: bool,
     *     approval_status: string,
     *     approved_by: int|null,
     *     approved_at: Carbon|null,
     *     leader_user_id: int,
     * }
     */
    public static function resolve(User $actor, User $assignee): array
    {
        $isAdmin = $actor->role === 'admin';
        $sameDepartment = self::isSameDepartment($actor, $assignee);
        $approved = $isAdmin || $sameDepartment;

        $leaderUserId = $isAdmin
            ? (int) $assignee->id
            : (($sameDepartment && (int) $assignee->id !== (int) $actor->id)
                ? (int) $assignee->id
                : (int) $actor->id);

        return [
            'same_department' => $sameDepartment,
            'approval_status' => $approved ? 'approved' : 'pending',
            'approved_by' => $approved ? $actor->id : null,
            'approved_at' => $approved ? now() : null,
            'leader_user_id' => $leaderUserId,
        ];
    }

    /**
     * มอบหมายให้ตัวเองถือว่า "แผนกเดียวกัน" เสมอ ไม่ว่าจะตั้งค่า department_id ไว้หรือไม่
     */
    public static function isSameDepartment(User $actor, User $assignee): bool
    {
        return (int) $assignee->id === (int) $actor->id
            || ($assignee->department_id && $actor->department_id
                && (int) $assignee->department_id === (int) $actor->department_id);
    }
}
