<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\DepartmentWorkBoardQuery;
use App\Services\MeetingQueryService;
use App\Services\MemberWorkloadQuery;
use App\Services\TaskCommentService;
use App\Support\ProjectCreatorSummary;
use App\Support\TaskCollaboratorOptions;
use App\Support\TodayWorkspace;
use App\Support\WorkBoardDesign;
use App\Support\WorkOrderAssignee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class WorkBoardController extends Controller
{
    /**
     * มุมมองของ Admin Member Workspace
     *
     * เก็บสถานะไว้ที่ query string อย่างเดียว ห้าม reuse session key ของ "งานของฉัน"
     * (MyTaskController::WORKSPACE_VIEW_SESSION_KEY) เพราะจะทำให้มุมมองของหน้าสมาชิก
     * กับมุมมองของ Admin ในหน้างานของตัวเองทับกัน
     */
    private const MEMBER_WORKSPACE_VIEWS = ['table', 'board', 'calendar', 'meeting'];

    private const DEFAULT_MEMBER_WORKSPACE_VIEW = 'table';

    public function __construct(
        private readonly DepartmentWorkBoardQuery $departmentWorkBoard,
        private readonly MemberWorkloadQuery $memberWorkloads,
    ) {}

    /**
     * ภาพรวมทุกแผนกในองค์กร
     *
     * หัวหน้าแผนกเห็นรายชื่อแผนกครบเท่ากับผู้ใช้ทั่วไป — เดิมถูกกรองเหลือเฉพาะแผนกตัวเอง
     * ทำให้หัวหน้าเห็นน้อยกว่าลูกทีมตัวเอง ซึ่งกลับหัวกลับหางกับที่ควรเป็น
     *
     * สิทธิ์ที่ต่างกันอยู่ที่ "ลงลึกได้แค่ไหน" ไม่ใช่ "เห็นแผนกไหนบ้าง":
     * แผนกที่ตนดูแลเข้า Workspace เต็มได้ แผนกอื่นดูได้แค่งานวันนี้แบบเดียวกับผู้ใช้ทั่วไป
     */
    public function index(Request $request)
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

    /**
     * รายชื่อสมาชิกของแผนกใดก็ได้ — เนื้อหาเท่ากับที่ผู้ใช้ทั่วไปเห็นอยู่แล้ว
     * จึงไม่กันหัวหน้าแผนกออกจากแผนกอื่น ความต่างของสิทธิ์ไปอยู่ที่ member()
     */
    public function department(Request $request, Department $department)
    {
        $directory = $this->departmentWorkBoard->directory($department, $request, false);

        return view('work-board.department', [
            'department' => $department,
            'departmentTone' => WorkBoardDesign::departmentTone($department),
            'departmentCode' => WorkBoardDesign::departmentCode($department),
            'statusMeta' => WorkBoardDesign::STATUSES,
            ...$directory,
        ]);
    }

    public function member(Request $request, Department $department, User $user)
    {
        $this->ensurePreviewMember($department, $user);

        // สิทธิ์เต็มผูกกับ "แผนกที่ตนดูแล" ไม่ใช่ role — หัวหน้าที่เปิดดูแผนกอื่น
        // จะตกไปใช้มุมมองเดียวกับผู้ใช้ทั่วไปด้านล่าง คือเห็นเฉพาะงานวันนี้ของคนนั้น
        if ($request->user()->overseesDepartment($department->id)) {
            if ($request->boolean('workspace')) {
                return $this->adminMember($request, $department, $user, true);
            }

            return view('work-board.components.member-preview', [
                'department' => $department,
                'member' => $user,
                'tasks' => $this->departmentWorkBoard->previewTasks($department, $user),
                'isAdmin' => true,
                'workspaceRouteName' => 'work-board.member',
                'workspaceRouteParameters' => [$department, $user, 'workspace' => 1],
            ]);
        }

        return view('work-board.components.member-preview', [
            'department' => $department,
            'member' => $user,
            'tasks' => $this->departmentWorkBoard->previewTasks($department, $user),
            'isAdmin' => false,
        ]);
    }

    public function adminDepartment(Request $request, Department $department)
    {
        $directory = $this->departmentWorkBoard->directory($department, $request, true);

        return view('work-board.admin.department', [
            'department' => $department,
            'departmentTone' => WorkBoardDesign::departmentTone($department),
            'departmentCode' => WorkBoardDesign::departmentCode($department),
            'statusMeta' => WorkBoardDesign::STATUSES,
            ...$directory,
        ]);
    }

    public function adminMemberPreview(Department $department, User $user)
    {
        $this->ensurePreviewMember($department, $user);

        return view('work-board.components.member-preview', [
            'department' => $department,
            'member' => $user,
            'tasks' => $this->departmentWorkBoard->previewTasks($department, $user),
            'isAdmin' => true,
        ]);
    }

    public function adminMember(Request $request, Department $department, User $user, bool $isReadOnlyWorkspace = false)
    {
        abort_unless((int) $user->department_id === (int) $department->id && $user->role === 'user', 404);

        if ($isReadOnlyWorkspace) {
            abort_unless($request->user()->overseesDepartment($department->id), 403);
        }

        $workspaceView = $this->resolveMemberWorkspaceView($request);

        $memberJobsQuery = $this->memberWorkloads->forMember($user);

        if ($isReadOnlyWorkspace) {
            $memberJobsQuery->where('approval_status', 'approved');
        } else {
            TodayWorkspace::synchronizeLate($memberJobsQuery);
        }

        $allJobs = $memberJobsQuery
            ->with([
                'taskList.attachments',
                'user.department',
                'creator',
                'leader.department',
                'collaborators.department',
                'images',
                'subtasks',
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
            ->where(function ($query) use ($user, $allJobs) {
                $query->where('user_id', $user->id)
                    ->orWhereIn('id', $allJobs->pluck('work_order_list_id')->filter()->unique());
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $manageableTaskLists = $taskLists
            ->filter(fn (WorkOrderList $list) => ! $isReadOnlyWorkspace && $request->user()->can('manage', $list))
            ->values();
        $activeTasks = $jobs->reject(fn (WorkOrder $job) => (int) $job->job_status === 4)->values();
        $completedTasks = $jobs->filter(fn (WorkOrder $job) => (int) $job->job_status === 4)->values();
        $todayTasks = TodayWorkspace::tasks($allJobs);
        $unreadCommentCounts = app(TaskCommentService::class)->unreadCounts($allJobs->pluck('job_id'), $request->user());

        // ประชุมถูก query ต่อเมื่อ Admin เปิดมุมมองนั้นจริง เหมือนที่หน้า "งานของฉัน" ทำ
        // scope มาจาก $user ที่ route ผูกมา ไม่ใช่ `?employee=` จึงแก้ค่าจาก URL ให้ข้ามไปดูคนอื่นไม่ได้
        $meetingData = [];
        if ($workspaceView === 'meeting') {
            Gate::authorize('viewAny', Meeting::class);
            $meetingData = app(MeetingQueryService::class)->indexData($request, $request->user(), $user);
        }
        $calendarNow = CarbonImmutable::now(MeetingQueryService::BUSINESS_TIMEZONE);
        $calendarFrom = $calendarNow->subMonthNoOverflow()->startOfMonth();
        $calendarTo = $calendarNow->addMonthNoOverflow()->endOfMonth();
        $calendarMeetings = app(MeetingQueryService::class)->calendarMeetings(
            $request->user(),
            $calendarFrom,
            $calendarTo,
            $user
        );

        return view('work-board.admin.member', [
            'workspaceView' => $workspaceView,
            'isReadOnlyWorkspace' => $isReadOnlyWorkspace,
            'meetingData' => $meetingData,
            'calendarMeetings' => $calendarMeetings,
            'calendarMeetingRange' => [
                'start' => $calendarFrom->format('Y-m-d'),
                'end' => $calendarTo->format('Y-m-d'),
            ],
            'calendarMeetingSubject' => $user,
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
            'projects' => $taskLists->sortBy('name')->values(),
            'availableCollaborators' => $isReadOnlyWorkspace ? collect() : TaskCollaboratorOptions::forActor($request->user()),
            // ใช้ตัวกรองเดียวกับหน้าบอร์ดรวม เพื่อให้โมดัลมอบหมายงานที่ใช้ร่วมกันเห็นรายชื่อชุดเดียวกัน
            'employees' => $isReadOnlyWorkspace ? collect() : WorkOrderAssignee::query()->with('department')->orderBy('name')->get(),
            'departments' => $isReadOnlyWorkspace ? collect() : Department::orderBy('department_name')->get(),
            'totals' => [
                'projects' => $taskLists->count(),
                'tasks' => $allJobs->count(),
            ],
            'statusCounts' => WorkBoardDesign::statusCounts($allJobs),
            'statusMeta' => WorkBoardDesign::STATUSES,
        ]);
    }

    /** `?view=` ที่ไม่อยู่ใน allow-list ต้องตกกลับมุมมองตั้งต้น ไม่ใช่ render panel ที่หน้านี้ไม่มี */
    private function resolveMemberWorkspaceView(Request $request): string
    {
        $requested = $request->string('view')->toString();

        return in_array($requested, self::MEMBER_WORKSPACE_VIEWS, true)
            ? $requested
            : self::DEFAULT_MEMBER_WORKSPACE_VIEW;
    }

    private function approvedJobs()
    {
        return WorkOrder::query()->where('approval_status', 'approved');
    }

    private function ensurePreviewMember(Department $department, User $user): void
    {
        abort_unless(
            (int) $user->department_id === (int) $department->id
                && $user->role === 'user'
                && $user->is_active,
            404
        );
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
