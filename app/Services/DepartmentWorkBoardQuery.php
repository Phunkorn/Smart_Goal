<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use App\Support\WorkBoardDesign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DepartmentWorkBoardQuery
{
    private const DONE_STATUS = 4;

    /** ล่าช้าก่อน แล้วครบกำหนดวันนี้ ตามด้วยงานที่กำลังทำอยู่ และปิดท้ายด้วยงานที่เพิ่งเริ่มวันนี้ */
    private const TODAY_BUCKET_ORDER = ['late' => 0, 'due_today' => 1, 'active' => 2, 'starts_today' => 3];

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

    /**
     * งานที่สมาชิก "ต้องจัดการวันนี้" สำหรับ Preview แบบ offcanvas
     *
     * สมาชิกภาพของรายการมาจาก TodayWorkspace::tasks() ตัวเดียวกับหน้า "งานของฉัน"
     * และ Member Workspace เต็ม — ห้ามนิยาม "งานวันนี้" ขึ้นใหม่ที่นี่
     *
     * Preview เพิ่มข้อจำกัดของตัวเองสองข้อเท่านั้น:
     *   1) เอาเฉพาะงานที่อนุมัติแล้ว งานที่รออนุมัติเป็นเรื่องของหน้า "คำขออนุมัติ"
     *      และไม่เคยเข้าวงจร auto-start/auto-late จึงไม่ควรปนอยู่ในรายการของวันนี้
     *   2) ตัดงานที่เสร็จแล้วออกทั้งหมด เพราะ Preview ตอบว่า "วันนี้ต้องทำอะไร"
     *      ไม่ใช่ประวัติงาน ส่วนงานที่เสร็จแล้วยังดูได้ครบใน Member Workspace เต็ม
     *
     * @return Collection<int, WorkOrder>
     */
    public function previewTasks(Department $department, User $member): Collection
    {
        $memberTasks = WorkOrder::query()
            ->where('department_id', $department->id)
            ->where('user_id', $member->id);

        // ต้องปรับสถานะอัตโนมัติให้เป็นปัจจุบันก่อน เหมือนที่ adminMember() และ MyTaskController::index() ทำ
        // มิฉะนั้นงานที่เลยกำหนดจะยังเป็น status 1-3 แล้วตกช่วง active range ของ TodayWorkspace
        // ทำให้งานล่าช้าหายไปจาก Preview ทั้งที่เป็นงานที่ต้องติดตามที่สุด
        TodayWorkspace::synchronizeActiveToday($memberTasks);
        TodayWorkspace::synchronizeLate($memberTasks);

        $tasks = $memberTasks
            ->where('approval_status', 'approved')
            ->select([
                'job_id',
                'user_id',
                'department_id',
                'work_order_list_id',
                'job_topic',
                'job_priority',
                'job_status',
                'approval_status',
                'job_start_at',
                'job_due_at',
                'job_completed_at',
            ])
            ->with('taskList:id,name')
            ->get();

        return TodayWorkspace::tasks($tasks)
            ->reject(fn (WorkOrder $task) => (int) $task->job_status === self::DONE_STATUS)
            ->each(fn (WorkOrder $task) => $task->setAttribute('today_bucket', $this->todayBucket($task)))
            ->sortBy(fn (WorkOrder $task) => [
                self::TODAY_BUCKET_ORDER[$task->today_bucket],
                $task->job_due_at?->getTimestamp() ?? PHP_INT_MAX,
                $task->job_id,
            ])
            ->values();
    }

    /**
     * กลุ่มความเร่งด่วนของงานในรายการวันนี้ ใช้ทั้งการเรียงลำดับและป้ายกำกับใน Blade
     * จึงคำนวณที่เดียวแล้วติดไปกับตัวงาน
     */
    private function todayBucket(WorkOrder $task): string
    {
        if (WorkBoardDesign::statusKey($task) === 'late') {
            return 'late';
        }

        $today = TodayWorkspace::calendarDate(now());

        if (TodayWorkspace::calendarDate($task->job_due_at) === $today) {
            return 'due_today';
        }

        if (TodayWorkspace::calendarDate($task->job_start_at) === $today) {
            return 'starts_today';
        }

        return 'active';
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
