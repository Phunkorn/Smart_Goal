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
    /** @var array<int, array<int>> */
    private array $acceptedProjectIdsByUser = [];

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
        if ($workOrder->approval_status !== 'approved') {
            return $user->role === 'admin' || $this->isAssignmentRequester($workOrder, $user);
        }

        return in_array($user->role, ['admin', 'viewer'], true)
            || $this->isTaskParticipant($workOrder, $user)
            || ($workOrder->work_order_list_id
                && in_array((int) $workOrder->work_order_list_id, $this->acceptedProjectIds($user), true));
    }

    /**
     * แก้ไข/ทำงานกับ WorkOrder ที่มีอยู่แล้ว ครอบคลุม:
     * Management-level task changes. Worker actions use work() so a direct
     * collaborator never inherits team, delete, assignment, or approval power.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer' || (int) $workOrder->job_status === 4) {
            return false;
        }

        if ($workOrder->approval_status !== 'approved') {
            return $user->role === 'admin';
        }

        return $this->isTaskEditor($workOrder, $user);
    }

    /**
     * Worker-level mutations only. Direct accepted collaborators work on the
     * task, but this ability is deliberately not used by team/delete/approval
     * endpoints.
     */
    public function work(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer' || (int) $workOrder->job_status === 4) {
            return false;
        }

        return $workOrder->approval_status === 'approved'
            && $this->isTaskParticipant($workOrder, $user);
    }

    public function submitForReview(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->approval_status === 'approved'
            && $user->role !== 'viewer'
            && ($this->isTaskParticipant($workOrder, $user))
            && ! $this->isAssignmentApprover($workOrder, $user);
    }

    public function review(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->approval_status === 'approved'
            && $user->role !== 'viewer'
            && (int) $this->approverId($workOrder) === (int) $user->id;
    }

    public function reopen(User $user, WorkOrder $workOrder): bool
    {
        return $user->role === 'admin' && (int) $workOrder->job_status === 4;
    }

    /**
     * Administrative status correction for approved, active work only.
     * Completed work must continue through the explicit reopen action.
     */
    public function overrideStatus(User $user, WorkOrder $workOrder): bool
    {
        return $user->role === 'admin'
            && $workOrder->approval_status === 'approved'
            && (int) $workOrder->job_status !== 4;
    }

    public function comment(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->approval_status === 'approved'
            && $user->role !== 'viewer'
            && $this->isTaskParticipant($workOrder, $user);
    }

    public function respondToInvitation(User $user, WorkOrder $workOrder): bool
    {
        return false;
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

        if ($workOrder->approval_status !== 'approved') {
            return $user->role === 'admin';
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

    public function approveCollaborator(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * จัดการทีม (เพิ่ม/นำผู้ร่วมงานออก) — TaskCollaboratorController::addCollaborators,
     * removeCollaborator (เดิมคือ canManageTeam())
     */
    public function manageTeam(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        // Admin จัดการทีมได้แม้งานปิดแล้ว ซึ่งตรงกับที่ TaskCollaboratorController
        // และธง locked ใน Blade สื่อไว้ตลอด (เดิมเงื่อนไข job_status === 4 อยู่เหนือบรรทัดนี้
        // จึงบล็อกทุก role รวม Admin ทำให้ abort_if ใน controller กลายเป็น dead code)
        if ($user->role === 'admin') {
            return true;
        }

        if ($workOrder->approval_status !== 'approved') {
            return false;
        }

        return (int) $workOrder->job_status !== 4
            && in_array($user->id, [$workOrder->created_by, $workOrder->leader_user_id], true);
    }

    /**
     * เดิมคือ TaskController::canWorkOnJob() / MyTaskController::authorizeWorkOrderAccess()
     */
    private function isTaskEditor(WorkOrder $workOrder, User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return in_array($user->id, [$workOrder->user_id, $workOrder->created_by, $workOrder->leader_user_id], true);
    }

    private function isTaskParticipant(WorkOrder $workOrder, User $user): bool
    {
        if ($this->isTaskEditor($workOrder, $user)) {
            return true;
        }

        if ($workOrder->relationLoaded('collaborators')) {
            return $workOrder->collaborators->contains(
                fn ($person) => (int) $person->id === (int) $user->id && $person->pivot?->status === 'accepted'
            );
        }

        return $workOrder->collaborators()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'accepted')
            ->exists();
    }

    /** @return array<int> */
    private function acceptedProjectIds(User $user): array
    {
        return $this->acceptedProjectIdsByUser[$user->id] ??= WorkOrder::query()
            ->where('approval_status', 'approved')
            ->whereNotNull('work_order_list_id')
            ->whereHas('collaborators', fn ($query) => $query
                ->where('users.id', $user->id)
                ->where('work_order_collaborators.status', 'accepted'))
            ->distinct()
            ->pluck('work_order_list_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function isAssignmentRequester(WorkOrder $workOrder, User $user): bool
    {
        return in_array($user->id, [
            $workOrder->created_by,
            $workOrder->assigned_by,
            $workOrder->leader_user_id,
        ], true);
    }

    private function approverId(WorkOrder $workOrder): ?int
    {
        return $workOrder->created_by ?: ($workOrder->leader_user_id ?: $workOrder->user_id);
    }

    private function isAssignmentApprover(WorkOrder $workOrder, User $user): bool
    {
        return (int) $this->approverId($workOrder) === (int) $user->id
            && (int) $workOrder->user_id !== (int) $user->id;
    }
}
