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
            return $user->role === 'admin'
                || $this->isAssignmentRequester($workOrder, $user)
                || $user->overseesDepartment($this->destinationDepartmentId($workOrder));
        }

        return in_array($user->role, ['admin', 'viewer'], true)
            || $user->overseesDepartment($this->destinationDepartmentId($workOrder))
            || $this->isTaskParticipant($workOrder, $user)
            || $this->overseesMemberOnTask($workOrder, $user)
            /*
             * สิทธิ์ระดับโปรเจกต์ให้เฉพาะคนในแผนกเดียวกับงานใบนั้น
             *
             * คนต่างแผนกที่ถูกเชิญมาช่วยงานหนึ่งใบ เคยได้เห็นงานทุกใบในโปรเจกต์นั้น
             * ตามไปด้วย ทั้งที่เขาถูกเชิญมาเพื่องานเดียว การเชิญข้ามแผนกครั้งเดียว
             * จึงเปิดงานทั้งโปรเจกต์ของอีกแผนกให้เขาเห็นหมด
             *
             * ตอนนี้เขายังเห็นงานที่ตัวเองร่วม (isTaskParticipant ด้านบน) เหมือนเดิม
             * แต่ไม่ได้สิทธิ์เหมารวมทั้งโปรเจกต์อีก ส่วนคนในแผนกเดียวกันยังเห็นทั้งโปรเจกต์
             * เพราะงานเหล่านั้นเป็นงานของแผนกตัวเองอยู่แล้ว
             */
            || ($workOrder->work_order_list_id
                && $this->sharesDepartmentWith($workOrder, $user)
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
     * งานใบนี้เป็น "งานของผู้ใช้คนนี้" หรือไม่ — ตอบเรื่องความเป็นเจ้าของ ไม่ใช่เรื่องสิทธิ์ตอนนี้
     *
     * work() ตอบคนละคำถาม: "แก้สถานะได้ตอนนี้ไหม" ซึ่งเป็นเท็จเสมอเมื่องานปิดแล้ว
     * และเป็นเท็จเมื่องานยังรออนุมัติ มุมมองตารางต้องการอีกคำถามหนึ่งคือ
     * "งานใบนี้เป็นของฉันไหม" เพื่อตัดงานของคนอื่นในโปรเจกต์เดียวกันออกจากกระดาน
     * โดยไม่ทำให้งานของตัวเองที่ปิดแล้วหรือที่ยังรออนุมัติหายไปด้วย
     *
     * ถ้าใช้ work() กรองแทน คอลัมน์ "เสร็จแล้ว" จะว่างทั้งคอลัมน์ เพราะ work() ปฏิเสธ
     * งานสถานะ 4 ทุกใบรวมงานของเจ้าของเอง
     */
    public function participate(User $user, WorkOrder $workOrder): bool
    {
        return $this->isTaskParticipant($workOrder, $user);
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

    /**
     * เพิ่ม เปลี่ยนชื่อ ย้าย หรือลบ "งานย่อย" ของงานใบนี้
     *
     * แคบกว่า work() โดยตั้งใจ — work() เปิดให้ผู้ร่วมงานที่ตอบรับแล้วทุกคน ซึ่งถูกต้อง
     * สำหรับการลงมือทำงาน (เปลี่ยนสถานะ แนบไฟล์ คอมเมนต์) แต่การกำหนดว่างานใบนี้
     * ประกอบด้วยงานย่อยอะไรบ้างคือการ "นิยามขอบเขตงาน" ไม่ใช่การลงมือทำ
     *
     * ของเดิมใช้ work() ผลคือคนที่ถูกเชิญมาช่วยงานหนึ่งใบ สร้างงานย่อยในงานของ
     * เจ้าของได้เอง และเพราะผู้สร้างงานย่อยกลายเป็น created_by ของงานย่อยนั้น
     * เขาจึงได้สิทธิ์ระดับเจ้าของบนงานย่อย รวมถึงแชร์มันออกไปให้คนนอกขอเข้าร่วมได้
     * ทั้งที่ไม่เคยเป็นเจ้าของงานนั้นเลย
     *
     * ผู้รับผิดชอบ ผู้สร้าง และหัวหน้างานยังทำได้ตามเดิม เพราะทั้งสามคนคือผู้ที่
     * รับผิดชอบเนื้องานใบนั้นจริง
     */
    public function manageSubtasks(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer' || (int) $workOrder->job_status === 4) {
            return false;
        }

        return $workOrder->approval_status === 'approved'
            && $this->isTaskEditor($workOrder, $user);
    }

    /**
     * ทุกคนในงานส่งเข้าขั้นตรวจสอบได้ รวมถึงผู้มอบหมายเอง
     *
     * เดิมผู้มอบหมายถูกกันออกด้วย isAssignmentApprover() ผลคือถ้าผู้รับผิดชอบไม่กดส่งตรวจ
     * งานจะตันสนิท โดยเฉพาะสถานะล่าช้าที่ถอยกลับไม่ได้เลย — หัวหน้าจึงไม่มีทางไปต่อได้
     * ตอนนี้หัวหน้าดึงงานเข้าขั้นตรวจเองได้ แล้วปิดงานต่อผ่านขั้นตรวจตามเดิม
     * ขั้นตรวจสอบยังอยู่ครบ และ AuditTrail บันทึกว่าใครเป็นคนดึงเข้าตรวจทุกครั้ง
     */
    public function submitForReview(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->approval_status === 'approved'
            && $user->role !== 'viewer'
            && $this->isTaskParticipant($workOrder, $user);
    }

    public function review(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->approval_status === 'approved'
            && $user->role !== 'viewer'
            && (int) $this->approverId($workOrder) === (int) $user->id;
    }

    public function reopen(User $user, WorkOrder $workOrder): bool
    {
        if ($user->role === 'viewer' || (int) $workOrder->job_status !== 4) {
            return false;
        }

        return $user->role === 'admin'
            || (int) $this->approverId($workOrder) === (int) $user->id;
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

    /**
     * เขียนความคิดเห็นในงาน
     *
     * หัวหน้าแผนกปลายทางคอมเมนต์ได้ แม้ไม่ได้เป็นผู้รับผิดชอบหรือผู้ร่วมงาน
     *
     * เดิมสิทธิ์นี้จำกัดที่ isTaskParticipant() อย่างเดียว หัวหน้าแผนกจึงเปิดงาน
     * ของลูกทีมได้ อ่านความคิดเห็นได้ (viewComments อนุญาต overseesDepartment
     * อยู่แล้ว) แต่ตอบกลับในงานเดียวกันไม่ได้ ต้องไปคุยนอกระบบแทน ซึ่งทำให้
     * บทสนทนาที่ควรอยู่คู่กับงานหายไปจากประวัติ
     *
     * ขอบเขตยังแคบกว่า viewComments() เสมอ เพราะที่นี่ไม่รวมผู้ร่วมงานของโปรเจกต์
     * ที่ไม่ได้อยู่ในงานนี้ ทุกคนที่คอมเมนต์ได้จึงอ่านความคิดเห็นได้แน่นอน
     */
    public function comment(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->approval_status === 'approved'
            && $user->role !== 'viewer'
            && (int) $workOrder->job_status !== 4
            && ($this->isTaskParticipant($workOrder, $user)
                || $user->overseesDepartment($this->destinationDepartmentId($workOrder))
                || $this->overseesMemberOnTask($workOrder, $user));
    }

    public function viewComments(User $user, WorkOrder $workOrder): bool
    {
        if ($workOrder->approval_status !== 'approved' || $user->role === 'viewer') {
            return false;
        }

        return $user->role === 'admin'
            || $user->overseesDepartment($this->destinationDepartmentId($workOrder))
            || $this->isTaskParticipant($workOrder, $user)
            || $this->overseesMemberOnTask($workOrder, $user)
            || ($workOrder->work_order_list_id
                && in_array((int) $workOrder->work_order_list_id, $this->acceptedProjectIds($user), true));
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
    public function approve(User $user, ?WorkOrder $workOrder = null): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->isDepartmentHead()
            && ($workOrder === null
                || $user->overseesDepartment($this->destinationDepartmentId($workOrder)));
    }

    public function approveCollaborator(User $user, ?WorkOrder $workOrder = null, ?User $candidate = null): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->isDepartmentHead()
            && ($candidate === null || $user->overseesDepartment($candidate->department_id));
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
     * ประกาศแชร์งานให้คนอื่นขอเข้าร่วม (WorkOrderShareController::store)
     *
     * แชร์ได้เฉพาะ "งานย่อย" เท่านั้น
     *
     * งานแม่คือขอบเขตงานทั้งก้อนของเจ้าของ การเปิดให้คนนอกขอเข้าร่วมที่ระดับนั้น
     * เท่ากับรับเขาเข้ามาในงานทุกใบที่อยู่ใต้มัน ทั้งที่สิ่งที่ต้องการจริง ๆ คือให้มา
     * ช่วยงานชิ้นใดชิ้นหนึ่ง การแชร์จึงต้องเกิดที่หน่วยที่เล็กที่สุดที่ลงมือทำได้จริง
     * คือรายการงานย่อย ผู้ที่เข้าร่วมจะได้สิทธิ์เท่าที่งานย่อยใบนั้นให้ ไม่เลยไปกว่านั้น
     *
     * เงื่อนไขที่เหลือใช้เกณฑ์เดียวกับ manageTeam() ทั้งดุ้น เพราะการแชร์คือการเปิดทาง
     * ให้มีผู้ร่วมงานเพิ่ม คนที่แชร์ได้จึงต้องเป็นคนที่เพิ่มผู้ร่วมงานได้อยู่แล้ว
     * ถ้าแยกเกณฑ์ออกมาจะเกิดช่องที่แชร์งานได้แต่อนุมัติคำขอของตัวเองไม่ได้
     */
    public function share(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->parent_job_id !== null
            && $this->manageTeam($user, $workOrder);
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

    /**
     * หัวหน้าแผนกที่ลูกทีมของตัวเองอยู่ในงานใบนี้
     *
     * ลูกทีม = ผู้รับผิดชอบ ผู้สร้างงาน หัวหน้างาน หรือผู้ร่วมงานที่ตอบรับแล้ว ซึ่งอยู่แผนกของหัวหน้า
     * นิยามเดียวกับ "ผลงาน" ในรายงานโปรเจกต์ (WorkOrder::scopeContributedBy) หัวหน้าจึงเปิดดู
     * ทุกงานที่ขึ้นในภาพรวมแผนกได้จริง
     *
     * งานย่อยได้สิทธิ์นี้ต่อจากงานแม่ด้วย — ลูกทีมไปร่วมงานข้ามแผนก หัวหน้าต้นสังกัดต้องเห็น
     * งานย่อยและไฟล์ของงานนั้นครบ แม้ลูกทีมไม่ได้อยู่ในงานย่อยทุกใบ
     *
     * ใช้กับงานข้ามแผนก — หัวหน้าต้นสังกัดดูงานและคอมเมนต์ได้ แต่ห้ามแก้เวลาหรือเปลี่ยนสถานะ
     * จึงถูกเติมเฉพาะ view / comment / viewComments ไม่ถูกเติมใน work() หรือ ability ใดที่แก้งาน
     */
    private function overseesMemberOnTask(WorkOrder $workOrder, User $user): bool
    {
        if (! $user->isDepartmentHead()) {
            return false;
        }

        $departmentId = (int) $user->department_id;

        if ($this->involvesDepartmentMember($workOrder, $departmentId)) {
            return true;
        }

        if ($workOrder->parent_job_id === null) {
            return false;
        }

        $parent = $workOrder->relationLoaded('parent') ? $workOrder->parent : $workOrder->parent()->first();

        return $parent !== null && $this->involvesDepartmentMember($parent, $departmentId);
    }

    private function involvesDepartmentMember(WorkOrder $workOrder, int $departmentId): bool
    {
        foreach (['user', 'creator', 'leader'] as $relation) {
            if ($workOrder->{$relation}?->department_id !== null
                && (int) $workOrder->{$relation}->department_id === $departmentId) {
                return true;
            }
        }

        if ($workOrder->relationLoaded('collaborators')) {
            return $workOrder->collaborators->contains(
                fn ($person) => $person->pivot?->status === 'accepted'
                    && (int) $person->department_id === $departmentId
            );
        }

        return $workOrder->collaborators()
            ->wherePivot('status', 'accepted')
            ->where('users.department_id', $departmentId)
            ->exists();
    }

    /**
     * โปรเจกต์ที่ผู้ใช้ได้สิทธิ์ระดับโปรเจกต์ — นับเฉพาะงานในแผนกของตัวเอง
     *
     * ต้องใช้เกณฑ์เดียวกับ WorkOrder::scopeVisibleInProjectsFor() ทุกประการ ไม่งั้น
     * จะเกิดสภาพที่ policy บอกว่าดูได้แต่คิวรีไม่คืนงานมา (หรือกลับกัน)
     *
     * @return array<int>
     */
    private function acceptedProjectIds(User $user): array
    {
        // คนที่ยังไม่ถูกจัดเข้าแผนก ไม่มีแผนกให้เทียบ จึงไม่ได้สิทธิ์เหมาทั้งโปรเจกต์
        if ($user->department_id === null) {
            return $this->acceptedProjectIdsByUser[$user->id] ??= [];
        }

        return $this->acceptedProjectIdsByUser[$user->id] ??= WorkOrder::query()
            ->whereNotNull('work_order_list_id')
            ->inDepartmentOf($user)
            ->whereHas('collaborators', fn ($query) => $query
                ->where('users.id', $user->id)
                ->where('work_order_collaborators.status', 'accepted'))
            ->distinct()
            ->pluck('work_order_list_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** งานใบนี้มีแผนกปลายทางเดียวกับผู้ใช้หรือไม่ */
    private function sharesDepartmentWith(WorkOrder $workOrder, User $user): bool
    {
        return $user->department_id !== null
            && (int) $this->destinationDepartmentId($workOrder) === (int) $user->department_id;
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

    private function destinationDepartmentId(WorkOrder $workOrder): ?int
    {
        return $workOrder->department_id ?: $workOrder->user?->department_id;
    }
}
