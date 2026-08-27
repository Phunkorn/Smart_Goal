<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Models\WorkOrderSubtask;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\WorkOrderApprovalResolver;
use App\Support\ProjectCreatorSummary;
use App\Support\ProtectedMedia;
use App\Support\TaskCollaboratorOptions;
use App\Support\TodayWorkspace;
use App\Support\WorkOrderAssignee;
use App\Services\MeetingQueryService;
use App\Services\TaskCommentService;
use App\Services\TaskStatusTransitionService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class MyTaskController extends Controller
{
    use ValidatesAttachments;

    private const TASK_SCOPES = [
        'all',
        'responsible',
        'created',
        'assigned_by_me',
        'collaborating',
    ];

    /** มุมมองที่ทุก role ซึ่งเข้าหน้านี้ได้มี panel รองรับจริง */
    private const WORKSPACE_VIEWS = ['table', 'board', 'calendar'];

    /**
     * พนักงานเท่านั้นที่ได้มุมมอง "ประชุม" เพราะเมนูการประชุมถูกย้ายออกจาก Sidebar ของ role นี้
     * Admin และ Viewer ยังมีเมนู "การประชุม" ของตัวเองอยู่ จึงไม่ต้องมี view ซ้ำ
     */
    private const MEMBER_WORKSPACE_VIEWS = ['table', 'board', 'calendar', 'meeting'];

    /** มุมมองตั้งต้นหลังเข้าสู่ระบบ และเมื่อค่าที่ร้องขอใช้ไม่ได้ */
    private const DEFAULT_WORKSPACE_VIEW = 'calendar';

    /** คีย์ session ที่จำมุมมองล่าสุดของผู้ใช้ ต้องตรงกับที่ AuthController เขียนตอน login */
    public const WORKSPACE_VIEW_SESSION_KEY = 'mytasks.view';

    /** เพดานช่วงเวลาต่อ 1 คำขอของ endpoint ปฏิทิน กันการดึงประชุมทั้งระบบ */
    private const CALENDAR_RANGE_MAX_DAYS = 366;

    /** ประชุมที่ฝังมากับ HTML ตั้งต้น: เดือนปัจจุบัน บวกกันชนหน้าหลัง 1 เดือน */
    private const CALENDAR_PRELOAD_MONTHS = 1;

    public function index(Request $request): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user->role === 'viewer') {
            return redirect()->route('board.index');
        }

        $taskScope = $user->role === 'user'
            ? $this->normalizeTaskScope($request->query('task_scope'))
            : 'all';

        $workspaceView = $this->resolveWorkspaceView($request, $user);

        $this->moveAdminAssignmentsToProjectGroups($user);

        // ไม่มีการยัดงานที่ยังไม่มีโปรเจกต์เข้าโปรเจกต์แรกของผู้ที่เปิดหน้านี้อีกต่อไป
        // เพราะงานเหล่านั้นถูกแสดงในกลุ่ม "งานทั่วไป" อยู่แล้ว (project-board-card.blade.php
        // และ workspace-task-source.blade.php) ส่วนของเดิมทำให้งานของเพื่อนร่วมงาน
        // ถูกดูดเข้าโปรเจกต์ของคนที่บังเอิญเปิดหน้า "งานของฉัน" ก่อน

        TodayWorkspace::synchronizeActiveToday($this->baseWorkOrderQuery());
        TodayWorkspace::synchronizeLate($this->baseWorkOrderQuery());

        $workOrders = WorkOrder::query()->visibleInProjectsFor($user)
            ->with([
                'taskList',
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
            ->orderByRaw('job_status = 4 asc')
            ->orderBy('job_due_at')
            ->latest('job_id')
            ->get();

        $workspaceWorkOrders = $workOrders;
        if ($taskScope !== 'all') {
            $workspaceTaskIds = $this->applyTaskScope($this->baseWorkOrderQuery(), $user, $taskScope)
                ->pluck('job_id');
            $workspaceWorkOrders = $workOrders
                ->whereIn('job_id', $workspaceTaskIds)
                ->values();
        }

        $taskLists = $this->taskListsForCurrentUser();
        $manageableTaskLists = $taskLists->where('user_id', $user->id)->values();

        $visibleLists = $taskLists->where('is_visible', true)->values();
        $workspaceTaskLists = $taskScope === 'all'
            ? $taskLists
            : $taskLists->whereIn('id', $workspaceWorkOrders->pluck('work_order_list_id')->filter()->unique())->values();
        $activeTasks = $workspaceWorkOrders->reject(fn (WorkOrder $workOrder) => (int) $workOrder->job_status === 4)->values();
        $completedTasks = $workspaceWorkOrders->filter(fn (WorkOrder $workOrder) => (int) $workOrder->job_status === 4)->values();
        $todayTasks = TodayWorkspace::tasks($workspaceWorkOrders);
        $calendarTasks = $workOrders;
        $unreadCommentCounts = app(TaskCommentService::class)->unreadCounts($workOrders->pluck('job_id'), $user);
        $availableCollaborators = TaskCollaboratorOptions::forActor($user);
        $projectCreatorMeta = ProjectCreatorSummary::forListIds($taskLists->pluck('id'));

        // ประชุมถูก query ต่อเมื่อผู้ใช้เปิดมุมมองนั้นจริง เพื่อไม่ให้ทุกการเปิดหน้างานมีคิวรีเพิ่ม
        $meetings = app(MeetingQueryService::class);
        $meetingData = $workspaceView === 'meeting'
            ? $meetings->indexData($request, $user)
            : [];

        // ฝังเฉพาะช่วงแคบ ๆ ให้ปฏิทินวาดรอบแรกได้ทันทีโดยไม่ต้องรอ fetch (จึงไม่กระพริบ)
        $calendarWindow = $this->calendarPreloadWindow();
        $calendarMeetings = $meetings->calendarMeetings($user, $calendarWindow['from'], $calendarWindow['to']);
        $calendarMeetingRange = [
            'start' => $calendarWindow['from']->format('Y-m-d'),
            'end' => $calendarWindow['to']->format('Y-m-d'),
        ];

        return view('tasks.index', compact(
            'workspaceView',
            'meetingData',
            'calendarMeetings',
            'calendarMeetingRange',
            'taskLists',
            'manageableTaskLists',
            'visibleLists',
            'workspaceTaskLists',
            'activeTasks',
            'completedTasks',
            'calendarTasks',
            'availableCollaborators',
            'projectCreatorMeta',
            'todayTasks',
            'unreadCommentCounts',
            'taskScope'
        ));
    }

    /**
     * ประชุมของช่วงเดือนที่ปฏิทินร้องขอ
     *
     * ปฏิทินเลือกปีได้ ±5 ปีและกดเดือนถัดไปได้ไม่จำกัด การฝัง JSON ล่วงหน้าอย่างเดียว
     * จึงทำให้ประชุมหายเงียบ ๆ เมื่อเลื่อนไกล endpoint นี้เติมให้ทีละช่วงโดยมีเพดานชัดเจน
     */
    public function calendarMeetings(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if($user->role === 'viewer', 403);

        $validated = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['start'], MeetingQueryService::BUSINESS_TIMEZONE)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['end'], MeetingQueryService::BUSINESS_TIMEZONE)->endOfDay();

        abort_if($from->diffInDays($to) > self::CALENDAR_RANGE_MAX_DAYS, 422, 'ช่วงเวลาที่ขอกว้างเกินไป');

        return response()->json([
            'meetings' => app(MeetingQueryService::class)->calendarMeetings($user, $from, $to),
        ]);
    }

    /**
     * Quick View ของงานบนปฏิทิน — คืน HTML ของ partial เพื่อ reuse formatter ของ Blade
     *
     * โหลดตอนคลิกเท่านั้น ไม่ฝังมากับหน้าปฏิทิน และตรวจสิทธิ์ด้วย WorkOrderPolicy::view
     * ทุกครั้ง การซ่อนปุ่มฝั่ง client ไม่ถือเป็นการป้องกัน
     */
    public function taskQuickView(Request $request, int $id): View
    {
        $task = WorkOrder::with([
            'taskList',
            'user.department',
            'creator',
            'collaborators.department',
            'updates.user',
        ])->withCount('images')->findOrFail($id);

        $this->authorize('view', $task);

        return view('calendar.quick-view.task', [
            'task' => $task,
            // ผู้ใช้คลิกที่หมุด "วันเริ่ม" หรือ "กำหนดส่ง" ให้บอกกลับว่ามาจากหมุดไหน
            'milestone' => in_array($request->query('milestone'), ['start', 'end', 'single'], true)
                ? $request->query('milestone')
                : 'single',
        ]);
    }

    /**
     * Quick View ของการประชุม — ใช้ MeetingPolicy::view ตัวเดียวกับหน้ารายละเอียดเดิม
     */
    public function meetingQuickView(Meeting $meeting): View
    {
        Gate::authorize('view', $meeting);

        $meeting->load(['creator.department', 'attendees.department']);

        return view('calendar.quick-view.meeting', [
            'meeting' => $meeting,
            'nowBangkok' => CarbonImmutable::now(MeetingQueryService::BUSINESS_TIMEZONE),
        ]);
    }

    /**
     * ลำดับความสำคัญของแหล่งข้อมูลมุมมอง: `?view=` → session → ค่าตั้งต้น (ปฏิทิน)
     *
     * server เป็นผู้ตัดสินตั้งแต่ HTML แรก เพื่อไม่ให้หน้าจอกระพริบจากตารางไปปฏิทิน
     * ค่าที่ร้องขอมาแต่ใช้ไม่ได้ (สะกดผิด หรือเป็นมุมมองที่ role นั้นไม่มี panel รองรับ)
     * จะ fallback เป็นปฏิทินและต้องไม่ถูกจำลง session
     */
    private function resolveWorkspaceView(Request $request, User $user): string
    {
        $allowed = $this->availableWorkspaceViews($user);
        $requested = $request->query('view');

        if (is_string($requested) && $requested !== '') {
            if (! in_array($requested, $allowed, true)) {
                return self::DEFAULT_WORKSPACE_VIEW;
            }

            $request->session()->put(self::WORKSPACE_VIEW_SESSION_KEY, $requested);

            return $requested;
        }

        $remembered = $request->session()->get(self::WORKSPACE_VIEW_SESSION_KEY);

        return is_string($remembered) && in_array($remembered, $allowed, true)
            ? $remembered
            : self::DEFAULT_WORKSPACE_VIEW;
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    private function calendarPreloadWindow(): array
    {
        $now = CarbonImmutable::now(MeetingQueryService::BUSINESS_TIMEZONE);

        return [
            'from' => $now->subMonthsNoOverflow(self::CALENDAR_PRELOAD_MONTHS)->startOfMonth(),
            'to' => $now->addMonthsNoOverflow(self::CALENDAR_PRELOAD_MONTHS)->endOfMonth(),
        ];
    }

    /**
     * @return list<string>
     */
    private function availableWorkspaceViews(User $user): array
    {
        return $user->role === 'user' ? self::MEMBER_WORKSPACE_VIEWS : self::WORKSPACE_VIEWS;
    }

    public function storeQuickTask(Request $request): JsonResponse
    {
        $user = Auth::user();

        $this->authorize('create', WorkOrder::class);

        $taskLists = $this->taskListsForCurrentUser()
            ->where('user_id', $user->id)
            ->values();

        $validated = $request->validate([
            'job_topic' => 'required|string|max:255',
            'work_order_list_id' => 'nullable|exists:work_order_lists,id',
        ]);

        $requestedListId = (int) ($validated['work_order_list_id'] ?? 0);
        $list = $requestedListId
            ? $taskLists->firstWhere('id', $requestedListId)
            : $taskLists->first();

        abort_if($requestedListId && ! $list, 403);

        if (! $list) {
            return response()->json([
                'ok' => false,
                'message' => 'กรุณาสร้างรายการก่อนเพิ่มงาน',
            ], 422);
        }

        $workOrder = WorkOrder::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'assigned_by' => $user->id,
            'leader_user_id' => $user->id,
            'department_id' => $user->department_id,
            'work_order_list_id' => $list->id,
            'job_topic' => $validated['job_topic'],
            'job_details' => null,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        AuditTrail::log('created', $workOrder, 'สร้างงานย่อย: '.$workOrder->job_topic, [
            'after' => $workOrder->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มงานย่อยแล้ว',
            'job_id' => $workOrder->job_id,
        ], 201);
    }

    /**
     * สร้างงานแบบเต็มรูปแบบจากหน้า "งานของฉัน" (หัวข้อ, รายละเอียด, ผู้รับผิดชอบ,
     * ผู้ร่วมงาน, วันเริ่ม-สิ้นสุด, ความสำคัญ, ไฟล์อ้างอิงงาน)
     *
     * กติกาการอนุมัติ:
     * - มอบหมายให้ตัวเอง หรือมอบหมายให้พนักงานแผนกเดียวกัน => สร้างงานได้ทันที (approved)
     * - มอบหมายให้พนักงานต่างแผนก => สถานะ "รออนุมัติ" และแจ้งเตือนไปยัง Admin ทุกคน
     *   ให้เป็นผู้ตัดสินใจรับหรือปฏิเสธงานแทน (ผ่านปุ่มอนุมัติ/ปฏิเสธที่มีอยู่แล้วในหน้าบอร์ด)
     */
    public function store(Request $request): JsonResponse
    {
        $actor = Auth::user();

        $this->authorize('create', WorkOrder::class);

        $validated = $request->validate($this->storeValidationRules());

        $this->assertAllowedAttachments($request, 'attachments');

        $projectItems = $this->normalizeProjectItems($validated);

        if ($projectItems->isEmpty()) {
            $projectName = trim((string) ($validated['project_name'] ?? ''));
            abort_if($projectName === '', 422, 'กรุณาระบุชื่อโปรเจกต์');

            $this->authorize('create', WorkOrderList::class);

            $list = DB::transaction(function () use ($validated, $actor, $request, $projectName) {
                $list = WorkOrderList::create([
                    'user_id' => $actor->id,
                    'name' => $projectName,
                    'priority' => $validated['project_priority'] ?? 2,
                    'is_visible' => true,
                    'sort_order' => (int) WorkOrderList::where('user_id', $actor->id)->max('sort_order') + 1,
                ]);

                if ($request->hasFile('attachments')) {
                    foreach ($request->file('attachments') as $file) {
                        // getMimeType() ตรวจจากเนื้อไฟล์จริง ต่างจาก getClientMimeType() ที่ปลอมได้ และต้องอ่านก่อนย้ายไฟล์
                        $mimeType = $file->getMimeType();
                        $path = ProtectedMedia::storeAttachment($file, 'project-attachments/'.$list->id);
                        WorkOrderListAttachment::create([
                            'work_order_list_id' => $list->id,
                            'file_path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'file_type' => $mimeType,
                            'uploaded_by' => $actor->id,
                        ]);
                    }
                }

                AuditTrail::log('created', $list, 'สร้างโปรเจกต์: '.$list->name, [
                    'after' => $list->attributesToArray(),
                ]);

                return $list;
            });

            return response()->json([
                'ok' => true,
                'message' => 'เพิ่มโปรเจกต์สำเร็จ',
                'job_id' => null,
                'list_id' => $list->id,
                'requires_admin_review' => false,
            ], 201);
        }

        $assignee = isset($validated['user_id'])
            ? WorkOrderAssignee::findWithDepartment((int) $validated['user_id'])
            : $actor->loadMissing('department');
        abort_unless($assignee, 422, 'ผู้รับผิดชอบไม่ถูกต้อง');

        $approval = WorkOrderApprovalResolver::resolve($actor, $assignee);
        $sameDepartment = $approval['same_department'];

        $job = DB::transaction(function () use ($validated, $actor, $assignee, $sameDepartment, $approval, $request, $projectItems) {
            $leaderId = $approval['leader_user_id'];
            $firstItem = $projectItems->first();
            $projectName = trim((string) ($validated['project_name'] ?? '')) ?: $firstItem['job_topic'];

            $list = WorkOrderList::create([
                'user_id' => $leaderId,
                'name' => $projectName,
                'priority' => $validated['project_priority'] ?? $validated['job_priority'] ?? 2,
                'is_visible' => true,
                'sort_order' => (int) WorkOrderList::where('user_id', $leaderId)->max('sort_order') + 1,
            ]);

            $createdJobs = collect();

            foreach ($projectItems as $itemIndex => $item) {
                $job = WorkOrder::create([
                    'user_id' => $assignee->id,
                    'created_by' => $actor->id,
                    'assigned_by' => $actor->id,
                    'leader_user_id' => $leaderId,
                    'department_id' => $assignee->department_id ?? $actor->department_id,
                    'work_order_list_id' => $list->id,
                    'job_topic' => $item['job_topic'],
                    'job_details' => $item['job_details'] ?: null,
                    'job_priority' => 2,
                    'job_status' => 1,
                    'approval_status' => $approval['approval_status'],
                    'approved_by' => $approval['approved_by'],
                    'approved_at' => $approval['approved_at'],
                    'job_progress' => 0,
                    'job_start_at' => Carbon::parse($validated['job_start_at']),
                    'job_due_at' => Carbon::parse($validated['job_due_at']),
                ]);

                foreach ($item['subtasks'] as $subtaskIndex => $subtask) {
                    $job->subtasks()->create([
                        'created_by' => $actor->id,
                        'title' => $subtask['title'],
                        'details' => $subtask['details'] ?: null,
                        'sort_order' => $subtaskIndex + 1,
                    ]);
                }

                AuditTrail::log('project_leader_assigned', $job, 'กำหนดหัวหน้าโปรเจกต์สำหรับงาน: '.$job->job_topic, [
                    'leader_user_id' => $leaderId,
                    'work_order_list_id' => $list->id,
                    'list_user_id' => $list->user_id,
                ]);

                $collaborators = collect($validated['collaborators'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->reject(fn ($id) => $id === (int) $assignee->id)
                    ->unique()
                    ->values();

                foreach ($collaborators as $userId) {
                    $job->collaborators()->syncWithoutDetaching([
                        $userId => [
                            'added_by' => $actor->id,
                            'status' => 'pending',
                        ],
                    ]);
                }

                AuditTrail::log('created', $job, ($sameDepartment ? 'สร้างโปรเจกต์: ' : 'ส่งคำขอเปิดงานข้ามแผนก: ').$job->job_topic, [
                    'after' => $job->attributesToArray(),
                ]);

                $createdJobs->push($job);
            }

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    // getMimeType() ตรวจจากเนื้อไฟล์จริง ต่างจาก getClientMimeType() ที่ปลอมได้ และต้องอ่านก่อนย้ายไฟล์
                    $mimeType = $file->getMimeType();
                    $path = ProtectedMedia::storeAttachment($file, 'project-attachments/'.$list->id);
                    WorkOrderListAttachment::create([
                        'work_order_list_id' => $list->id,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type' => $mimeType,
                        'uploaded_by' => $actor->id,
                    ]);
                }
            }

            return $createdJobs->first();
        });

        $job->refresh();

        if ($sameDepartment) {
            if ((int) $assignee->id !== (int) $actor->id) {
                $this->notifyUsers([$assignee->id], $job, 'task_assigned', 'มีงานใหม่', $actor->name.' มอบหมายงาน "'.$job->job_topic.'" ให้คุณ');
            }
            $message = 'เพิ่มโปรเจกต์สำเร็จ';
        } else {
            $this->notifyAdmins($job, 'cross_department_pending', 'มีคำขอเปิดงานข้ามแผนกรอตรวจสอบ',
                $actor->name.' ต้องการมอบหมายงาน "'.$job->job_topic.'" ให้ '.$assignee->name.' (ต่างแผนก) กรุณาตรวจสอบและอนุมัติ/ปฏิเสธ');
            $message = 'ส่งคำขอเปิดงานข้ามแผนกแล้ว รอผู้ดูแลระบบตรวจสอบก่อนเริ่มงาน';
        }

        return response()->json([
            'ok' => true,
            'message' => $message,
            'job_id' => $job->job_id,
            'list_id' => $job->work_order_list_id,
            'requires_admin_review' => ! $sameDepartment,
        ], 201);
    }

    public function storeList(Request $request): JsonResponse
    {
        $user = Auth::user();

        $this->authorize('create', WorkOrderList::class);

        $validated = $request->validate([
            'name' => 'required|string|max:80',
        ]);

        $list = WorkOrderList::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'is_visible' => true,
            'sort_order' => (int) WorkOrderList::where('user_id', $user->id)->max('sort_order') + 1,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'สร้างรายการแล้ว',
            'list_id' => $list->id,
        ], 201);
    }

    private function storeValidationRules(): array
    {
        return [
            'project_name' => ['nullable', 'string', 'max:80'],
            'job_topic' => ['nullable', 'string', 'max:255'],
            'job_details' => ['nullable', 'string', 'max:2000'],
            'initial_subtask_title' => ['nullable', 'string', 'max:255'],
            'initial_subtask_details' => ['nullable', 'string', 'max:2000'],
            'project_items' => ['nullable', 'array', 'max:20'],
            'project_items.*.job_topic' => ['nullable', 'string', 'max:255'],
            'project_items.*.job_details' => ['nullable', 'string', 'max:2000'],
            'project_items.*.subtasks' => ['nullable', 'array', 'max:50'],
            'project_items.*.subtasks.*.title' => ['nullable', 'string', 'max:255'],
            'project_items.*.subtasks.*.details' => ['nullable', 'string', 'max:2000'],
            'user_id' => WorkOrderAssignee::validationRules(false),
            'collaborators' => ['nullable', 'array'],
            'collaborators.*' => ['integer', 'exists:users,id'],
            'job_start_at' => ['nullable', 'date'],
            'job_due_at' => ['nullable', 'date', 'after_or_equal:job_start_at'],
            'job_priority' => ['nullable', 'integer', 'in:1,2,3,4,5'],
            'project_priority' => ['nullable', 'integer', 'in:1,2,3'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
        ];
    }

    private function normalizeProjectItems(array $validated): Collection
    {
        $projectItems = collect($validated['project_items'] ?? [])
            ->map(function ($item) {
                $subtasks = collect($item['subtasks'] ?? [])
                    ->map(fn ($subtask) => [
                        'title' => trim((string) ($subtask['title'] ?? '')),
                        'details' => trim((string) ($subtask['details'] ?? '')),
                    ])
                    ->filter(fn ($subtask) => $subtask['title'] !== '')
                    ->values()
                    ->all();

                return [
                    'job_topic' => trim((string) ($item['job_topic'] ?? '')),
                    'job_details' => trim((string) ($item['job_details'] ?? '')),
                    'subtasks' => $subtasks,
                ];
            })
            ->filter(fn ($item) => $item['job_topic'] !== '')
            ->values();

        if ($projectItems->isNotEmpty() || blank($validated['job_topic'] ?? null)) {
            return $projectItems;
        }

        $legacySubtasks = [];

        if (filled($validated['initial_subtask_title'] ?? null)) {
            $legacySubtasks[] = [
                'title' => trim((string) $validated['initial_subtask_title']),
                'details' => trim((string) ($validated['initial_subtask_details'] ?? '')),
            ];
        }

        return collect([[
            'job_topic' => trim((string) $validated['job_topic']),
            'job_details' => trim((string) ($validated['job_details'] ?? '')),
            'subtasks' => $legacySubtasks,
        ]]);
    }

    public function toggleList(Request $request, WorkOrderList $list): JsonResponse
    {
        $this->authorize('toggle', $list);

        $validated = $request->validate([
            'is_visible' => 'required|boolean',
        ]);

        $list->update(['is_visible' => $validated['is_visible']]);

        return response()->json([
            'ok' => true,
            'message' => $validated['is_visible'] ? 'แสดงรายการแล้ว' : 'ซ่อนรายการแล้ว',
            'is_visible' => (bool) $validated['is_visible'],
        ]);
    }

    public function updateList(Request $request, WorkOrderList $list): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required_without:priority|string|max:80',
            'priority' => 'required_without:name|integer|in:1,2,3',
        ]);

        $this->authorize('manage', $list);
        abort_if(isset($validated['name']) && $this->listIsCompleted($list) && $user->role !== 'admin', 403);

        $before = $list->attributesToArray();
        $list->update(collect($validated)->only(['name', 'priority'])->all());

        AuditTrail::log('updated', $list, isset($validated['priority']) ? 'เปลี่ยนความสำคัญโปรเจกต์: '.$list->name : 'เปลี่ยนชื่อโปรเจกต์: '.$list->name, [
            'before' => $before,
            'after' => $list->fresh()->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'เปลี่ยนชื่อโปรเจกต์แล้ว',
            'list_id' => $list->id,
            'name' => $list->name,
            'priority' => (int) $list->priority,
        ]);
    }

    public function destroyList(WorkOrderList $list): JsonResponse
    {
        $user = Auth::user();

        $this->authorize('manage', $list);
        abort_if($this->listIsCompleted($list) && $user->role !== 'admin', 403);

        DB::transaction(function () use ($list, $user) {
            AuditTrail::trash($list, $user, [
                'list' => $list->attributesToArray(),
                'work_order_count' => $list->workOrders()->count(),
            ]);
            AuditTrail::log('deleted', $list, 'ลบโปรเจกต์: '.$list->name, [
                'before' => $list->attributesToArray(),
            ]);

            $list->workOrders()->eachById(function (WorkOrder $workOrder) {
                AuditTrail::trash($workOrder, Auth::user(), [
                    'work_order' => $workOrder->attributesToArray(),
                    'deleted_with_list' => true,
                ]);
                AuditTrail::log('deleted', $workOrder, 'ลบงานพร้อมโปรเจกต์: '.$workOrder->job_topic, [
                    'before' => $workOrder->attributesToArray(),
                ]);
                $workOrder->delete();
            }, 100, 'job_id');

            $list->attachments()->each(fn (WorkOrderListAttachment $attachment) => $attachment->delete());

            $list->delete();
        });

        return response()->json([
            'ok' => true,
            'message' => 'ลบรายการและงานในรายการเรียบร้อยแล้ว',
        ]);
    }

    public function storeListAttachments(Request $request, WorkOrderList $list): JsonResponse
    {
        $this->authorize('manage', $list);

        $request->validate([
            'attachments' => ['required', 'array', 'min:1', 'max:5'],
            'attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
        ]);

        $incomingFiles = $request->file('attachments', []);
        abort_if(
            $list->attachments()->count() + count($incomingFiles) > 5,
            422,
            'แนบไฟล์ของโปรเจกต์ได้รวมไม่เกิน 5 ไฟล์'
        );

        $this->assertAllowedAttachments($request, 'attachments');

        $storedPaths = [];
        try {
            DB::transaction(function () use ($incomingFiles, $list, &$storedPaths) {
                foreach ($incomingFiles as $file) {
                    // getMimeType() ตรวจจากเนื้อไฟล์จริง ต่างจาก getClientMimeType() ที่ปลอมได้ และต้องอ่านก่อนย้ายไฟล์
                    $mimeType = $file->getMimeType();
                    $path = ProtectedMedia::storeAttachment($file, 'project-attachments/'.$list->id);
                    $storedPaths[] = $path;

                    WorkOrderListAttachment::create([
                        'work_order_list_id' => $list->id,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type' => $mimeType,
                        'uploaded_by' => Auth::id(),
                    ]);
                }

                AuditTrail::log('attachments_uploaded', $list, 'เพิ่มไฟล์แนบโปรเจกต์: '.$list->name, [
                    'count' => count($incomingFiles),
                ]);
            });
        } catch (Throwable $exception) {
            foreach (array_unique($storedPaths) as $path) {
                ProtectedMedia::deleteAttachment($path);
            }

            throw $exception;
        }

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มไฟล์แนบโปรเจกต์แล้ว',
        ]);
    }

    public function destroyListAttachment(
        WorkOrderList $list,
        WorkOrderListAttachment $attachment
    ): JsonResponse {
        $this->authorize('manage', $list);
        abort_unless((int) $attachment->work_order_list_id === (int) $list->id, 404);

        $before = $attachment->attributesToArray();
        $attachment->delete();

        AuditTrail::log('attachment_deleted', $list, 'ลบไฟล์แนบโปรเจกต์: '.$list->name, [
            'attachment' => $before,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'ลบไฟล์แนบโปรเจกต์แล้ว',
        ]);
    }

    public function destroy(int $job_id): JsonResponse
    {
        $workOrder = WorkOrder::with(['creator', 'user', 'leader', 'collaborators'])->findOrFail($job_id);
        $this->authorize('deleteOwn', $workOrder);
        abort_if($this->isCompletedLocked($workOrder), 403);

        if ($workOrder->creator?->role === 'admin' && Auth::user()?->role !== 'admin') {
            $this->requestAdminAssignedDelete($workOrder);

            return response()->json([
                'ok' => true,
                'message' => 'ส่งคำขอลบงานให้ Admin แล้ว',
                'delete_requested' => true,
            ], 202);
        }

        AuditTrail::trash($workOrder, Auth::user(), [
            'work_order' => $workOrder->attributesToArray(),
        ]);
        AuditTrail::log('deleted', $workOrder, 'ลบงาน: '.$workOrder->job_topic, [
            'before' => $workOrder->attributesToArray(),
        ]);
        $workOrder->delete();

        return response()->json([
            'ok' => true,
            'message' => 'ลบงานเรียบร้อยแล้ว',
        ]);
    }

    public function toggleComplete(Request $request, int $job_id, TaskStatusTransitionService $transitions): JsonResponse
    {
        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize((int) $workOrder->job_status === 4 ? 'reopen' : 'update', $workOrder);

        $validated = $request->validate([
            'completed' => 'required|boolean',
            'action' => ['nullable', 'string', 'in:reopen'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $workOrder = $transitions->transition($workOrder, $request->user(), $validated['completed'] ? 4 : 2, [
            'action' => $validated['action'] ?? null,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => $validated['completed'] ? 'ทำเครื่องหมายว่าเสร็จแล้ว' : 'ย้ายกลับไปงานที่ต้องทำแล้ว',
            'completed' => (bool) $validated['completed'],
        ]);
    }

    public function storeSubtask(Request $request, int $job_id): JsonResponse
    {
        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize('update', $workOrder);
        abort_if($this->isCompletedLocked($workOrder), 403);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'details' => 'nullable|string|max:2000',
        ]);

        $subtask = $workOrder->subtasks()->create([
            'created_by' => Auth::id(),
            'title' => $validated['title'],
            'details' => filled($validated['details'] ?? null) ? trim($validated['details']) : null,
            'sort_order' => (int) $workOrder->subtasks()->max('sort_order') + 1,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มงานย่อยแล้ว',
            'subtask_id' => $subtask->id,
        ], 201);
    }

    public function updateSubtask(Request $request, WorkOrderSubtask $subtask): JsonResponse
    {
        $subtask->load('workOrder.collaborators');
        $this->authorize('update', $subtask->workOrder);
        abort_if($this->isCompletedLocked($subtask->workOrder), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $subtask->attributesToArray();
        $subtask->update([
            'title' => trim($validated['title']),
            'details' => filled($validated['details'] ?? null) ? trim($validated['details']) : null,
        ]);

        AuditTrail::log('updated', $subtask->workOrder, 'แก้ไขงานย่อย: '.$subtask->title, [
            'subtask_before' => $before,
            'subtask_after' => $subtask->fresh()->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'แก้ไขงานย่อยแล้ว',
            'subtask_id' => $subtask->id,
        ]);
    }

    public function toggleSubtask(Request $request, WorkOrderSubtask $subtask): JsonResponse
    {
        $subtask->load('workOrder.collaborators');
        $this->authorize('update', $subtask->workOrder);
        abort_if($this->isCompletedLocked($subtask->workOrder), 403);

        $validated = $request->validate([
            'completed' => 'required|boolean',
        ]);

        $subtask->update(['is_completed' => $validated['completed']]);

        return response()->json([
            'ok' => true,
            'message' => $validated['completed'] ? 'ปิดงานย่อยแล้ว' : 'เปิดงานย่อยอีกครั้งแล้ว',
            'completed' => (bool) $validated['completed'],
        ]);
    }

    public function updateStatus(Request $request, int $job_id, TaskStatusTransitionService $transitions): JsonResponse
    {
        $validated = $request->validate([
            'job_status' => 'required|integer|in:1,2,3,4,5,6',
            'action' => ['nullable', 'string', 'in:reopen'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize((int) $workOrder->job_status === 4 ? 'reopen' : 'update', $workOrder);
        $workOrder = $transitions->transition($workOrder, $request->user(), (int) $validated['job_status'], [
            'action' => $validated['action'] ?? null,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => (int) $validated['job_status'] === 4 ? 'ปิดงานสำเร็จ' : 'ปรับสถานะงานสำเร็จ',
            'job_id' => $workOrder->job_id,
            'job_status' => $workOrder->job_status,
        ]);
    }

    public function updatePriority(Request $request, int $job_id): JsonResponse
    {
        $validated = $request->validate([
            'job_priority' => 'required|integer|in:1,2,3,4,5',
        ]);

        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize('update', $workOrder);

        $before = $workOrder->attributesToArray();
        $workOrder->update(['job_priority' => $validated['job_priority']]);

        AuditTrail::log('priority_changed', $workOrder, 'เปลี่ยนความสำคัญของงาน: '.$workOrder->job_topic, [
            'before' => $before,
            'after' => $workOrder->fresh()->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'ปรับความสำคัญแล้ว',
            'job_id' => $workOrder->job_id,
            'job_priority' => $workOrder->job_priority,
        ]);
    }

    public function updateDueDate(Request $request, int $job_id): JsonResponse
    {
        $validated = $request->validate([
            'job_due_at' => 'required|date',
        ]);

        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize('update', $workOrder);
        abort_if($this->isCompletedLocked($workOrder), 403);

        $before = $workOrder->attributesToArray();
        $workOrder->update(['job_due_at' => $validated['job_due_at']]);

        AuditTrail::log('due_date_changed', $workOrder, 'เปลี่ยนกำหนดส่งงาน: '.$workOrder->job_topic, [
            'before' => $before,
            'after' => $workOrder->fresh()->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'job_id' => $workOrder->job_id,
            'job_due_at' => $workOrder->job_due_at->format('Y-m-d'),
        ]);
    }

    private function notifyUsers(array $userIds, WorkOrder $job, string $type, string $title, string $message): void
    {
        $safeTitle = Str::limit(strip_tags($title), 120, '');
        $safeMessage = Str::limit(strip_tags($message), 1000, '');

        app(NotificationService::class)->notify($userIds, $type, $safeTitle, $safeMessage, $job, Auth::user());
    }

    private function notifyAdmins(WorkOrder $job, string $type, string $title, string $message): void
    {
        $adminIds = User::where('role', 'admin')->pluck('id')->all();

        $this->notifyUsers($adminIds, $job, $type, $title, $message);
    }

    private function requestAdminAssignedDelete(WorkOrder $workOrder): void
    {
        if ($workOrder->delete_requested_at) {
            return;
        }

        $user = Auth::user();
        $before = $workOrder->attributesToArray();

        $workOrder->forceFill([
            'delete_requested_by' => $user->id,
            'delete_requested_at' => now(),
            'delete_request_reason' => 'ส่งคำขอจากหน้างานของฉัน',
        ])->save();

        AuditTrail::log('delete_requested', $workOrder, 'ส่งคำขอลบงานที่ Admin มอบหมาย: '.$workOrder->job_topic, [
            'before' => $before,
            'after' => $workOrder->fresh()->attributesToArray(),
            'requested_by' => $user->id,
        ]);

        $this->notifyAdmins(
            $workOrder,
            'delete_request',
            'มีคำขอลบงาน',
            $user->name.' ขออนุญาตลบงาน "'.$workOrder->job_topic.'"'
        );
    }

    /**
     * Return every project list that the current user owns OR that contains at
     * least one work order the current user can access. This keeps project
     * visibility aligned with baseWorkOrderQuery() for same-department
     * assignments, admin assignments and accepted collaborator access.
     */
    private function taskListsForCurrentUser()
    {
        $user = Auth::user();
        $accessibleListIds = $this->baseWorkOrderQuery()
            ->whereNotNull('work_order_list_id')
            ->pluck('work_order_list_id')
            ->filter()
            ->unique();

        return WorkOrderList::with([
            'user',
            'attachments',
            'taskRequests' => fn ($query) => $query
                ->where('status', 'pending')
                ->with('requester')
                ->oldest(),
        ])
            ->where(function ($query) use ($user, $accessibleListIds) {
                $query->where('user_id', $user->id)
                    ->orWhereIn('id', $accessibleListIds);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * งานที่ Admin มอบหมายและยังไม่มีโปรเจกต์ จะถูกจัดให้มีโปรเจกต์ของตัวเอง
     * เพื่อให้แสดงเป็นกลุ่มงานในหน้า "งานของฉัน"
     *
     * ทำเฉพาะงานที่ยังไม่มีโปรเจกต์เท่านั้น งานที่ถูกจัดเข้าโปรเจกต์ไว้แล้ว
     * (เช่น Admin เพิ่มงานเข้าโปรเจกต์ของสมาชิกผ่านหน้า work-board) ต้องคงอยู่ที่เดิม
     * มิฉะนั้นการเปิดหน้านี้จะย้อนสิ่งที่ Admin เพิ่งทำไป
     */
    private function moveAdminAssignmentsToProjectGroups(User $user): void
    {
        $ungroupedAdminAssignments = WorkOrder::query()
            ->where('user_id', $user->id)
            ->whereNull('work_order_list_id')
            ->whereHas('creator', fn ($query) => $query->where('role', 'admin'))
            ->get();

        if ($ungroupedAdminAssignments->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ungroupedAdminAssignments, $user) {
            foreach ($ungroupedAdminAssignments as $job) {
                $projectList = WorkOrderList::create([
                    'user_id' => $user->id,
                    'name' => $job->job_topic,
                    'is_visible' => true,
                    'sort_order' => (int) WorkOrderList::where('user_id', $user->id)->max('sort_order') + 1,
                ]);

                $job->update(['work_order_list_id' => $projectList->id]);
            }
        });
    }

    private function baseWorkOrderQuery()
    {
        $user = Auth::user();

        return WorkOrder::query()->with(['collaborators'])->involving($user);
    }

    private function normalizeTaskScope(mixed $taskScope): string
    {
        return is_string($taskScope) && in_array($taskScope, self::TASK_SCOPES, true)
            ? $taskScope
            : 'all';
    }

    private function applyTaskScope(Builder $query, User $user, string $taskScope): Builder
    {
        return match ($taskScope) {
            'responsible' => $query->where('user_id', $user->id),
            'created' => $query->where('created_by', $user->id),
            'assigned_by_me' => $query
                ->where('assigned_by', $user->id)
                ->where('user_id', '!=', $user->id),
            'collaborating' => $query->whereHas('collaborators', fn (Builder $collaboratorQuery) => $collaboratorQuery
                ->where('users.id', $user->id)
                ->where('work_order_collaborators.status', 'accepted')),
            default => $query,
        };
    }

    private function isCompletedLocked(WorkOrder $workOrder): bool
    {
        return (int) $workOrder->job_status === 4 && Auth::user()?->role !== 'admin';
    }

    private function listIsCompleted(WorkOrderList $list): bool
    {
        return $list->workOrders()->exists()
            && $list->workOrders()->where('job_status', '!=', 4)->doesntExist();
    }
}
