<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\TaskCommentService;
use App\Support\ProjectCreatorSummary;
use App\Support\TodayWorkspace;
use App\Support\WorkBoardDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WorkBoardController extends Controller
{
    public function index()
    {
        $departments = Department::query()
            ->withCount(['users as member_count' => fn ($query) => $query
                ->where('role', 'user')
                ->where('is_active', true)])
            ->orderBy('department_name')
            ->get();

        $jobs = $this->approvedJobs()
            ->get(['job_id', 'department_id', 'work_order_list_id', 'job_status', 'job_due_at']);
        $jobsByDepartment = $jobs->groupBy('department_id');

        $departments->each(function (Department $department) use ($jobsByDepartment): void {
            $departmentJobs = $jobsByDepartment->get($department->id, collect());
            $department->setAttribute('task_count', $departmentJobs->count());
            $department->setAttribute('project_count', $departmentJobs->pluck('work_order_list_id')->filter()->unique()->count());
            $department->setAttribute('board_tone', WorkBoardDesign::departmentTone($department));
            $department->setAttribute('board_code', WorkBoardDesign::departmentCode($department));
        });

        return view('work-board.index', [
            'departments' => $departments,
            'totals' => [
                'departments' => $departments->count(),
                'projects' => $jobs->pluck('work_order_list_id')->filter()->unique()->count(),
                'tasks' => $jobs->count(),
            ],
            'statusCounts' => WorkBoardDesign::statusCounts($jobs),
            'statusMeta' => WorkBoardDesign::STATUSES,
        ]);
    }

    public function department(Request $request, Department $department)
    {
        $allJobs = $this->approvedJobs()
            ->where('department_id', $department->id)
            ->with('taskList:id,name')
            ->get();

        $projects = $allJobs->pluck('taskList')->filter()->unique('id')->sortBy('name')->values();
        $members = User::query()
            ->where('department_id', $department->id)
            ->where('role', 'user')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $filteredJobs = $this->filterJobs($allJobs, $request, false);
        $jobsByUser = $filteredJobs->groupBy('user_id');
        $search = mb_strtolower(trim($request->string('search')->toString()));
        $hasJobFilter = $request->filled('status') || $request->integer('project_id') || $request->filled('due');

        $members = $members->filter(function (User $member) use ($jobsByUser, $search, $hasJobFilter): bool {
            $memberJobs = $jobsByUser->get($member->id, collect());

            if ($hasJobFilter && $memberJobs->isEmpty()) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            return str_contains(mb_strtolower($member->name), $search)
                || str_contains(mb_strtolower($member->username), $search)
                || str_contains(mb_strtolower((string) $member->email), $search)
                || $memberJobs->contains(fn (WorkOrder $job) => str_contains(
                    mb_strtolower($job->job_topic.' '.$job->job_details.' '.($job->taskList?->name ?? '')),
                    $search
                ));
        })->map(function (User $member) use ($jobsByUser): User {
            $memberJobs = $jobsByUser->get($member->id, collect());
            $projectRows = $memberJobs->groupBy(fn (WorkOrder $job) => $job->work_order_list_id ?: 'none')
                ->map(function (Collection $jobs): array {
                    $representative = $jobs->sortByDesc('job_id')->first();

                    return [
                        'name' => $representative->taskList?->name ?? 'งานทั่วไป',
                        'count' => $jobs->count(),
                        'status' => WorkBoardDesign::status($representative),
                    ];
                })->values();

            $member->setAttribute('board_jobs', $memberJobs);
            $member->setAttribute('board_projects', $projectRows);
            $member->setAttribute('board_status_counts', WorkBoardDesign::statusCounts($memberJobs));
            $member->setAttribute('latest_due_at', $memberJobs->max('job_due_at'));

            return $member;
        });

        $members = (match ($request->string('sort')->toString()) {
            'tasks_desc' => $members->sortByDesc(fn (User $user) => $user->board_jobs->count()),
            'due_asc' => $members->sortBy(fn (User $user) => $user->latest_due_at?->timestamp ?? PHP_INT_MAX),
            default => $members->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
        })->values();

        return view('work-board.department', [
            'department' => $department,
            'departmentTone' => WorkBoardDesign::departmentTone($department),
            'departmentCode' => WorkBoardDesign::departmentCode($department),
            'members' => $members,
            'projects' => $projects,
            'totals' => [
                'members' => User::where('department_id', $department->id)->where('role', 'user')->where('is_active', true)->count(),
                'projects' => $allJobs->pluck('work_order_list_id')->filter()->unique()->count(),
                'tasks' => $allJobs->count(),
            ],
            'statusCounts' => WorkBoardDesign::statusCounts($allJobs),
            'statusMeta' => WorkBoardDesign::STATUSES,
        ]);
    }

    public function member(Request $request, Department $department, User $user)
    {
        abort_unless((int) $user->department_id === (int) $department->id && $user->role === 'user', 404);

        $allJobs = $this->approvedJobs()
            ->where('department_id', $department->id)
            ->where('user_id', $user->id)
            ->with([
                'taskList:id,name',
                'collaborators:id,name,profile_image',
            ])
            ->withCount('images')
            ->get();

        $projects = $allJobs->pluck('taskList')->filter()->unique('id')->sortBy('name')->values();
        $jobs = $this->filterJobs($allJobs, $request);
        $jobs = (match ($request->string('sort')->toString()) {
            'name_asc' => $jobs->sortBy('job_topic', SORT_NATURAL | SORT_FLAG_CASE),
            'status_asc' => $jobs->sortBy(fn (WorkOrder $job) => WorkBoardDesign::statusKey($job)),
            default => $jobs->sortBy(fn (WorkOrder $job) => $job->job_due_at?->timestamp ?? PHP_INT_MAX),
        })->values();

        $projectGroups = $jobs->groupBy(fn (WorkOrder $job) => $job->work_order_list_id ?: 'none')
            ->map(fn (Collection $group) => [
                'project' => $group->first()->taskList,
                'jobs' => $group,
            ])->values();

        return view('work-board.member', [
            'department' => $department,
            'departmentTone' => WorkBoardDesign::departmentTone($department),
            'departmentCode' => WorkBoardDesign::departmentCode($department),
            'member' => $user,
            'projects' => $projects,
            'projectGroups' => $projectGroups,
            'totals' => [
                'projects' => $allJobs->pluck('work_order_list_id')->filter()->unique()->count(),
                'tasks' => $allJobs->count(),
            ],
            'statusCounts' => WorkBoardDesign::statusCounts($allJobs),
            'statusMeta' => WorkBoardDesign::STATUSES,
        ]);
    }

    public function adminDepartment(Request $request, Department $department)
    {
        $allJobs = WorkOrder::query()
            ->where('department_id', $department->id)
            ->with('taskList:id,name')
            ->get();
        $filteredJobs = $this->filterJobs($allJobs, $request, false);
        $jobsByUser = $filteredJobs->groupBy('user_id');
        $search = mb_strtolower(trim($request->string('search')->toString()));
        $hasJobFilter = $request->filled('status') || $request->integer('project_id') || $request->filled('due');

        $members = User::query()
            ->where('department_id', $department->id)
            ->where('role', 'user')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(function (User $member) use ($jobsByUser, $search, $hasJobFilter): bool {
                $memberJobs = $jobsByUser->get($member->id, collect());

                if ($hasJobFilter && $memberJobs->isEmpty()) {
                    return false;
                }

                return $search === ''
                    || str_contains(mb_strtolower($member->name.' '.$member->username.' '.($member->email ?? '')), $search)
                    || $memberJobs->contains(fn (WorkOrder $job) => str_contains(
                        mb_strtolower($job->job_topic.' '.$job->job_details.' '.($job->taskList?->name ?? '')),
                        $search
                    ));
            })
            ->map(function (User $member) use ($jobsByUser): User {
                $memberJobs = $jobsByUser->get($member->id, collect());
                $member->setAttribute('board_jobs', $memberJobs);
                $member->setAttribute('board_project_count', $memberJobs->pluck('work_order_list_id')->filter()->unique()->count());
                $member->setAttribute('board_status_counts', WorkBoardDesign::statusCounts($memberJobs));
                $member->setAttribute('latest_due_at', $memberJobs->max('job_due_at'));

                return $member;
            });

        $members = (match ($request->string('sort')->toString()) {
            'tasks_desc' => $members->sortByDesc(fn (User $member) => $member->board_jobs->count()),
            'due_asc' => $members->sortBy(fn (User $member) => $member->latest_due_at?->timestamp ?? PHP_INT_MAX),
            default => $members->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
        })->values();

        return view('work-board.admin.department', [
            'department' => $department,
            'departmentTone' => WorkBoardDesign::departmentTone($department),
            'departmentCode' => WorkBoardDesign::departmentCode($department),
            'members' => $members,
            'projects' => $allJobs->pluck('taskList')->filter()->unique('id')->sortBy('name')->values(),
            'totals' => [
                'members' => User::where('department_id', $department->id)->where('role', 'user')->where('is_active', true)->count(),
                'projects' => $allJobs->pluck('work_order_list_id')->filter()->unique()->count(),
                'tasks' => $allJobs->count(),
            ],
            'statusCounts' => WorkBoardDesign::statusCounts($allJobs),
            'statusMeta' => WorkBoardDesign::STATUSES,
        ]);
    }

    public function adminMember(Request $request, Department $department, User $user)
    {
        abort_unless((int) $user->department_id === (int) $department->id && $user->role === 'user', 404);

        $memberJobsQuery = WorkOrder::query()
            ->where('department_id', $department->id)
            ->where('user_id', $user->id);

        TodayWorkspace::synchronizeActiveToday($memberJobsQuery);
        TodayWorkspace::synchronizeLate($memberJobsQuery);

        $allJobs = $memberJobsQuery
            ->with([
                'taskList.attachments',
                'subtasks',
                'user.department',
                'creator',
                'leader.department',
                'collaborators.department',
                'images',
                'updates.user.department',
                'activityLogs.user.department',
                'reviewSubmitter',
            ])
            ->withCount('images')
            ->get();
        $jobs = $this->filterJobs($allJobs, $request);
        $jobs = (match ($request->string('sort')->toString()) {
            'name_asc' => $jobs->sortBy('job_topic', SORT_NATURAL | SORT_FLAG_CASE),
            'status_asc' => $jobs->sortBy(fn (WorkOrder $job) => WorkBoardDesign::statusKey($job)),
            default => $jobs->sortBy(fn (WorkOrder $job) => $job->job_due_at?->timestamp ?? PHP_INT_MAX),
        })->values();
        $taskLists = WorkOrderList::query()
            ->with('attachments')
            ->withCount('workOrders')
            ->whereIn('id', $allJobs->pluck('work_order_list_id')->filter()->unique())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $manageableTaskLists = $taskLists
            ->filter(fn (WorkOrderList $list) => $request->user()->can('manage', $list))
            ->values();
        $activeTasks = $jobs->reject(fn (WorkOrder $job) => (int) $job->job_status === 4)->values();
        $completedTasks = $jobs->filter(fn (WorkOrder $job) => (int) $job->job_status === 4)->values();
        $todayTasks = TodayWorkspace::tasks($allJobs);
        $unreadCommentCounts = app(TaskCommentService::class)->unreadCounts($allJobs->pluck('job_id'), $request->user());

        return view('work-board.admin.member', [
            'department' => $department,
            'departmentTone' => WorkBoardDesign::departmentTone($department),
            'departmentCode' => WorkBoardDesign::departmentCode($department),
            'member' => $user,
            'taskLists' => $taskLists,
            'manageableTaskLists' => $manageableTaskLists,
            'activeTasks' => $activeTasks,
            'completedTasks' => $completedTasks,
            'todayTasks' => $todayTasks,
            'unreadCommentCounts' => $unreadCommentCounts,
            'projectCreatorMeta' => ProjectCreatorSummary::forListIds($taskLists->pluck('id')),
            'projects' => $allJobs->pluck('taskList')->filter()->unique('id')->sortBy('name')->values(),
            'availableCollaborators' => User::with('department')
                ->where('role', 'user')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'employees' => User::with('department')->where('role', 'user')->orderBy('name')->get(),
            'departments' => Department::orderBy('department_name')->get(),
            'totals' => [
                'projects' => $allJobs->pluck('work_order_list_id')->filter()->unique()->count(),
                'tasks' => $allJobs->count(),
            ],
            'statusCounts' => WorkBoardDesign::statusCounts($allJobs),
            'statusMeta' => WorkBoardDesign::STATUSES,
        ]);
    }

    private function approvedJobs()
    {
        return WorkOrder::query()->where('approval_status', 'approved');
    }

    private function filterJobs(Collection $jobs, Request $request, bool $includeSearch = true): Collection
    {
        $status = $request->string('status')->toString();
        $projectId = $request->integer('project_id');
        $due = $request->string('due')->toString();
        $search = mb_strtolower(trim($request->string('search')->toString()));

        return $jobs->filter(function (WorkOrder $job) use ($status, $projectId, $due, $search, $includeSearch): bool {
            if ($status !== '' && isset(WorkBoardDesign::STATUSES[$status]) && WorkBoardDesign::statusKey($job) !== $status) {
                return false;
            }
            if ($projectId && (int) $job->work_order_list_id !== $projectId) {
                return false;
            }
            if ($due === 'overdue' && WorkBoardDesign::statusKey($job) !== 'late') {
                return false;
            }
            if ($due === '7days' && (! $job->job_due_at || ! $job->job_due_at->between(now(), now()->addDays(7)))) {
                return false;
            }
            if ($includeSearch && $search !== '' && ! str_contains(mb_strtolower($job->job_topic.' '.$job->job_details.' '.($job->taskList?->name ?? '')), $search)) {
                return false;
            }

            return true;
        })->values();
    }
}
