<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CrossDepartmentWork;
use App\Support\ProjectRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdminApprovalQuery
{
    public function __construct(
        private readonly WorkOrderShareQuery $shares,
        private readonly NotificationService $notifications,
    ) {}

    /** @var array{assignments: int, shares: int, total: int}|null */
    private ?array $resolvedCounts = null;

    /**
     * @return array{
     *     pendingAssignments: Collection<int, WorkOrder>,
     *     shareJoinRequests: Collection<int, WorkOrderShareRequest>,
     *     approvalCounts: array{assignments: int, shares: int, total: int}
     * }
     */
    public function data(User $viewer): array
    {
        $pendingAssignments = $this->pendingAssignments($viewer);
        $shareRequests = $this->shares->headQueueFor($viewer);
        $this->resolvedCounts = $this->formatCounts(
            $pendingAssignments->count(),
            $shareRequests->count(),
        );

        return [
            'pendingAssignments' => $pendingAssignments,
            'shareJoinRequests' => $shareRequests,
            'approvalCounts' => $this->resolvedCounts,
        ];
    }

    /** @return Collection<int, WorkOrder> */
    public function pendingAssignments(User $viewer): Collection
    {
        $query = WorkOrder::with([
            'user.department',
            'department',
            'creator.department',
            'leader',
            'taskList',
        ])
            ->where('approval_status', 'pending');

        $this->scopeAssignments($query, $viewer);

        return $query
            ->latest('job_id')
            ->get();
    }

    /**
     * งานข้ามแผนกของทีม — ลูกทีมของแผนกที่กำลังเข้าร่วมโปรเจกต์ของแผนกอื่น
     *
     * เป็นมุมมองติดตาม (tracking/visibility) ไม่ใช่คิวรออนุมัติ หัวหน้าแผนกต้นสังกัด
     * "รับทราบ" ว่าลูกทีมไปร่วมโปรเจกต์ของใคร ไม่มีอะไรให้อนุมัติหรือปฏิเสธที่นี่
     * จึงไม่ถูกนับใน approvalCounts (ตัวเลขบนแถบข้างต้องเป็นงานที่รอการตัดสินใจเท่านั้น)
     *
     * หัวหน้าแผนกเห็นเฉพาะลูกทีมของแผนกตัวเอง admin เห็นทุกแผนก
     * นับเฉพาะผู้เข้าร่วมที่อยู่ในโปรเจกต์แล้ว บนงานที่อนุมัติแล้ว และแผนกเจ้าของโปรเจกต์
     * ไม่ใช่แผนกของพนักงาน
     *
     * @return Collection<int, array{task: WorkOrder, member: User, destination: string, leader: User|null}>
     */
    public function outgoingCollaborations(User $viewer): Collection
    {
        $departmentId = $viewer->role === 'admin' ? null : $viewer->department_id;

        if ($viewer->role !== 'admin' && $departmentId === null) {
            return collect();
        }

        $members = fn ($collaborators) => $collaborators
            ->where('work_order_collaborators.status', 'accepted')
            ->where('users.role', 'user')
            ->whereNotNull('users.department_id')
            ->when($departmentId !== null, fn ($query) => $query->where('users.department_id', $departmentId));

        return WorkOrder::query()
            ->with([
                'department',
                'user.department',
                // หัวหน้าโปรเจกต์คือผู้สร้าง (ProjectRoles) ตารางติดตามต้องบอกให้ได้ว่าใคร
                'creator.department',
                'leader.department',
                'taskList',
                'parent',
                'collaborators' => fn ($collaborators) => $members($collaborators)->with('department'),
            ])
            ->where('approval_status', 'approved')
            ->whereHas('collaborators', $members)
            ->when($departmentId !== null, fn (Builder $query) => $query->where(fn (Builder $elsewhere) => $elsewhere
                ->where(fn (Builder $explicit) => $explicit
                    ->whereNotNull('department_id')
                    ->where('department_id', '!=', $departmentId))
                ->orWhere(fn (Builder $fallback) => $fallback
                    ->whereNull('department_id')
                    ->whereHas('user', fn (Builder $assignee) => $assignee->where('department_id', '!=', $departmentId)))))
            ->orderByRaw('job_status = 4 asc')
            ->latest('job_id')
            ->get()
            ->flatMap(function (WorkOrder $task): Collection {
                $destination = CrossDepartmentWork::destinationDepartmentId($task);
                $leader = ProjectRoles::leader($task);

                return $task->collaborators
                    ->filter(fn (User $member) => $destination !== null && (int) $member->department_id !== $destination)
                    ->map(fn (User $member) => [
                        'task' => $task,
                        'member' => $member,
                        'destination' => CrossDepartmentWork::destinationDepartmentName($task) ?? 'ไม่ระบุแผนก',
                        'leader' => $leader,
                    ]);
            })
            ->values();
    }

    /** @return array{assignments: int, shares: int, total: int} */
    public function counts(?User $viewer = null): array
    {
        if ($this->resolvedCounts !== null) {
            return $this->resolvedCounts;
        }

        $assignmentQuery = WorkOrder::query()->where('approval_status', 'pending');
        $this->scopeAssignments($assignmentQuery, $viewer);

        // คำขอจากการแชร์งานอยู่ในตารางของตัวเอง ไม่ได้อยู่ใน pivot ผู้เข้าร่วม
        // จึงต้องนับแยกแล้วรวมเข้า total ซึ่งเป็นตัวเลขบนป้ายแถบข้าง
        $shareCount = $viewer ? $this->shares->headQueueCount($viewer) : 0;

        return $this->resolvedCounts = $this->formatCounts($assignmentQuery->count(), $shareCount);
    }

    /** @return array{assignments: int, shares: int, total: int} */
    private function formatCounts(int $assignments, int $shares): array
    {
        return [
            'assignments' => $assignments,
            'shares' => $shares,
            'total' => $assignments + $shares,
        ];
    }

    /**
     * ขอบเขตของคิวงานข้ามแผนก
     *
     * หัวหน้าแผนก — เห็นเฉพาะงานที่ปลายทางเป็นแผนกตัวเอง (เหมือนเดิม)
     *
     * admin — เดิมไม่กรองเลย จึงเห็นคำขอทั้งระบบรวมทั้งที่หัวหน้าแผนกถืออยู่แล้ว กลายเป็น
     * คิวซ้อนที่นับงานใบเดียวกันให้สองคน ตอนนี้เห็นเฉพาะงานที่ "ไม่มีหัวหน้าแผนกรับผิดชอบ"
     * ซึ่งเป็นเกณฑ์เดียวกับที่ departmentApprovalRecipientIds() ใช้ส่งแจ้งเตือน คิวกับ
     * แจ้งเตือนจึงตรงกันพอดี
     *
     * งานที่ department_id เป็น null ก็ไม่มีหัวหน้าเช่นกัน จึงเป็นของ admin ด้วย
     *
     * หมายเหตุ: นี่คือการจำกัด "สิ่งที่เห็น" ไม่ใช่ "อำนาจ" — WorkOrderPolicy::approve()
     * ยังเปิดให้ admin อนุมัติได้ทุกใบเมื่อเปิด URL ตรง ๆ ในฐานะอำนาจสำรอง
     */
    private function scopeAssignments(Builder $query, ?User $viewer): void
    {
        if (! $viewer) {
            return;
        }

        if ($viewer->role !== 'admin') {
            $query->where('department_id', $viewer->department_id);

            return;
        }

        $covered = $this->notifications->departmentIdsWithActiveHead();

        $query->where(fn (Builder $orphan) => $orphan
            ->whereNull('department_id')
            ->when($covered !== [], fn (Builder $q) => $q->orWhereNotIn('department_id', $covered))
            ->when($covered === [], fn (Builder $q) => $q->orWhereNotNull('department_id')));
    }
}
