<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminApprovalQuery
{
    public function __construct(
        private readonly WorkOrderShareQuery $shares,
        private readonly NotificationService $notifications,
    ) {}

    /** @var array{assignments: int, collaborators: int, shares: int, total: int}|null */
    private ?array $resolvedCounts = null;

    /**
     * @return array{
     *     pendingAssignments: Collection<int, WorkOrder>,
     *     pendingCollaboratorTasks: Collection<int, WorkOrder>,
     *     pendingCollaboratorInviters: Collection<int, User>,
     *     approvalCounts: array{assignments: int, collaborators: int, shares: int, total: int}
     * }
     */
    public function data(User $viewer): array
    {
        $pendingAssignments = $this->pendingAssignments($viewer);
        $pendingCollaboratorTasks = $this->pendingCollaboratorTasks($viewer);
        $collaboratorCount = $pendingCollaboratorTasks
            ->sum(fn (WorkOrder $task): int => $task->collaborators->count());

        $shareRequests = $this->shares->headQueueFor($viewer);
        $this->resolvedCounts = $this->formatCounts(
            $pendingAssignments->count(),
            $collaboratorCount,
            $shareRequests->count(),
        );

        return [
            'pendingAssignments' => $pendingAssignments,
            'pendingCollaboratorTasks' => $pendingCollaboratorTasks,
            'pendingCollaboratorInviters' => $this->pendingCollaboratorInviters($pendingCollaboratorTasks),
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

    /** @return Collection<int, WorkOrder> */
    public function pendingCollaboratorTasks(User $viewer): Collection
    {
        // admin เห็นเฉพาะผู้ถูกเชิญที่แผนกไม่มีหัวหน้า ตามเหตุผลเดียวกับ scopeAssignments()
        $covered = $viewer->role === 'admin' ? $this->notifications->departmentIdsWithActiveHead() : [];

        $query = WorkOrder::with([
            'taskList',
            'creator.department',
            'collaborators' => function ($collaborators) use ($viewer, $covered): void {
                $collaborators->where('work_order_collaborators.status', 'pending')
                    ->when($viewer->role !== 'admin', fn ($query) => $query
                        ->where('users.department_id', $viewer->department_id))
                    ->when($viewer->role === 'admin', fn ($query) => $this->scopeOrphanCandidates($query, $covered))
                    ->with('department');
            },
        ])
            ->where('approval_status', 'approved')
            ->whereHas('collaborators', fn ($collaborators) => $collaborators
                ->where('work_order_collaborators.status', 'pending')
                ->when($viewer->role !== 'admin', fn ($query) => $query
                    ->where('users.department_id', $viewer->department_id))
                ->when($viewer->role === 'admin', fn ($query) => $this->scopeOrphanCandidates($query, $covered)))
            ->latest('job_id')
            ->get();

        return $query;
    }

    /**
     * @param  Collection<int, WorkOrder>  $tasks
     * @return Collection<int, User>
     */
    public function pendingCollaboratorInviters(Collection $tasks): Collection
    {
        return User::query()
            ->with('department')
            ->whereIn('id', $tasks
                ->flatMap(fn (WorkOrder $task) => $task->collaborators->pluck('pivot.added_by'))
                ->filter()
                ->unique())
            ->get()
            ->keyBy('id');
    }

    /** @return array{assignments: int, collaborators: int, shares: int, total: int} */
    public function counts(?User $viewer = null): array
    {
        if ($this->resolvedCounts !== null) {
            return $this->resolvedCounts;
        }

        $assignmentQuery = WorkOrder::query()->where('approval_status', 'pending');
        $this->scopeAssignments($assignmentQuery, $viewer);
        $assignmentCount = $assignmentQuery->count();

        $collaboratorQuery = DB::table('work_order_collaborators')
            ->join('work_orders', 'work_orders.job_id', '=', 'work_order_collaborators.work_order_id')
            ->whereNull('work_orders.deleted_at')
            ->where('work_orders.approval_status', 'approved')
            ->where('work_order_collaborators.status', 'pending');

        if ($viewer && $viewer->role !== 'admin') {
            $collaboratorQuery
                ->join('users as collaborator_users', 'collaborator_users.id', '=', 'work_order_collaborators.user_id')
                ->where('collaborator_users.department_id', $viewer->department_id);
        }

        if ($viewer && $viewer->role === 'admin') {
            $collaboratorQuery
                ->join('users as collaborator_users', 'collaborator_users.id', '=', 'work_order_collaborators.user_id');

            $this->scopeOrphanCandidates(
                $collaboratorQuery,
                $this->notifications->departmentIdsWithActiveHead(),
                'collaborator_users.department_id'
            );
        }

        $collaboratorCount = $collaboratorQuery->count();

        // คำขอจากการแชร์งานอยู่ในตารางของตัวเอง ไม่ได้อยู่ใน pivot ผู้ร่วมงาน
        // จึงต้องนับแยกแล้วรวมเข้า total ซึ่งเป็นตัวเลขบนป้ายแถบข้าง
        $shareCount = $viewer ? $this->shares->headQueueCount($viewer) : 0;

        return $this->resolvedCounts = $this->formatCounts($assignmentCount, $collaboratorCount, $shareCount);
    }

    /** @return array{assignments: int, collaborators: int, shares: int, total: int} */
    private function formatCounts(int $assignments, int $collaborators, int $shares): array
    {
        return [
            'assignments' => $assignments,
            'collaborators' => $collaborators,
            'shares' => $shares,
            'total' => $assignments + $collaborators + $shares,
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

    /**
     * เงื่อนไขว่าผู้ถูกเชิญคนนี้ "ไม่มีหัวหน้าแผนกดูแล" — ใช้ร่วมกันทุกที่ที่กรองคิวผู้ร่วมงาน
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder  $query
     */
    private function scopeOrphanCandidates($query, array $covered, string $column = 'users.department_id')
    {
        return $query->where(fn ($orphan) => $orphan
            ->whereNull($column)
            ->when($covered !== [], fn ($q) => $q->orWhereNotIn($column, $covered))
            ->when($covered === [], fn ($q) => $q->orWhereNotNull($column)));
    }
}
