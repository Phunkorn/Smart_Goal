<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderUpdate;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    use ValidatesAttachments;

    private const STATUS_META = [
        1 => ['key' => 'todo', 'label' => 'รอดำเนินการ', 'tone' => 'gray', 'icon' => 'bi-clock'],
        2 => ['key' => 'inprogress', 'label' => 'กำลังทำ', 'tone' => 'blue', 'icon' => 'bi-lightning-charge-fill'],
        3 => ['key' => 'review', 'label' => 'ตรวจสอบ', 'tone' => 'amber', 'icon' => 'bi-eye'],
        4 => ['key' => 'done', 'label' => 'เสร็จสิ้น', 'tone' => 'green', 'icon' => 'bi-check-circle-fill'],
        5 => ['key' => 'paused', 'label' => 'พักงานชั่วคราว', 'tone' => 'gray', 'icon' => 'bi-pause-circle'],
    ];

    /**
     * ค่าคงที่ ALLOWED_ATTACHMENT_EXTENSIONS / ALLOWED_ATTACHMENT_MIMES / ATTACHMENT_MAX_KB
     * และเมธอด assertAllowedAttachments() / storeFiles() ย้ายไปอยู่ที่ trait
     * App\Support\Concerns\ValidatesAttachments เพื่อใช้ allow-list เดียวกันกับ
     * MyTaskController (หน้า "งานของฉัน") ด้วย ป้องกันช่องโหว่หลุดจากจุดใดจุดหนึ่ง
     */

    private const PRIORITY_META = [
        1 => ['label' => 'ต่ำ', 'tone' => 'gray'],
        2 => ['label' => 'กลาง', 'tone' => 'amber'],
        3 => ['label' => 'สูง', 'tone' => 'red'],
    ];

    public function index(Request $request)
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'viewer'], true), 403);

        $currentDeptId = $request->integer('department_id') ?: null;
        $currentAssignee = $request->integer('assignee') ?: null;

        $query = WorkOrder::with(['user.department', 'department', 'creator', 'leader', 'collaborators.department']);

        if ($currentDeptId) {
            $query->where('department_id', $currentDeptId);
        }

        if ($currentAssignee) {
            $query->where('user_id', $currentAssignee);
        }

        $jobs = $query->latest('job_id')->get()->map(function (WorkOrder $job) {
            $job->is_overdue = $this->isOverdue($job);
            return $job;
        });

        $departments = Department::orderBy('department_name')->get();
        $employees = User::with('department')
            ->where('role', 'user')
            ->orderBy('name')
            ->get();

        $stats = [
            'pending' => $jobs->where('approval_status', 'pending')->count(),
            'doing' => $jobs->where('approval_status', 'approved')->whereIn('job_status', [1, 2, 3])->count(),
            'paused' => $jobs->where('approval_status', 'approved')->where('job_status', 5)->count(),
            'done' => $jobs->where('job_status', 4)->count(),
            'late' => $jobs->where('is_overdue', true)->count(),
        ];

        $attentionJobs = $jobs
            ->filter(fn (WorkOrder $job) => $job->approval_status !== 'rejected' && ($job->is_overdue || $this->isDueSoon($job)))
            ->sortBy(fn (WorkOrder $job) => optional($job->job_due_at)->timestamp ?? PHP_INT_MAX)
            ->values();

        $workloadByDepartment = $departments->map(function (Department $department) use ($jobs, $employees) {
            $deptJobs = $jobs->where('department_id', $department->id);

            return [
                'name' => $department->department_name,
                'employee_count' => $employees->where('department_id', $department->id)->count(),
                'total_jobs' => $deptJobs->count(),
                'done_count' => $deptJobs->where('job_status', 4)->count(),
                'active_count' => $deptJobs->where('job_status', '!=', 4)->count(),
                'overdue_count' => $deptJobs->where('is_overdue', true)->count(),
            ];
        });

        $workloadByUser = $employees->map(function (User $employee) use ($jobs) {
            $assigned = $jobs->where('user_id', $employee->id);
            $latest = $assigned->sortByDesc('job_id')->first();

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'department' => optional($employee->department)->department_name ?? '-',
                'profile_image' => $employee->profile_image,
                'latest_job' => $latest?->job_topic,
                'active_count' => $assigned->where('job_status', '!=', 4)->count(),
                'done_count' => $assigned->where('job_status', 4)->count(),
            ];
        });

        $canManageTasks = Auth::user()?->role === 'admin';

        return view('board.index', compact(
            'jobs',
            'departments',
            'employees',
            'currentDeptId',
            'currentAssignee',
            'stats',
            'attentionJobs',
            'workloadByDepartment',
            'workloadByUser',
            'canManageTasks'
        ));
    }

    public function store(Request $request)
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'user'], true), 403);

        $validated = $request->validate([
            'job_topic' => ['required', 'string', 'max:255'],
            'job_details' => ['nullable', 'string'],
            'user_id' => ['nullable', 'exists:users,id,role,user'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_priority' => ['nullable', 'integer', 'in:1,2,3'],
            'job_start_at' => ['required', 'date'],
            'job_due_at' => ['required', 'date', 'after_or_equal:job_start_at'],
            'collaborators' => ['nullable', 'array'],
            'collaborators.*' => ['integer', 'exists:users,id,role,user'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:' . implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:' . self::ATTACHMENT_MAX_KB],
        ]);

        $this->assertAllowedAttachments($request, 'attachments');

        $actor = Auth::user();
        $assignee = User::with('department')->find($validated['user_id'] ?? $actor->id);
        abort_unless($assignee, 422);

        $job = DB::transaction(function () use ($validated, $actor, $assignee, $request) {
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
                'leader_user_id' => $actor->role === 'admin' ? $assignee->id : $actor->id,
                'department_id' => $validated['department_id'] ?? $assignee->department_id,
                'work_order_list_id' => $projectList?->id,
                'job_topic' => $validated['job_topic'],
                'job_details' => $validated['job_details'] ?? null,
                'job_priority' => $validated['job_priority'] ?? 2,
                'job_status' => $initialStatus,
                'approval_status' => $actor->role === 'admin' ? 'approved' : 'pending',
                'approved_by' => $actor->role === 'admin' ? $actor->id : null,
                'approved_at' => $actor->role === 'admin' ? now() : null,
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
                $job->collaborators()->syncWithoutDetaching([
                    $userId => [
                        'added_by' => $actor->id,
                        'status' => $actor->role === 'admin' ? 'accepted' : 'pending',
                    ],
                ]);
            }

            $this->storeFiles($request, $job, 'attachments');

            if ($actor->role === 'admin') {
                $this->notifyJobMembers($job, 'admin_created_task', 'มีงานใหม่', 'ผู้ดูแลระบบมอบหมายงาน "' . $job->job_topic . '" ให้คุณ');
            }

            return $job;
        });

        $job->refresh();
        AuditTrail::log('created', $job, ($actor->role === 'admin' ? 'Admin สร้างงาน: ' : 'ผู้ใช้ส่งคำขอเปิดงาน: ') . $job->job_topic, [
            'after' => $job->attributesToArray(),
        ]);

        $message = Auth::user()->role === 'admin'
            ? 'เพิ่มงานสำเร็จ'
            : 'ส่งคำขอเปิดงานแล้ว รอผู้ดูแลระบบอนุมัติ';

        return redirect()->route(Auth::user()->role === 'admin' ? 'board.index' : 'mytasks.index')
            ->with('success', $message);
    }

    public function show($id)
    {
        $job = WorkOrder::with([
            'user.department',
            'department',
            'creator',
            'leader.department',
            'collaborators.department',
            'images',
            'updates.user',
            'deleteRequester',
        ])->findOrFail($id);

        $user = Auth::user();
        $canView = in_array($user?->role, ['admin', 'viewer'], true)
            || in_array($user?->id, [$job->user_id, $job->created_by, $job->leader_user_id], true)
            || $job->collaborators->contains('id', $user?->id);

        abort_unless($canView, 403);

        $canManageTeam = $this->canManageTeam($job, $user);

        $availableCollaborators = User::with('department')
            ->where('role', '!=', 'viewer')
            ->whereNotIn('id', collect([$job->user_id, $job->leader_user_id, Auth::id()])
                ->merge($job->collaborators->pluck('id'))
                ->filter()
                ->unique()
                ->values())
            ->orderBy('name')
            ->get();

        return view('tasks.show', compact('job', 'availableCollaborators', 'canManageTeam'));
    }

    public function updateStatus(Request $request, $id)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $canUpdate = $user?->role === 'admin'
            || in_array($user?->id, [$job->user_id, $job->created_by, $job->leader_user_id], true)
            || $job->collaborators->contains(fn ($person) => $person->id === $user?->id && $person->pivot?->status === 'accepted');

        abort_unless($canUpdate, 403);

        $validated = $request->validate([
            'job_status' => ['required', 'integer', 'in:1,2,3,4,5'],
            'job_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'completion_attachments' => ['nullable', 'array', 'max:5'],
            'completion_attachments.*' => ['file', 'mimes:' . implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:' . self::ATTACHMENT_MAX_KB],
        ]);

        if ((int) $job->job_status === 4 && (int) $validated['job_status'] !== 4 && $user?->role !== 'admin') {
            return $this->jsonOrBack($request, false, 'งานนี้ปิดแล้ว ไม่สามารถเปลี่ยนสถานะกลับได้', 422);
        }

        if ($request->hasFile('completion_attachments') && $job->images()->count() + count($request->file('completion_attachments', [])) > 5) {
            return $this->jsonOrBack($request, false, 'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน', 422);
        }

        $this->assertAllowedAttachments($request, 'completion_attachments');

        $before = $job->attributesToArray();

        DB::transaction(function () use ($job, $validated, $request) {
            $newStatus = (int) $validated['job_status'];

            $job->job_status = $newStatus;
            $job->job_progress = $newStatus === 4 ? 100 : ($validated['job_progress'] ?? $job->job_progress ?? 0);
            $job->job_completed_at = $newStatus === 4 ? ($job->job_completed_at ?: now()) : null;
            $job->save();

            if ($newStatus === 4) {
                $this->storeFiles($request, $job, 'completion_attachments');
            }
        });

        $job->refresh();
        AuditTrail::log('status_changed', $job, 'เปลี่ยนสถานะงาน: ' . $job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
        ]);

        $message = (int) $validated['job_status'] === 4 ? 'ปิดงานสำเร็จ' : 'ปรับสถานะงานสำเร็จ';

        return $this->jsonOrBack($request, true, $message);
    }

    public function uploadAttachments(Request $request, $id)
    {
        $job = WorkOrder::with(['collaborators', 'images'])->findOrFail($id);
        $user = Auth::user();

        $canUpload = $user?->role === 'admin'
            || in_array($user?->id, [$job->user_id, $job->created_by, $job->leader_user_id], true)
            || $job->collaborators->contains(fn ($person) => $person->id === $user?->id && $person->pivot?->status === 'accepted');

        abort_unless($canUpload, 403);
        abort_if((int) $job->job_status === 4 && $user?->role !== 'admin', 403);

        $request->validate([
            'completion_attachments' => ['required', 'array', 'min:1', 'max:5'],
            'completion_attachments.*' => ['file', 'mimes:' . implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:' . self::ATTACHMENT_MAX_KB],
        ]);

        $incomingCount = count($request->file('completion_attachments', []));
        if ($job->images->count() + $incomingCount > 5) {
            return $this->jsonOrBack($request, false, 'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน', 422);
        }

        $this->assertAllowedAttachments($request, 'completion_attachments');

        $this->storeFiles($request, $job, 'completion_attachments');
        AuditTrail::log('attachments_uploaded', $job, 'เพิ่มไฟล์อ้างอิงงาน: ' . $job->job_topic, [
            'field' => 'completion_attachments',
            'count' => count($request->file('completion_attachments', [])),
        ]);

        return $this->jsonOrBack($request, true, 'เพิ่มไฟล์อ้างอิงงานสำเร็จ');
    }

    public function addCollaborators(Request $request, $id)
    {
        $job = WorkOrder::with(['collaborators', 'user.department', 'leader.department'])->findOrFail($id);
        abort_unless($this->canManageTeam($job, Auth::user()), 403);
        abort_if((int) $job->job_status === 4 && Auth::user()?->role !== 'admin', 403);

        $validated = $request->validate([
            'collaborators' => ['required', 'array', 'min:1'],
            'collaborators.*' => ['integer', 'exists:users,id'],
        ]);

        $existingIds = collect([$job->user_id, $job->leader_user_id, Auth::id()])
            ->merge($job->collaborators->pluck('id'))
            ->filter()
            ->unique();

        $eligibleUsers = User::with('department')
            ->where('role', 'user')
            ->whereIn('id', $validated['collaborators'])
            ->get();

        $newUsers = $eligibleUsers
            ->reject(fn (User $candidate) => $existingIds->contains((int) $candidate->id))
            ->unique('id')
            ->values();

        $actor = Auth::user();
        $actorLabel = $actor?->role === 'admin' ? 'ผู้ดูแลระบบ' : ($actor?->name ?? 'หัวหน้างาน');
        $jobDepartmentId = $job->department_id ?: $job->user?->department_id;
        $admins = User::where('role', 'admin')->get();

        foreach ($newUsers as $candidate) {
            $sameDepartment = $jobDepartmentId && (int) $candidate->department_id === (int) $jobDepartmentId;
            $pivotStatus = $sameDepartment || $actor?->role === 'admin' ? 'accepted' : 'pending';

            $job->collaborators()->syncWithoutDetaching([
                $candidate->id => [
                    'added_by' => Auth::id(),
                    'status' => $pivotStatus,
                    'responded_at' => $pivotStatus === 'accepted' ? now() : null,
                ],
            ]);

            AuditTrail::log('collaborator_added', $job, 'เพิ่มผู้ร่วมโปรเจกต์ในงาน: ' . $job->job_topic, [
                'user_id' => $candidate->id,
                'status' => $pivotStatus,
            ]);

            if ($pivotStatus === 'accepted') {
                SystemNotification::create([
                    'user_id' => $candidate->id,
                    'work_order_id' => $job->job_id,
                    'type' => 'collaborator_added',
                    'title' => 'ถูกเพิ่มเข้าร่วมงาน',
                    'message' => $actorLabel . ' เพิ่มคุณเข้าร่วมงาน "' . $job->job_topic . '"',
                ]);

                continue;
            }

            foreach ($admins as $admin) {
                SystemNotification::create([
                    'user_id' => $admin->id,
                    'work_order_id' => $job->job_id,
                    'type' => 'collaborator_approval_request',
                    'title' => 'ขออนุมัติผู้ร่วมงานข้ามแผนก',
                    'message' => $actorLabel . ' ขอเพิ่ม ' . $candidate->name . ' (' . ($candidate->department?->department_name ?? 'ไม่ระบุแผนก') . ') เข้าร่วมงาน "' . $job->job_topic . '"',
                ]);
            }
        }

        return $this->jsonOrBack($request, true, $newUsers->isEmpty() ? 'พนักงานคนนี้อยู่ในรายการเชิญหรือทีมแล้ว' : 'เพิ่ม/ส่งคำขอผู้ร่วมงานสำเร็จ');
    }

    public function removeCollaborator(Request $request, $id, User $user)
    {
        $job = WorkOrder::with(['collaborators', 'user', 'leader'])->findOrFail($id);
        abort_unless($this->canManageTeam($job, Auth::user()), 403);
        abort_if((int) $job->job_status === 4 && Auth::user()?->role !== 'admin', 403);
        abort_if(in_array($user->id, [$job->user_id, $job->created_by, $job->leader_user_id], true), 422, 'ไม่สามารถลบผู้รับผิดชอบหลักหรือหัวหน้างานออกจากทีมได้');

        $job->collaborators()->detach($user->id);

        AuditTrail::log('collaborator_removed', $job, 'นำผู้ร่วมโปรเจกต์ออกจากงาน: ' . $job->job_topic, [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        SystemNotification::create([
            'user_id' => $user->id,
            'work_order_id' => $job->job_id,
            'type' => 'collaborator_removed',
            'title' => 'ถูกนำออกจากงาน',
            'message' => 'คุณถูกนำออกจากทีมงาน "' . $job->job_topic . '"',
        ]);

        return $this->jsonOrBack($request, true, 'นำผู้ร่วมงานออกจากทีมแล้ว');
    }

    public function updateProgress(Request $request, $id)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $canUpdate = $this->canWorkOnJob($job, $user);
        abort_unless($canUpdate, 403);

        if ($job->approval_status !== 'approved') {
            return $this->jsonOrBack($request, false, 'งานนี้ยังไม่ได้รับอนุมัติ', 422);
        }

        if ((int) $job->job_status === 4 && $user?->role !== 'admin') {
            return $this->jsonOrBack($request, false, 'งานนี้ปิดแล้ว ไม่สามารถอัปเดตความคืบหน้าได้', 422);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $before = $job->attributesToArray();
        $subtaskCount = $job->subtasks()->count();
        $completedSubtaskCount = $job->subtasks()->where('is_completed', true)->count();
        $progress = $subtaskCount > 0
            ? (int) round(($completedSubtaskCount / $subtaskCount) * 100)
            : (int) $job->job_progress;

        DB::transaction(function () use ($job, $validated, $user, $progress) {
            WorkOrderUpdate::create([
                'work_order_id' => $job->job_id,
                'user_id' => $user->id,
                // ความคืบหน้าถูกคำนวณจากงานย่อย จึงไม่รับค่าจากผู้ใช้โดยตรง
                'progress' => $progress,
                'note' => $validated['note'],
            ]);

            $job->job_progress = $progress;
            if ((int) $job->job_status === 1) {
                $job->job_status = 2;
            }
            $job->save();
        });

        $job->refresh();
        AuditTrail::log('progress_updated', $job, 'เพิ่มความคิดเห็น/อัปเดตงาน: ' . $job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
            'progress' => $progress,
            'note' => Str::limit($validated['note'], 200),
        ]);

        return $this->jsonOrBack($request, true, 'อัปเดตความคืบหน้าสำเร็จ');
    }

    public function requestDelete(Request $request, $id)
    {
        $job = WorkOrder::with(['collaborators', 'user', 'creator', 'leader'])->findOrFail($id);
        $user = Auth::user();

        abort_unless($this->canWorkOnJob($job, $user), 403);

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

        AuditTrail::log('delete_requested', $job, 'ส่งคำขอลบงาน: ' . $job->job_topic, [
            'reason' => Str::limit($validated['reason'], 500),
            'requested_by' => $user->id,
        ]);

        $admins = User::where('role', 'admin')->where('id', '!=', $user->id)->get();
        foreach ($admins as $admin) {
            SystemNotification::create([
                'user_id' => $admin->id,
                'work_order_id' => $job->job_id,
                'type' => 'delete_request',
                'title' => 'มีคำขอลบงาน',
                'message' => $user->name . ' ขออนุญาตลบงาน "' . $job->job_topic . '" เหตุผล: ' . Str::limit($validated['reason'], 180),
            ]);
        }

        return $this->jsonOrBack($request, true, 'ส่งคำขอลบงานให้ผู้ดูแลระบบแล้ว');
    }

    public function approveDeleteRequest(Request $request, $id)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators'])->findOrFail($id);

        if (! $job->delete_requested_at) {
            return $this->jsonOrBack($request, false, 'งานนี้ไม่มีคำขอลบ', 422);
        }

        $this->notifyJobDeleted($job, 'ผู้ดูแลระบบอนุมัติคำขอลบงาน "' . $job->job_topic . '" แล้ว');
        AuditTrail::trash($job, Auth::user(), [
            'work_order' => $job->attributesToArray(),
            'assignee' => $job->user?->only(['id', 'name', 'email']),
            'creator' => $job->creator?->only(['id', 'name', 'email']),
            'leader' => $job->leader?->only(['id', 'name', 'email']),
            'collaborators' => $job->collaborators->map->only(['id', 'name', 'email'])->values()->all(),
        ]);
        AuditTrail::log('deleted', $job, 'Admin ลบงาน: ' . $job->job_topic, [
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
        abort_unless(Auth::user()?->role === 'admin', 403);

        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators', 'deleteRequester'])->findOrFail($id);

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

        AuditTrail::log('delete_request_rejected', $job, 'Admin ปฏิเสธคำขอลบงาน: ' . $job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
            'requested_by' => $requesterId,
        ]);

        if ($requesterId && (int) $requesterId !== (int) Auth::id()) {
            SystemNotification::create([
                'user_id' => $requesterId,
                'work_order_id' => $job->job_id,
                'type' => 'delete_request_rejected',
                'title' => 'คำขอลบงานถูกปฏิเสธ',
                'message' => 'ผู้ดูแลระบบปฏิเสธคำขอลบงาน "' . $job->job_topic . '"',
            ]);
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

    public function updateApproval(Request $request, $id)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $validated = $request->validate([
            'approval_status' => ['required', 'in:approved,rejected'],
        ]);

        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators'])->findOrFail($id);
        $before = $job->attributesToArray();

        $job->approval_status = $validated['approval_status'];
        $job->approved_by = Auth::id();
        $job->approved_at = now();

        if ($validated['approval_status'] === 'approved' && (int) $job->job_status === 1) {
            $job->job_status = 2;
        }

        $job->save();

        $job->refresh();
        AuditTrail::log('approval_updated', $job, 'Admin อัปเดตการอนุมัติงาน: ' . $job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
        ]);

        if ($validated['approval_status'] === 'approved') {
            $title = 'งานได้รับอนุมัติแล้ว';
            $message = 'ผู้ดูแลระบบอนุมัติงาน "' . $job->job_topic . '" แล้ว';
        } else {
            $title = 'งานไม่ผ่านการอนุมัติ';
            $message = 'ผู้ดูแลระบบปฏิเสธคำขอเปิดงาน "' . $job->job_topic . '"';
        }

        $this->notifyJobMembers($job, 'admin_approval', $title, $message);

        return back()->with('success', $title);
    }

    public function destroy($id)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators'])->findOrFail($id);
        AuditTrail::trash($job, Auth::user(), [
            'work_order' => $job->attributesToArray(),
            'assignee' => $job->user?->only(['id', 'name', 'email']),
            'creator' => $job->creator?->only(['id', 'name', 'email']),
            'leader' => $job->leader?->only(['id', 'name', 'email']),
            'collaborators' => $job->collaborators->map->only(['id', 'name', 'email'])->values()->all(),
        ]);
        AuditTrail::log('deleted', $job, 'Admin ลบงาน: ' . $job->job_topic, [
            'before' => $job->attributesToArray(),
        ]);
        $this->notifyJobDeleted($job, 'ผู้ดูแลระบบลบงาน "' . $job->job_topic . '" แล้ว');
        $job->delete();

        return redirect()->route('board.index')->with('success', 'ลบงานสำเร็จ');
    }

    public function respondInvitation(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
        ]);

        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $collaborator = $job->collaborators->firstWhere('id', Auth::id());

        abort_unless($collaborator, 403);

        $job->collaborators()->updateExistingPivot(Auth::id(), [
            'status' => $validated['status'],
            'responded_at' => now(),
        ]);

        $message = $validated['status'] === 'accepted' ? 'รับเข้าร่วมงานแล้ว' : 'ปฏิเสธคำเชิญแล้ว';

        return back()->with('success', $message);
    }

    private function notifyJobMembers(WorkOrder $job, string $type, string $title, string $message): void
    {
        $job->loadMissing('collaborators');

        $safeTitle = Str::limit(strip_tags($title), 120, '');
        $safeMessage = Str::limit(strip_tags($message), 1000, '');

        $userIds = collect([$job->user_id, $job->created_by, $job->leader_user_id])
            ->merge($job->collaborators->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($userId) => (int) $userId === (int) Auth::id())
            ->values();

        foreach ($userIds as $userId) {
            SystemNotification::create([
                'user_id' => $userId,
                'work_order_id' => $job->job_id,
                'type' => $type,
                'title' => $safeTitle,
                'message' => $safeMessage,
            ]);
        }
    }

    private function notifyJobDeleted(WorkOrder $job, string $message): void
    {
        $job->loadMissing('collaborators');

        $userIds = collect([$job->user_id, $job->created_by, $job->leader_user_id])
            ->merge($job->collaborators->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($userId) => (int) $userId === (int) Auth::id())
            ->values();

        foreach ($userIds as $userId) {
            SystemNotification::create([
                'user_id' => $userId,
                'work_order_id' => null,
                'type' => 'task_deleted',
                'title' => 'งานถูกลบแล้ว',
                'message' => Str::limit(strip_tags($message), 1000, ''),
            ]);
        }
    }

    private function canWorkOnJob(WorkOrder $job, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return in_array($user->id, [$job->user_id, $job->created_by, $job->leader_user_id], true)
            || $job->collaborators->contains(fn ($person) => $person->id === $user->id && $person->pivot?->status === 'accepted');
    }

    private function canManageTeam(WorkOrder $job, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->role === 'admin'
            || in_array($user->id, [$job->created_by, $job->leader_user_id], true);
    }

    private function jsonOrBack(Request $request, bool $ok, string $message, int $status = 200)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => $ok, 'message' => $message], $status);
        }

        return $ok
            ? back()->with('success', $message)
            : back()->withErrors(['status' => $message]);
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
