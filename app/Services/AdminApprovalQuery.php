<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminApprovalQuery
{
    /** @var array{assignments: int, collaborators: int, total: int}|null */
    private ?array $resolvedCounts = null;

    /**
     * @return array{
     *     pendingAssignments: Collection<int, WorkOrder>,
     *     pendingCollaboratorTasks: Collection<int, WorkOrder>,
     *     pendingCollaboratorInviters: Collection<int, User>,
     *     approvalCounts: array{assignments: int, collaborators: int, total: int}
     * }
     */
    public function data(): array
    {
        $pendingAssignments = $this->pendingAssignments();
        $pendingCollaboratorTasks = $this->pendingCollaboratorTasks();
        $collaboratorCount = $pendingCollaboratorTasks
            ->sum(fn (WorkOrder $task): int => $task->collaborators->count());

        $this->resolvedCounts = $this->formatCounts($pendingAssignments->count(), $collaboratorCount);

        return [
            'pendingAssignments' => $pendingAssignments,
            'pendingCollaboratorTasks' => $pendingCollaboratorTasks,
            'pendingCollaboratorInviters' => $this->pendingCollaboratorInviters($pendingCollaboratorTasks),
            'approvalCounts' => $this->resolvedCounts,
        ];
    }

    /** @return Collection<int, WorkOrder> */
    public function pendingAssignments(): Collection
    {
        return WorkOrder::with([
            'user.department',
            'department',
            'creator.department',
            'leader',
            'taskList',
        ])
            ->where('approval_status', 'pending')
            ->latest('job_id')
            ->get();
    }

    /** @return Collection<int, WorkOrder> */
    public function pendingCollaboratorTasks(): Collection
    {
        return WorkOrder::with([
            'taskList',
            'creator.department',
            'collaborators' => fn ($collaborators) => $collaborators
                ->where('work_order_collaborators.status', 'pending')
                ->with('department'),
        ])
            ->where('approval_status', 'approved')
            ->whereHas('collaborators', fn ($collaborators) => $collaborators
                ->where('work_order_collaborators.status', 'pending'))
            ->latest('job_id')
            ->get();
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

    /** @return array{assignments: int, collaborators: int, total: int} */
    public function counts(): array
    {
        if ($this->resolvedCounts !== null) {
            return $this->resolvedCounts;
        }

        $assignmentCount = WorkOrder::query()
            ->where('approval_status', 'pending')
            ->count();

        $collaboratorCount = DB::table('work_order_collaborators')
            ->join('work_orders', 'work_orders.job_id', '=', 'work_order_collaborators.work_order_id')
            ->whereNull('work_orders.deleted_at')
            ->where('work_orders.approval_status', 'approved')
            ->where('work_order_collaborators.status', 'pending')
            ->count();

        return $this->resolvedCounts = $this->formatCounts($assignmentCount, $collaboratorCount);
    }

    /** @return array{assignments: int, collaborators: int, total: int} */
    private function formatCounts(int $assignments, int $collaborators): array
    {
        return [
            'assignments' => $assignments,
            'collaborators' => $collaborators,
            'total' => $assignments + $collaborators,
        ];
    }
}
