<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrderShare;
use App\Models\WorkOrderShareRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * การอ่านข้อมูลของหน้าแชร์งาน
 *
 * แยกออกจาก WorkOrderShareService เพราะที่นั่นเป็นการเปลี่ยนสถานะ ส่วนที่นี่เป็น
 * การจัดรูป query ให้หน้าจอ — แบบเดียวกับที่ AdminApprovalQuery แยกจาก
 * TaskCollaboratorController
 */
class WorkOrderShareQuery
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * ประกาศที่ผู้ใช้คนนี้เห็นและยังกดขอเข้าร่วมได้
     *
     * ตัดงานที่ตัวเองเกี่ยวข้องอยู่แล้วออก เพราะการ์ดที่กดไม่ได้คือสิ่งรบกวน ไม่ใช่ข้อมูล
     *
     * @return Collection<int, WorkOrderShare>
     */
    public function feedFor(User $viewer): Collection
    {
        if ($viewer->role === 'viewer') {
            return collect();
        }

        return WorkOrderShare::query()
            ->with($this->cardRelations())
            ->open()
            ->visibleTo($viewer)
            ->where('shared_by', '!=', $viewer->id)
            // งานที่ปิดแล้วรับคนเพิ่มไม่ได้ และงานที่ยังไม่ผ่านอนุมัติก็เช่นกัน
            ->whereHas('workOrder', fn ($task) => $task
                ->where('job_status', '!=', 4)
                ->where('approval_status', 'approved'))
            ->whereDoesntHave('workOrder.collaborators', fn ($user) => $user->where('users.id', $viewer->id))
            ->whereDoesntHave('workOrder', fn ($task) => $task
                ->where(fn ($owned) => $owned
                    ->where('user_id', $viewer->id)
                    ->orWhere('created_by', $viewer->id)
                    ->orWhere('leader_user_id', $viewer->id)))
            ->latest('id')
            ->get()
            ->each(fn (WorkOrderShare $share) => $share->setAttribute(
                'viewer_request',
                $share->requests->firstWhere('requester_id', $viewer->id)
            ));
    }

    /**
     * ประกาศที่ฉันเป็นคนแชร์ และยังเปิดรับอยู่
     *
     * ประกาศที่ปิดแล้วไม่ถูกคืนมาเลย — ปิดคือปิด ไม่ใช่ค้างไว้เป็นรายการสีจาง
     * ของเดิมยังแสดงพร้อมป้าย "ปิดแล้ว" ซึ่งกลายเป็นประวัติว่าครั้งหนึ่งเคยเปิดแชร์
     * งานใบไหนไว้บ้าง ทั้งที่ผู้แชร์ตั้งใจกดปิดเพื่อให้มันหายไป และรายการที่กดอะไร
     * ไม่ได้แล้วก็เป็นสิ่งรบกวน ไม่ใช่ข้อมูล — เกณฑ์เดียวกับที่ feed ใช้ตัดการ์ดที่กดไม่ได้ทิ้ง
     *
     * ประวัติการแชร์ยังอยู่ครบในฐานข้อมูล (แถวไม่ได้ถูกลบ) และคำขอที่เคยตัดสินไป
     * ยังตรวจสอบย้อนหลังได้จากแท็บคำขอตามเดิม
     *
     * @return Collection<int, WorkOrderShare>
     */
    public function mySharesFor(User $viewer): Collection
    {
        return WorkOrderShare::query()
            ->with($this->cardRelations())
            ->open()
            ->where('shared_by', $viewer->id)
            ->latest('id')
            ->get();
    }

    /**
     * คำขอที่รอฉันตัดสิน — ฉันคือผู้แชร์
     *
     * @return Collection<int, WorkOrderShareRequest>
     */
    public function incomingRequestsFor(User $viewer): Collection
    {
        return WorkOrderShareRequest::query()
            ->with(['requester.department', 'share.workOrder.taskList'])
            ->pending()
            ->whereHas('share', fn ($share) => $share
                ->where('shared_by', $viewer->id)
                ->where('status', 'open'))
            ->latest('id')
            ->get();
    }

    /**
     * คำขอที่ฉันส่งไป
     *
     * @return Collection<int, WorkOrderShareRequest>
     */
    public function myRequestsFor(User $viewer): Collection
    {
        return WorkOrderShareRequest::query()
            ->with(['share.workOrder.taskList', 'share.sharer.department', 'decider'])
            ->where('requester_id', $viewer->id)
            ->latest('id')
            ->get();
    }

    /**
     * คำขอที่รอหัวหน้าแผนกของผู้แชร์ตัดสิน — ชั้นที่สอง
     *
     * ขอบเขตผูกกับแผนกปลายทางของ "งาน" ให้ตรงกับ WorkOrderSharePolicy::reviewAsHead()
     * ถ้าสองที่นี้ใช้เกณฑ์คนละแบบ จะเกิดกรณีที่เห็นรายการแต่กดไม่ได้ หรือกดได้แต่ไม่เห็น
     *
     * งานที่ไม่มี department_id ของตัวเองให้ตกไปใช้แผนกของผู้รับผิดชอบ ซึ่งเป็น
     * fallback ชุดเดียวกับที่ policy ทั้งระบบใช้
     *
     * @return Collection<int, WorkOrderShareRequest>
     */
    public function headQueueFor(User $viewer): Collection
    {
        $query = $this->headQueue($viewer);

        if ($query === null) {
            return collect();
        }

        return $query
            ->with(['requester.department', 'share.sharer.department', 'share.workOrder.taskList', 'share.workOrder.user.department'])
            ->latest('id')
            ->get();
    }

    /**
     * ตัวนับคำขอชั้นที่สอง
     *
     * ต้องเป็น count query จริง ไม่ใช่นับจากผลของ headQueueFor() เพราะตัวนับนี้ถูกเรียก
     * บนทุกหน้าที่ render แถบข้าง การดึงทั้งแถวพร้อม eager load มาเพื่อนับจำนวนคือ
     * ภาระที่ไม่จำเป็น (AdminApprovalPageTest ตรวจข้อนี้อยู่)
     */
    public function headQueueCount(User $viewer): int
    {
        return $this->headQueue($viewer)?->count() ?? 0;
    }

    /**
     * โครง query ร่วมของคิวชั้นที่สอง — คืน null เมื่อผู้ใช้ไม่มีสิทธิ์เห็นคิวนี้เลย
     *
     * @return Builder<WorkOrderShareRequest>|null
     */
    private function headQueue(User $viewer): ?Builder
    {
        if ($viewer->role !== 'admin' && ! $viewer->isDepartmentHead()) {
            return null;
        }

        $query = WorkOrderShareRequest::query()
            ->awaitingHead()
            ->whereHas('share', fn ($share) => $share->where('status', 'open'));

        if ($viewer->role !== 'admin') {
            return $query->whereHas('share.workOrder', fn ($task) => $task
                ->where('department_id', $viewer->department_id)
                ->orWhere(fn ($fallback) => $fallback
                    ->whereNull('department_id')
                    ->whereHas('user', fn ($owner) => $owner->where('department_id', $viewer->department_id))));
        }

        /*
         * admin เห็นเฉพาะคำขอที่ไม่มีหัวหน้าแผนกรับผิดชอบ
         *
         * เกณฑ์เดียวกับ AdminApprovalQuery::scopeAssignments() และกับที่
         * WorkOrderShareService ใช้เลือกผู้รับแจ้งเตือนตอน escalate คิวกับแจ้งเตือนจึง
         * ตรงกัน ไม่ใช่ admin เห็นทุกคำขอทั้งระบบเหมือนเดิม
         */
        $covered = $this->notifications->departmentIdsWithActiveHead();

        return $query->whereHas('share.workOrder', fn ($task) => $task
            ->where(fn ($orphan) => $orphan
                ->where(fn ($noDepartment) => $noDepartment
                    ->whereNull('department_id')
                    ->whereDoesntHave('user', fn ($owner) => $owner->whereIn('department_id', $covered)))
                ->when($covered !== [], fn ($q) => $q->orWhereNotIn('department_id', $covered))
                ->when($covered === [], fn ($q) => $q->orWhereNotNull('department_id'))));
    }

    /** ตัวนับสำหรับป้ายบนแถบข้าง */
    public function pendingIncomingCount(User $viewer): int
    {
        if ($viewer->role === 'viewer') {
            return 0;
        }

        return WorkOrderShareRequest::query()
            ->pending()
            ->whereHas('share', fn ($share) => $share
                ->where('shared_by', $viewer->id)
                ->where('status', 'open'))
            ->count();
    }

    /** @return array<int, string> */
    private function cardRelations(): array
    {
        return [
            'workOrder.taskList',
            'workOrder.user.department',
            /*
             * สิ่งที่แชร์ได้คืองานย่อย การ์ดจึงต้องบอกบริบทว่างานย่อยใบนี้อยู่ใต้งานไหน
             * ไม่งั้นคนอ่านเห็นแค่ชื่อรายการสั้น ๆ โดยไม่รู้ว่าเป็นส่วนหนึ่งของงานอะไร
             */
            'workOrder.parent',
            'sharer.department',
            'department',
            'requests',
        ];
    }
}
