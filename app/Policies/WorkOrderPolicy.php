<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * รวม authorization logic ของ WorkOrder ที่แต่เดิมกระจายอยู่เป็น
 * abort_unless()/abort_if() ตรงๆ ใน TaskController และ MyTaskController
 * (รวมถึง private helper canWorkOnJob()/canManageTeam() ในอดีต) ให้มาอยู่
 * ที่เดียวกัน
 *
 * หมายเหตุสำคัญ: TaskController::canWorkOnJob() (เดิม) และ
 * MyTaskController::authorizeWorkOrderAccess() (เดิม) เป็น "เช็คแบบเดียวกัน"
 * อยู่แล้ว (admin เข้าได้เสมอ, หรือเป็นผู้รับผิดชอบ/ผู้สร้าง/หัวหน้างาน, หรือเป็น
 * collaborator ที่ status = accepted) ต่างกันแค่ authorizeWorkOrderAccess()
 * มีการเช็ค role !== 'viewer' เพิ่มเข้ามาอย่างชัดเจน (ซึ่งในทางปฏิบัติไม่มีผลต่าง
 * เพราะ viewer ไม่มีทางถูกกำหนดเป็นผู้รับผิดชอบ/ผู้สร้าง/หัวหน้างาน/collaborator
 * ได้ผ่าน endpoint สร้างงานหรือเพิ่มผู้ร่วมงานของระบบนี้อยู่แล้ว) จึงรวมเป็น
 * method update() เดียวโดยใส่การเช็ค viewer ไว้เพื่อความชัดเจนและปลอดภัยสูงสุด
 * โดยพฤติกรรมเดิมของทุก endpoint ที่เคยเรียกทั้งสอง helper นี้ไม่เปลี่ยนแปลง
 */
class WorkOrderPolicy
{
    /**
     * ดูบอร์ดงานทั้งหมด (TaskController::index)
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'viewer'], true);
    }

    /**
     * สร้างงานใหม่ (TaskController::store, MyTaskController::store,
     * MyTaskController::storeQuickTask) — เดิมแต่ละที่เช็คคนละแบบ
     * (in_array(role, ['admin','user']) กับ role !== 'viewer') แต่ระบบมีแค่
     * 3 role (admin/user/viewer) เท่านั้น จึงเทียบเท่ากันทุกกรณี
     */
    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * ดูรายละเอียดงาน (TaskController::show)
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return in_array($user->role, ['admin', 'viewer'], true)
            || in_array($user->id, [$workOrder->user_id, $workOrder->created_by, $workOrder->leader_user_id], true)
            || $workOrder->collaborators->contains(
                fn ($person) => $person->id === $user->id && $person->pivot?->status === 'accepted'
            );
    }

    /**
     * แก้ไข/ทำงานกับ WorkOrder ที่มีอยู่แล้ว ครอบคลุม:
     * TaskStatusController::updateStatus, TaskAttachmentController::uploadAttachments,
     * TaskProgressController::updateProgress, TaskController::requestDelete
     * MyTaskController::updateDueDate, toggleComplete, storeSubtask, toggleSubtask,
     * updateStatus, updatePriority
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer' || (int) $workOrder->job_status === 4) {
            return false;
        }

        return $this->canWorkOnJob($workOrder, $user);
    }

    public function submitForReview(User $user, WorkOrder $workOrder): bool
    {
        return $user->role !== 'viewer' && (int) $workOrder->user_id === (int) $user->id;
    }

    public function review(User $user, WorkOrder $workOrder): bool
    {
        return $user->role !== 'viewer'
            && (int) $this->approverId($workOrder) === (int) $user->id;
    }

    public function reopen(User $user, WorkOrder $workOrder): bool
    {
        return $user->role === 'admin' && (int) $workOrder->job_status === 4;
    }

    public function comment(User $user, WorkOrder $workOrder): bool
    {
        return $user->role !== 'viewer' && $this->canWorkOnJob($workOrder, $user);
    }

    public function respondToInvitation(User $user, WorkOrder $workOrder): bool
    {
        return $user->role !== 'viewer'
            && $workOrder->collaborators->contains('id', $user->id);
    }

    /**
     * ลบงานแบบ admin-only จากหน้าบอร์ด (TaskController::destroy) รวมถึงการ
     * ตัดสินใจคำขอลบงาน (TaskController::approveDeleteRequest, rejectDeleteRequest)
     * ซึ่งเดิมทุกจุดเช็คแค่ role === 'admin' เหมือนกัน
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->role !== 'viewer' && $user->role === 'admin';
    }

    /**
     * ลบงานของตัวเอง/งานที่ตนดูแล (MyTaskController::destroy) — ต่างจาก delete()
     * ตรงที่ไม่รวม collaborator และเปิดให้เจ้าของ/ผู้สร้าง/หัวหน้างานลบเองได้
     * ไม่ใช่แค่ admin (ถ้าเป็นงานที่ admin มอบหมาย controller จะเปลี่ยนเป็นคำขอลบแทน)
     */
    public function deleteOwn(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        return $user->role === 'admin'
            || $workOrder->user_id === $user->id
            || $workOrder->created_by === $user->id
            || $workOrder->leader_user_id === $user->id;
    }

    /**
     * อนุมัติ/ปฏิเสธการเปิดงาน (TaskStatusController::updateApproval) — admin เท่านั้น
     */
    public function approve(User $user): bool
    {
        return $user->role !== 'viewer' && $user->role === 'admin';
    }

    /**
     * จัดการทีม (เพิ่ม/นำผู้ร่วมงานออก) — TaskCollaboratorController::addCollaborators,
     * removeCollaborator (เดิมคือ canManageTeam())
     */
    public function manageTeam(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer' || (int) $workOrder->job_status === 4) {
            return false;
        }

        return $user->role === 'admin'
            || in_array($user->id, [$workOrder->created_by, $workOrder->leader_user_id], true);
    }

    /**
     * เดิมคือ TaskController::canWorkOnJob() / MyTaskController::authorizeWorkOrderAccess()
     */
    private function canWorkOnJob(WorkOrder $workOrder, User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return in_array($user->id, [$workOrder->user_id, $workOrder->created_by, $workOrder->leader_user_id], true)
            || $workOrder->collaborators->contains(
                fn ($person) => $person->id === $user->id && $person->pivot?->status === 'accepted'
            );
    }

    private function approverId(WorkOrder $workOrder): ?int
    {
        return $workOrder->created_by ?: ($workOrder->leader_user_id ?: $workOrder->user_id);
    }
}
