<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\WorkBoardDesign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DepartmentWorkBoardQuery
{
    /**
     * Build the lightweight member directory without loading every task model.
     *
     * @return array{members: Collection<int, User>, projects: Collection<int, WorkOrderList>}
     */
    public function directory(Department $department, Request $request, bool $isAdmin): array
    {
        $status = $request->string('status')->toString();
        $projectId = $request->integer('project_id');
        $search = trim($request->string('search')->toString());
        $hasTaskFilter = ($status !== '' && isset(WorkBoardDesign::STATUSES[$status])) || $projectId > 0;

        $members = User::query()
            ->select(['id', 'name', 'username', 'email', 'profile_image', 'department_id', 'role', 'is_active'])
            ->where('department_id', $department->id)
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        $memberIds = $members->pluck('id');
        $taskSummary = collect();
        $updateSummary = collect();

        if ($memberIds->isNotEmpty()) {
            $tasks = $this->departmentTasks($department, $isAdmin)
                ->whereIn('user_id', $memberIds);
            $this->applyTaskFilters($tasks, $status, $projectId);

            $taskSummary = $tasks
                ->select('user_id')
                ->selectRaw('COUNT(*) as task_count')
                ->selectRaw('MAX(updated_at) as latest_task_updated_at')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $updates = DB::table('work_order_updates')
                ->join('work_orders', 'work_orders.job_id', '=', 'work_order_updates.work_order_id')
                ->whereNull('work_orders.deleted_at')
                ->where('work_orders.department_id', $department->id)
                ->whereIn('work_orders.user_id', $memberIds);

            if (! $isAdmin) {
                $updates->where('work_orders.approval_status', 'approved');
            }

            $this->applyTaskFilters($updates, $status, $projectId, 'work_orders.');

            $updateSummary = $updates
                ->select('work_orders.user_id')
                ->selectRaw('MAX(work_order_updates.created_at) as latest_update_at')
                ->groupBy('work_orders.user_id')
                ->get()
                ->keyBy('user_id');
        }

        $members = $members
            ->map(function (User $member) use ($taskSummary, $updateSummary): User {
                $tasks = $taskSummary->get($member->id);
                $updates = $updateSummary->get($member->id);
                $latestActivity = collect([
                    $tasks?->latest_task_updated_at,
                    $updates?->latest_update_at,
                ])->filter()->map(fn ($value) => Carbon::parse($value))->sortDesc()->first();

                $member->setAttribute('board_task_count', (int) ($tasks?->task_count ?? 0));
                $member->setAttribute('latest_activity_at', $latestActivity);

                return $member;
            })
            ->when($hasTaskFilter, fn (Collection $collection) => $collection
                ->filter(fn (User $member) => $member->board_task_count > 0))
            ->values();

        $projectTaskIds = $this->departmentTasks($department, $isAdmin)
            ->whereNotNull('work_order_list_id')
            ->select('work_order_list_id')
            ->distinct();

        $projects = WorkOrderList::query()
            ->whereIn('id', $projectTaskIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return compact('members', 'projects');
    }

    /** @return Collection<int, WorkOrder> */
    public function previewTasks(Department $department, User $member, bool $isAdmin): Collection
    {
        $tasks = $this->departmentTasks($department, $isAdmin)
            ->where('user_id', $member->id)
            ->select([
                'job_id',
                'user_id',
                'department_id',
                'work_order_list_id',
                'job_topic',
                'job_priority',
                'job_status',
                'approval_status',
                'job_due_at',
                'updated_at',
            ])
            ->with('taskList:id,name')
            ->withMax('updates', 'created_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('job_id')
            ->get();

        return $tasks->map(function (WorkOrder $task): WorkOrder {
            $latestActivity = collect([
                $task->updated_at,
                $task->updates_max_created_at ? Carbon::parse($task->updates_max_created_at) : null,
            ])->filter()->sortDesc()->first();

            $task->setAttribute('latest_activity_at', $latestActivity);

            return $task;
        });
    }

    private function departmentTasks(Department $department, bool $isAdmin): Builder
    {
        return WorkOrder::query()
            ->where('department_id', $department->id)
            ->when(! $isAdmin, fn (Builder $query) => $query->where('approval_status', 'approved'));
    }

    private function applyTaskFilters($query, string $status, int $projectId, string $prefix = ''): void
    {
        if ($projectId > 0) {
            $query->where($prefix.'work_order_list_id', $projectId);
        }

        if ($status === '' || ! isset(WorkBoardDesign::STATUSES[$status])) {
            return;
        }

        $statusColumn = $prefix.'job_status';
        $dueColumn = $prefix.'job_due_at';
        $today = now()->startOfDay();

        match ($status) {
            'paused' => $query->where($statusColumn, 5),
            'late' => $query->where(function ($late) use ($statusColumn, $dueColumn, $today): void {
                $late->where($statusColumn, 6)
                    ->orWhere(function ($overdue) use ($statusColumn, $dueColumn, $today): void {
                        $overdue->whereNotIn($statusColumn, [4, 5])
                            ->whereNotNull($dueColumn)
                            ->where($dueColumn, '<', $today);
                    });
            }),
            'done' => $query->where($statusColumn, 4),
            'doing' => $this->applyActiveStatus($query, $statusColumn, $dueColumn, $today, 2),
            'review' => $this->applyActiveStatus($query, $statusColumn, $dueColumn, $today, 3),
            'todo' => $this->applyActiveStatus($query, $statusColumn, $dueColumn, $today, 1),
        };
    }

    private function applyActiveStatus($query, string $statusColumn, string $dueColumn, Carbon $today, int $status): void
    {
        $query->where($statusColumn, $status)
            ->where(function ($notLate) use ($dueColumn, $today): void {
                $notLate->whereNull($dueColumn)->orWhere($dueColumn, '>=', $today);
            });
    }
}
