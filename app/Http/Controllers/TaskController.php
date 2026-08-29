<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\Department;
use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Services\CollaboratorInvitationService;
use App\Services\NotificationService;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\ProtectedMedia;
use App\Support\WorkOrderApprovalResolver;
use App\Support\WorkOrderAssignee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TaskController extends Controller
{
    use RespondsWithTaskResult;
    use ValidatesAttachments;

    /**
     * ค่าคงที่ ALLOWED_ATTACHMENT_EXTENSIONS / ALLOWED_ATTACHMENT_MIMES / ATTACHMENT_MAX_KB
     * และเมธอด assertAllowedAttachments() / storeFiles() ย้ายไปอยู่ที่ trait
     * App\Support\Concerns\ValidatesAttachments เพื่อใช้ allow-list เดียวกันกับ
     * MyTaskController (หน้า "งานของฉัน") ด้วย ป้องกันช่องโหว่หลุดจากจุดใดจุดหนึ่ง
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', WorkOrder::class);

        $currentDeptId = $request->integer('department_id') ?: null;
        $currentAssignee = $request->integer('assignee') ?: null;
        $currentStatus = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $query = WorkOrder::with([
            'user.department',
            'department',
            'creator.department',
            'leader',
            'taskList',
            'collaborators.department',
        ]);

        if ($currentDeptId) {
            $query->where('department_id', $currentDeptId);
        }

        if ($currentAssignee) {
            $query->where('user_id', $currentAssignee);
        }

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query
                    ->where('job_topic', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('department', function ($departmentQuery) use ($search) {
                        $departmentQuery->where('department_name', 'like', '%'.$search.'%');
                    });
            });
        }

        $jobs = $query
            ->latest('job_id')
            ->get()
            ->map(function (WorkOrder $job) {
                $job->is_overdue = $this->isOverdue($job);

                return $job;
            });

        if ($currentStatus === 'late') {
            $jobs = $jobs
                ->where('is_overdue', true)
                ->values();
        } elseif (in_array($currentStatus, ['1', '2', '3', '4', '5'], true)) {
            $jobs = $jobs
                ->where('job_status', (int) $currentStatus)
                ->values();
        }

        $departments = Department::orderBy('department_name')->get();

        $employees = WorkOrderAssignee::query()
            ->with('department')
            ->orderBy('name')
            ->get();

        $stats = $this->boardStats($jobs);

        $attentionJobs = $jobs
            ->filter(
                fn (WorkOrder $job) => $job->approval_status !== 'rejected'
                    && ($job->is_overdue || $this->isDueSoon($job))
            )
            ->sortBy(
                fn (WorkOrder $job) => optional($job->job_due_at)->timestamp ?? PHP_INT_MAX
            )
            ->values();

        $workloadByDepartment = $this->departmentWorkload(
            $departments,
            $employees,
            $jobs
        );

        $workloadByUser = $this->userWorkload($employees, $jobs);

        $canManageTasks = Auth::user()?->role === 'admin';

        return view('board.index', compact(
            'jobs',
            'departments',
            'employees',
            'currentDeptId',
            'currentAssignee',
            'currentStatus',
            'search',
            'stats',
            'attentionJobs',
            'workloadByDepartment',
            'workloadByUser',
            'canManageTasks'
        ));
    }

    public function store(Request $request, CollaboratorInvitationService $invitations)
    {
        if ($request->routeIs('admin.tasks.store')) {
            return $this->storeAdminProject($request, $invitations);
        }

        $this->authorize('create', WorkOrder::class);

        $validated = $request->validate($this->storeValidationRules());

        $this->assertAllowedAttachments($request, 'attachments');

        $actor = Auth::user();
        $assignee = isset($validated['user_id'])
            ? WorkOrderAssignee::findWithDepartment((int) $validated['user_id'])
            : $actor->loadMissing('department');
        abort_unless($assignee, 422);

        // กติกาการอนุมัติ (approval_status / approved_by / approved_at / leader_user_id)
        // คำนวณจาก WorkOrderApprovalResolver ตัวเดียวกับที่ MyTaskController::store() ใช้
        // เพื่อไม่ให้ logic เพี้ยนกันไปคนละทางระหว่างสองช่องทางสร้างงาน
        $approval = WorkOrderApprovalResolver::resolve($actor, $assignee);

        $job = DB::transaction(function () use ($validated, $actor, $assignee, $request, $approval, $invitations) {
            $initialStatus = (int) ($validated['job_status'] ?? 1);
            $projectList = null;

            // งานที่ Admin มอบหมายคือ 1 โปรเจกต์ของผู้รับ ไม่ใช่งานย่อยในรายการเดิม
            if ($actor->role === 'admin') {
                $projectList = WorkOrderList::create([
                    'user_id' => $assignee->id,
                    'name' => $validated['job_topic'],
                    'is_visible' => true,
                    'sort_order' => (int) WorkOrderList::where('user_id', $assignee->id)->max('sort_order') + 1,
                ]);
            }

            $job = WorkOrder::create([
                'user_id' => $assignee->id,
                'created_by' => $actor->id,
                'assigned_by' => $actor->id,
                'leader_user_id' => $approval['leader_user_id'],
                'department_id' => $validated['department_id'] ?? $assignee->department_id,
                'work_order_list_id' => $projectList?->id,
                'job_topic' => $validated['job_topic'],
                'job_details' => $validated['job_details'] ?? null,
                'job_priority' => $validated['job_priority'] ?? 2,
                'job_status' => $initialStatus,
                'approval_status' => $approval['approval_status'],
                'approved_by' => $approval['approved_by'],
                'approved_at' => $approval['approved_at'],
                'job_progress' => $initialStatus === 4 ? 100 : 0,
                'job_start_at' => Carbon::parse($validated['job_start_at']),
                'job_due_at' => Carbon::parse($validated['job_due_at']),
                'job_completed_at' => $initialStatus === 4 ? now() : null,
            ]);

            $collaborators = collect($validated['collaborators'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === (int) $assignee->id)
                ->unique()
                ->values();

            foreach ($collaborators as $userId) {
                $candidate = User::find($userId);
                if ($candidate) {
                    $invitations->invite($job, $candidate, $actor);
                }
            }

            $this->storeFiles($request, $job, 'attachments');

            return $job;
        });

        $job->refresh();
        app(NotificationService::class)->notifyAssignmentCreated(
            $job,
            $actor,
            $assignee,
            $approval['same_department']
        );
        AuditTrail::log('created', $job, ($actor->role === 'admin' ? 'Admin สร้างงาน: ' : 'ผู้ใช้ส่งคำขอเปิดงาน: ').$job->job_topic, [
            'after' => $job->attributesToArray(),
        ]);

        $message = Auth::user()->role === 'admin'
            ? 'เพิ่มงานสำเร็จ'
            : ($approval['same_department']
                ? 'เพิ่มงานสำเร็จ'
                : 'ส่งคำขอเปิดงานแล้ว รอผู้ดูแลระบบอนุมัติ');

        return redirect()->route(Auth::user()->role === 'admin' ? 'board.index' : 'mytasks.index')
            ->with('success', $message);
    }

    public function storeForAdminMember(
        Request $request,
        Department $department,
        User $user,
        WorkOrderList $list
    ) {
        abort_unless(
            $user->role === 'user' && (int) $user->department_id === (int) $department->id,
            404
        );

        $this->authorize('create', WorkOrder::class);
        $this->authorize('manage', $list);

        $belongsToWorkspace = WorkOrder::query()
            ->where('work_order_list_id', $list->id)
            ->where('user_id', $user->id)
            ->where('department_id', $department->id)
            ->exists();

        abort_unless($belongsToWorkspace, 404);

        $validated = $request->validate([
            'job_topic' => ['required', 'string', 'max:255'],
        ]);

        $actor = Auth::user();
        $approval = WorkOrderApprovalResolver::resolve($actor, $user);

        $job = DB::transaction(function () use ($validated, $actor, $approval, $department, $user, $list) {
            $job = WorkOrder::create([
                'user_id' => $user->id,
                'created_by' => $actor->id,
                'assigned_by' => $actor->id,
                'leader_user_id' => $approval['leader_user_id'],
                'department_id' => $department->id,
                'work_order_list_id' => $list->id,
                'job_topic' => trim($validated['job_topic']),
                'job_details' => null,
                'job_priority' => 2,
                'job_status' => 1,
                'approval_status' => $approval['approval_status'],
                'approved_by' => $approval['approved_by'],
                'approved_at' => $approval['approved_at'],
                'job_progress' => 0,
                'job_start_at' => now(),
                'job_due_at' => now()->addDay(),
            ]);

            AuditTrail::log('created', $job, 'Admin เพิ่มงานในโปรเจกต์: '.$job->job_topic, [
                'after' => $job->attributesToArray(),
                'work_order_list_id' => $list->id,
                'workspace_user_id' => $user->id,
            ]);

            return $job;
        });

        app(NotificationService::class)->notifyAssignmentCreated($job, $actor, $user, true);

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มงานในโปรเจกต์แล้ว',
            'job_id' => $job->job_id,
            'list_id' => $list->id,
        ], 201);
    }

    private function storeAdminProject(Request $request, CollaboratorInvitationService $invitations)
    {
        $this->authorize('create', WorkOrder::class);
        $validator = validator($request->all(), $this->adminProjectValidationRules());
        $validator->after(function ($validator) use ($request) {
            foreach ((array) $request->input('tasks', []) as $taskIndex => $taskData) {
                if (! is_array($taskData)) {
                    continue;
                }

                $collaboratorIds = collect($taskData['collaborators'] ?? [])->map(fn ($id) => (int) $id);

                if ($collaboratorIds->duplicates()->isNotEmpty()) {
                    $validator->errors()->add("tasks.{$taskIndex}.collaborators", 'ผู้ร่วมงานภายในงานเดียวกันต้องไม่ซ้ำกัน');
                }

                if ($collaboratorIds->contains((int) ($taskData['user_id'] ?? 0))) {
                    $validator->errors()->add("tasks.{$taskIndex}.collaborators", 'ผู้รับผิดชอบหลักต้องไม่อยู่ในรายชื่อผู้ร่วมงาน');
                }
            }
        });
        $validated = $validator->validate();
        $this->assertAllowedAttachments($request, 'project_attachments');
        foreach (array_keys($validated['tasks']) as $taskIndex) {
            $this->assertAllowedAttachments($request, 'tasks.'.$taskIndex.'.attachments');
        }

        $actor = Auth::user();
        $assignees = WorkOrderAssignee::query()->with('department')
            ->whereIn('id', collect($validated['tasks'])->pluck('user_id')->unique())
            ->get()
            ->keyBy('id');

        $storedPaths = [];

        try {
            $createdJobs = DB::transaction(function () use ($validated, $request, $actor, $assignees, &$storedPaths, $invitations) {
                $project = WorkOrderList::create([
                    'user_id' => $actor->id,
                    'name' => trim($validated['project_name']),
                    'priority' => $validated['project_priority'],
                    'is_visible' => true,
                    'sort_order' => (int) WorkOrderList::where('user_id', $actor->id)->max('sort_order') + 1,
                ]);

                if ($request->hasFile('project_attachments')) {
                    foreach ($request->file('project_attachments') as $file) {
                        // getMimeType() ตรวจจากเนื้อไฟล์จริง ต่างจาก getClientMimeType() ที่ปลอมได้ และต้องอ่านก่อนย้ายไฟล์
                        $mimeType = $file->getMimeType();
                        $path = ProtectedMedia::storeAttachment($file, 'project-attachments/'.$project->id);
                        $storedPaths[] = $path;
                        WorkOrderListAttachment::create([
                            'work_order_list_id' => $project->id,
                            'file_path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'file_type' => $mimeType,
                            'uploaded_by' => $actor->id,
                        ]);
                    }
                }

                $jobs = collect();
                foreach ($validated['tasks'] as $taskIndex => $taskData) {
                    $assignee = $assignees->get((int) $taskData['user_id']);
                    abort_unless($assignee, 422, 'ผู้รับผิดชอบไม่ถูกต้อง');
                    $approval = WorkOrderApprovalResolver::resolve($actor, $assignee);
                    abort_unless($approval['approval_status'] === 'approved', 422, 'งานที่ผู้ดูแลระบบสร้างต้องได้รับอนุมัติทันที');

                    $job = WorkOrder::create([
                        'user_id' => $assignee->id,
                        'created_by' => $actor->id,
                        'assigned_by' => $actor->id,
                        'leader_user_id' => $approval['leader_user_id'],
                        'department_id' => $assignee->department_id,
                        'work_order_list_id' => $project->id,
                        'job_topic' => trim($taskData['job_topic']),
                        'job_details' => filled($taskData['job_details'] ?? null) ? trim($taskData['job_details']) : null,
                        'job_priority' => $taskData['job_priority'] ?? 2,
                        'job_status' => 1,
                        'approval_status' => $approval['approval_status'],
                        'approved_by' => $approval['approved_by'],
                        'approved_at' => $approval['approved_at'],
                        'job_progress' => 0,
                        'job_start_at' => Carbon::parse($taskData['job_start_at']),
                        'job_due_at' => Carbon::parse($taskData['job_due_at']),
                    ]);

                    foreach (collect($taskData['subtasks'] ?? [])->filter(fn ($subtask) => filled($subtask['title'] ?? null))->values() as $subtaskIndex => $subtask) {
                        $job->subtasks()->create([
                            'created_by' => $actor->id,
                            'title' => trim($subtask['title']),
                            'details' => filled($subtask['details'] ?? null) ? trim($subtask['details']) : null,
                            'sort_order' => $subtaskIndex + 1,
                        ]);
                    }

                    $collaboratorIds = collect($taskData['collaborators'] ?? [])
                        ->map(fn ($id) => (int) $id)
                        ->reject(fn ($id) => $id === (int) $assignee->id)
                        ->unique()
                        ->values();
                    foreach ($collaboratorIds as $collaboratorId) {
                        $candidate = User::find($collaboratorId);
                        if ($candidate) {
                            $invitations->invite($job, $candidate, $actor);
                        }
                    }

                    $this->storeAdminTaskFiles($request, $job, 'tasks.'.$taskIndex.'.attachments', $storedPaths);
                    AuditTrail::log('created', $job, 'Admin สร้างงานในโปรเจกต์: '.$job->job_topic, [
                        'after' => $job->attributesToArray(),
                        'work_order_list_id' => $project->id,
                    ]);
                    $jobs->push($job);
                }

                AuditTrail::log('created', $project, 'Admin สร้างโปรเจกต์: '.$project->name, [
                    'after' => $project->attributesToArray(),
                    'task_count' => $jobs->count(),
                ]);

                return $jobs;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                ProtectedMedia::deleteAttachment($path);
            }

            throw $exception;
        }

        foreach ($createdJobs as $job) {
            app(NotificationService::class)->notifyAssignmentCreated(
                $job,
                $actor,
                $assignees->get((int) $job->user_id),
                true
            );
        }

        return redirect()->to($this->adminProjectRedirectUrl($request))
            ->with('success', 'สร้างโปรเจกต์และมอบหมายงาน '.$createdJobs->count().' งานสำเร็จ');
    }

    /**
     * ปลายทางหลังสร้างโปรเจกต์สำเร็จ
     *
     * ฟอร์มส่งมาเพียง context ว่าเปิดมาจาก Member Workspace ไหน ไม่ใช่ URL สำเร็จรูป
     * เพื่อไม่ให้เกิดช่อง open redirect เซิร์ฟเวอร์จึงตรวจ context ซ้ำด้วยเงื่อนไข
     * เดียวกับ WorkBoardController::adminMember() แล้วประกอบ named route เอง
     * ถ้า context ไม่ครบหรือถูกแก้ค่า ให้ตกกลับบอร์ดรวมเสมอ
     */
    private function adminProjectRedirectUrl(Request $request): string
    {
        if ($request->input('assignment_origin') !== 'admin-member') {
            return route('board.index');
        }

        $department = Department::find($request->integer('origin_department_id'));
        $member = User::find($request->integer('origin_member_id'));

        if (! $department || ! $member) {
            return route('board.index');
        }

        if ($member->role !== WorkOrderAssignee::ROLE || (int) $member->department_id !== (int) $department->id) {
            return route('board.index');
        }

        return route('admin.work-board.member', [$department, $member]);
    }

    private function storeAdminTaskFiles(Request $request, WorkOrder $job, string $field, array &$storedPaths): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        foreach ($request->file($field) as $file) {
            // getMimeType() ตรวจจากเนื้อไฟล์จริง ต่างจาก getClientMimeType() ที่ปลอมได้ และต้องอ่านก่อนย้ายไฟล์
            $mimeType = $file->getMimeType();
            $path = ProtectedMedia::storeAttachment($file, 'job-attachments/'.$job->job_id);
            $storedPaths[] = $path;

            JobImage::create([
                'job_id' => $job->job_id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'file_type' => $mimeType,
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    public function show($id)
    {
        $job = WorkOrder::findOrFail($id);
        $this->authorize('view', $job);

        return redirect()->route(Auth::user()?->role === 'viewer' ? 'board.index' : 'mytasks.index');
    }

    public function updateDetails(Request $request, $id)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $this->authorize('work', $job);

        if ((int) $job->job_status === 4 && $user?->role !== 'admin') {
            return $this->jsonOrBack($request, false, 'งานนี้ปิดแล้ว ไม่สามารถแก้ไขรายละเอียดได้', 422);
        }

        $validated = $request->validate([
            'job_topic' => ['required', 'string', 'max:255'],
            'job_details' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $job->attributesToArray();
        $job->job_topic = trim($validated['job_topic']);

        // Task Workspace ไม่ส่ง job_details มาแล้ว จึงต้องแตะคอลัมน์นี้เฉพาะเมื่อผู้เรียกส่งมาจริง
        // ถ้าเขียนทับด้วย null ทุกครั้ง ข้อมูลเดิมของงานเก่าจะถูกล้างทิ้งตั้งแต่กดบันทึกครั้งแรก
        if ($request->has('job_details')) {
            $job->job_details = trim((string) ($validated['job_details'] ?? ''));
        }

        $job->save();
        $job->refresh();

        AuditTrail::log('updated', $job, 'แก้ไขรายละเอียดงาน: '.$job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
        ]);

        return $this->jsonOrBack($request, true, 'บันทึกรายละเอียดงานสำเร็จ');
    }

    public function updateSchedule(Request $request, $id)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $this->authorize('work', $job);
        abort_if((int) $job->job_status === 4 && $user?->role !== 'admin', 403);

        $validated = $request->validate([
            'job_start_at' => ['required', 'date'],
            'job_due_at' => ['required', 'date', 'after_or_equal:job_start_at'],
        ]);

        $before = $job->attributesToArray();
        $job->update([
            'job_start_at' => Carbon::parse($validated['job_start_at']),
            'job_due_at' => Carbon::parse($validated['job_due_at']),
        ]);

        AuditTrail::log('schedule_changed', $job, 'เปลี่ยนช่วงเวลางาน: '.$job->job_topic, [
            'before' => $before,
            'after' => $job->fresh()->attributesToArray(),
        ]);

        return $this->jsonOrBack($request, true, 'บันทึกช่วงเวลางานแล้ว');
    }

    public function requestDelete(Request $request, $id)
    {
        $job = WorkOrder::with(['collaborators', 'user', 'creator', 'leader'])->findOrFail($id);
        $user = Auth::user();

        $this->authorize('update', $job);

        if ((int) $job->job_status === 4) {
            return $this->jsonOrBack($request, false, 'งานนี้ปิดแล้ว ไม่สามารถขอลบได้', 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $job->delete_requested_by = $user->id;
        $job->delete_requested_at = now();
        $job->delete_request_reason = $validated['reason'];
        $job->save();

        AuditTrail::log('delete_requested', $job, 'ส่งคำขอลบงาน: '.$job->job_topic, [
            'reason' => Str::limit($validated['reason'], 500),
            'requested_by' => $user->id,
        ]);

        $admins = User::where('role', 'admin')->where('id', '!=', $user->id)->get();
        app(NotificationService::class)->notify($admins, 'delete_request', 'มีคำขอลบงาน',
            $user->name.' ขออนุญาตลบงาน "'.$job->job_topic.'" เหตุผล: '.Str::limit($validated['reason'], 180),
            $job, $user);

        return $this->jsonOrBack($request, true, 'ส่งคำขอลบงานให้ผู้ดูแลระบบแล้ว');
    }

    public function approveDeleteRequest(Request $request, $id)
    {
        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators'])->findOrFail($id);
        $this->authorize('delete', $job);

        if (! $job->delete_requested_at) {
            return $this->jsonOrBack($request, false, 'งานนี้ไม่มีคำขอลบ', 422);
        }

        app(NotificationService::class)->notifyTaskDeleted(
            $job,
            'ผู้ดูแลระบบอนุมัติคำขอลบงาน "'.$job->job_topic.'" แล้ว',
            Auth::user()
        );
        AuditTrail::trash($job, Auth::user(), [
            'work_order' => $job->attributesToArray(),
            'assignee' => $job->user?->only(['id', 'name', 'email']),
            'creator' => $job->creator?->only(['id', 'name', 'email']),
            'leader' => $job->leader?->only(['id', 'name', 'email']),
            'collaborators' => $job->collaborators->map->only(['id', 'name', 'email'])->values()->all(),
        ]);
        AuditTrail::log('deleted', $job, 'Admin ลบงาน: '.$job->job_topic, [
            'before' => $job->attributesToArray(),
        ]);
        $job->delete();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => 'อนุมัติลบงานสำเร็จ',
                'redirect' => route('board.index'),
            ]);
        }

        return redirect()->route('board.index')->with('success', 'อนุมัติลบงานสำเร็จ');
    }

    public function rejectDeleteRequest(Request $request, $id)
    {
        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators', 'deleteRequester'])->findOrFail($id);
        $this->authorize('delete', $job);

        if (! $job->delete_requested_at) {
            return $this->jsonOrBack($request, false, 'งานนี้ไม่มีคำขอลบ', 422);
        }

        $requesterId = $job->delete_requested_by;
        $before = $job->attributesToArray();

        $job->forceFill([
            'delete_requested_by' => null,
            'delete_requested_at' => null,
            'delete_request_reason' => null,
        ])->save();

        $job->refresh();

        AuditTrail::log('delete_request_rejected', $job, 'Admin ปฏิเสธคำขอลบงาน: '.$job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
            'requested_by' => $requesterId,
        ]);

        if ($requesterId && (int) $requesterId !== (int) Auth::id()) {
            app(NotificationService::class)->notify([$requesterId], 'delete_request_rejected', 'คำขอลบงานถูกปฏิเสธ',
                'ผู้ดูแลระบบปฏิเสธคำขอลบงาน "'.$job->job_topic.'"', $job, Auth::user());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => 'ปฏิเสธคำขอลบงานแล้ว',
                'redirect' => route('board.index'),
            ]);
        }

        return redirect()->route('board.index')->with('success', 'ปฏิเสธคำขอลบงานแล้ว');
    }

    public function destroy(Request $request, $id)
    {
        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators'])->findOrFail($id);
        $this->authorize('delete', $job);

        AuditTrail::trash($job, Auth::user(), [
            'work_order' => $job->attributesToArray(),
            'assignee' => $job->user?->only(['id', 'name', 'email']),
            'creator' => $job->creator?->only(['id', 'name', 'email']),
            'leader' => $job->leader?->only(['id', 'name', 'email']),
            'collaborators' => $job->collaborators->map->only(['id', 'name', 'email'])->values()->all(),
        ]);
        AuditTrail::log('deleted', $job, 'Admin ลบงาน: '.$job->job_topic, [
            'before' => $job->attributesToArray(),
        ]);
        app(NotificationService::class)->notifyTaskDeleted(
            $job,
            'ผู้ดูแลระบบลบงาน "'.$job->job_topic.'" แล้ว',
            Auth::user()
        );
        $job->delete();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => 'ลบงานสำเร็จ',
            ]);
        }

        return redirect()->route('board.index')->with('success', 'ลบงานสำเร็จ');
    }

    private function boardStats(Collection $jobs): array
    {
        return [
            'pending' => $jobs->where('approval_status', 'pending')->count(),
            'doing' => $jobs->where('approval_status', 'approved')->whereIn('job_status', [1, 2, 3])->count(),
            'paused' => $jobs->where('approval_status', 'approved')->where('job_status', 5)->count(),
            'done' => $jobs->where('job_status', 4)->count(),
            'late' => $jobs->where('is_overdue', true)->count(),
        ];
    }

    private function departmentWorkload(
        Collection $departments,
        Collection $employees,
        Collection $jobs
    ): Collection {
        return $departments->map(function (Department $department) use ($jobs, $employees) {
            $departmentJobs = $jobs->where('department_id', $department->id);

            $totalJobs = $departmentJobs->count();

            $doneCount = $departmentJobs
                ->where('job_status', 4)
                ->count();

            $projectCount = $departmentJobs
                ->pluck('work_order_list_id')
                ->filter()
                ->unique()
                ->count();

            return [
                'id' => $department->id,
                'name' => $department->department_name,

                'code' => mb_strtoupper(
                    mb_substr($department->department_name, 0, 2)
                ),

                'employee_count' => $employees
                    ->where('department_id', $department->id)
                    ->count(),

                'project_count' => $projectCount,

                'total_jobs' => $totalJobs,

                'done_count' => $doneCount,

                'active_count' => $departmentJobs
                    ->where('job_status', '!=', 4)
                    ->count(),

                'overdue_count' => $departmentJobs
                    ->where('is_overdue', true)
                    ->count(),

                'completion_rate' => $totalJobs > 0
                    ? (int) round(($doneCount / $totalJobs) * 100)
                    : 0,
            ];
        });
    }

    private function userWorkload(Collection $employees, Collection $jobs): Collection
    {
        return $employees->map(function (User $employee) use ($jobs) {
            $assignedJobs = $jobs->where('user_id', $employee->id);
            $latestJob = $assignedJobs->sortByDesc('job_id')->first();

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'department' => optional($employee->department)->department_name ?? '-',
                'profile_image' => $employee->profile_image,
                'latest_job' => $latestJob?->job_topic,
                'active_count' => $assignedJobs->where('job_status', '!=', 4)->count(),
                'done_count' => $assignedJobs->where('job_status', 4)->count(),
            ];
        });
    }

    private function adminProjectValidationRules(): array
    {
        return [
            'project_name' => ['required', 'string', 'max:80'],
            'project_priority' => ['required', 'integer', 'in:1,2,3'],
            'project_attachments' => ['nullable', 'array', 'max:5'],
            'project_attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
            'tasks' => ['required', 'array', 'min:1', 'max:20'],
            'tasks.*.job_topic' => ['required', 'string', 'max:255'],
            'tasks.*.job_details' => ['nullable', 'string', 'max:2000'],
            'tasks.*.user_id' => WorkOrderAssignee::validationRules(),
            'tasks.*.job_priority' => ['nullable', 'integer', 'in:1,2,3,4,5'],
            'tasks.*.job_start_at' => ['required', 'date'],
            'tasks.*.job_due_at' => ['required', 'date', 'after_or_equal:tasks.*.job_start_at'],
            'tasks.*.collaborators' => ['nullable', 'array'],
            'tasks.*.collaborators.*' => ['integer', 'exists:users,id,role,user'],
            'tasks.*.attachments' => ['nullable', 'array', 'max:5'],
            'tasks.*.attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
            'tasks.*.subtasks' => ['nullable', 'array', 'max:50'],
            'tasks.*.subtasks.*.title' => ['nullable', 'string', 'max:255'],
            'tasks.*.subtasks.*.details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function storeValidationRules(): array
    {
        return [
            'job_topic' => ['required', 'string', 'max:255'],
            'job_details' => ['nullable', 'string'],
            'user_id' => WorkOrderAssignee::validationRules(false),
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_priority' => ['nullable', 'integer', 'in:1,2,3,4,5'],
            'job_start_at' => ['required', 'date'],
            'job_due_at' => ['required', 'date', 'after_or_equal:job_start_at'],
            'collaborators' => ['nullable', 'array'],
            'collaborators.*' => ['integer', 'exists:users,id,role,user'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
        ];
    }

    private function isOverdue(WorkOrder $job): bool
    {
        return $job->approval_status === 'approved'
            && (int) $job->job_status !== 4
            && $job->job_due_at
            && Carbon::parse($job->job_due_at)->endOfDay()->lt(now());
    }

    private function isDueSoon(WorkOrder $job): bool
    {
        return $job->approval_status === 'approved'
            && (int) $job->job_status !== 4
            && $job->job_due_at
            && Carbon::parse($job->job_due_at)->between(now(), now()->addDays(3));
    }
}
