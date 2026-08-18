<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Models\WorkOrderSubtask;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\WorkOrderApprovalResolver;
use App\Support\ProjectCreatorSummary;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MyTaskController extends Controller
{
    use ValidatesAttachments;

    public function index(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user->role === 'viewer') {
            return redirect()->route('board.index');
        }

        $this->moveAdminAssignmentsToProjectGroups($user);
        $taskLists = $this->taskListsForCurrentUser();
        $manageableTaskLists = $taskLists->where('user_id', $user->id)->values();
        $defaultList = $manageableTaskLists->first();

        if ($defaultList) {
            WorkOrder::where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('created_by', $user->id)
                    ->orWhere('leader_user_id', $user->id)
                    ->orWhereHas('collaborators', fn ($collaboratorQuery) => $collaboratorQuery
                        ->where('users.id', $user->id)
                        ->where('work_order_collaborators.status', 'accepted'));
            })
                ->whereNull('work_order_list_id')
                ->update(['work_order_list_id' => $defaultList->id]);
        }

        $workOrders = $this->baseWorkOrderQuery()
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
            ])
            ->withCount('images')
            ->orderByRaw('job_status = 4 asc')
            ->orderBy('job_due_at')
            ->latest('job_id')
            ->get();

        $taskLists = $this->taskListsForCurrentUser();
        $manageableTaskLists = $taskLists->where('user_id', $user->id)->values();

        $visibleLists = $taskLists->where('is_visible', true)->values();
        $activeTasks = $workOrders->reject(fn (WorkOrder $workOrder) => (int) $workOrder->job_status === 4)->values();
        $completedTasks = $workOrders->filter(fn (WorkOrder $workOrder) => (int) $workOrder->job_status === 4)->values();
        $availableCollaborators = User::with('department')
            ->where('role', 'user')
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();
        $projectCreatorMeta = ProjectCreatorSummary::forListIds($taskLists->pluck('id'));

        return view('tasks.index', compact(
            'taskLists',
            'manageableTaskLists',
            'visibleLists',
            'activeTasks',
            'completedTasks',
            'availableCollaborators',
            'projectCreatorMeta'
        ));
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
                        $path = $file->store('project-attachments/'.$list->id, 'public');
                        WorkOrderListAttachment::create([
                            'work_order_list_id' => $list->id,
                            'file_path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'file_type' => $file->getClientMimeType(),
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

        $assignee = User::with('department')->find($validated['user_id'] ?? $actor->id);
        abort_unless($assignee && $assignee->role !== 'viewer', 422, 'ผู้รับผิดชอบไม่ถูกต้อง');

        // กติกาการอนุมัติ (approval_status / approved_by / approved_at / leader_user_id)
        // คำนวณจาก WorkOrderApprovalResolver ตัวเดียวกับที่ TaskController::store() ใช้
        // เพื่อไม่ให้ logic เพี้ยนกันไปคนละทางระหว่างสองช่องทางสร้างงาน
        $approval = WorkOrderApprovalResolver::resolve($actor, $assignee);
        $sameDepartment = $approval['same_department'];

        $job = DB::transaction(function () use ($validated, $actor, $assignee, $sameDepartment, $approval, $request, $projectItems) {
            $leaderId = $approval['leader_user_id'];
            $firstItem = $projectItems->first();
            $projectName = trim((string) ($validated['project_name'] ?? '')) ?: $firstItem['job_topic'];

            // Create a dedicated project group so the new work card is rendered beneath its group-head.
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
                    $path = $file->store('project-attachments/'.$list->id, 'public');
                    WorkOrderListAttachment::create([
                        'work_order_list_id' => $list->id,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type' => $file->getClientMimeType(),
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

    /**
     * Keep the HTTP contract for project creation in one place so store() can
     * focus on authorization and orchestration.
     */
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
            'user_id' => ['nullable', 'exists:users,id'],
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

    /**
     * Normalize both the current multi-item payload and the legacy single-item
     * payload into the same collection shape.
     */
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

    public function toggleComplete(Request $request, int $job_id): JsonResponse
    {
        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize('update', $workOrder);
        abort_if($this->isCompletedLocked($workOrder), 403);

        $validated = $request->validate([
            'completed' => 'required|boolean',
        ]);

        if ((int) $workOrder->job_status === 4 && ! $validated['completed'] && Auth::user()?->role !== 'admin') {
            return response()->json([
                'ok' => false,
                'message' => 'โปรเจกต์นี้ปิดแล้ว ไม่สามารถเปิดกลับได้',
            ], 422);
        }

        if ($validated['completed'] && ! $this->hasCompletedAllSubtasks($workOrder)) {
            return response()->json([
                'ok' => false,
                'message' => 'กรุณาติ๊กงานย่อยให้ครบทุกข้อก่อนปิดโปรเจกต์',
            ], 422);
        }

        $workOrder->update($validated['completed']
            ? [
                'job_status' => 4,
                'job_progress' => 100,
                'job_completed_at' => now(),
            ]
            : [
                'job_status' => 2,
                'job_progress' => min((int) $workOrder->job_progress, 99),
                'job_completed_at' => null,
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
        ]);

        $subtask = $workOrder->subtasks()->create([
            'created_by' => Auth::id(),
            'title' => $validated['title'],
            'details' => null,
            'sort_order' => (int) $workOrder->subtasks()->max('sort_order') + 1,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มงานย่อยแล้ว',
            'subtask_id' => $subtask->id,
        ], 201);
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

    public function updateStatus(Request $request, int $job_id): JsonResponse
    {
        $validated = $request->validate([
            'job_status' => 'required|integer|in:2,4,5',
        ]);

        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorize('update', $workOrder);

        if ((int) $workOrder->job_status === 4 && (int) $validated['job_status'] !== 4 && Auth::user()?->role !== 'admin') {
            return response()->json([
                'ok' => false,
                'message' => 'งานนี้ปิดแล้ว ไม่สามารถเปลี่ยนสถานะกลับได้',
            ], 422);
        }

        if ((int) $validated['job_status'] === 4 && ! $this->hasCompletedAllSubtasks($workOrder)) {
            return response()->json([
                'ok' => false,
                'message' => 'กรุณาติ๊กงานย่อยให้ครบทุกข้อก่อนปิดโปรเจกต์',
            ], 422);
        }

        $updates = ['job_status' => $validated['job_status']];

        if ((int) $validated['job_status'] === 4) {
            $updates['job_progress'] = 100;
            $updates['job_completed_at'] = $workOrder->job_completed_at ?? now();
        }

        $before = $workOrder->attributesToArray();
        $workOrder->update($updates);

        AuditTrail::log('status_changed', $workOrder, 'เปลี่ยนสถานะงาน: '.$workOrder->job_topic, [
            'before' => $before,
            'after' => $workOrder->fresh()->attributesToArray(),
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

        foreach (collect($userIds)->filter()->unique() as $userId) {
            SystemNotification::create([
                'user_id' => $userId,
                'work_order_id' => $job->job_id,
                'type' => $type,
                'title' => $safeTitle,
                'message' => $safeMessage,
            ]);
        }
    }

    /** โปรเจกต์ปิดได้ต่อเมื่องานย่อยอย่างน้อยหนึ่งข้อถูกทำครบทั้งหมด */
    private function hasCompletedAllSubtasks(WorkOrder $workOrder): bool
    {
        $total = $workOrder->subtasks()->count();

        return $total > 0
            && $workOrder->subtasks()->where('is_completed', false)->doesntExist();
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
     * ดึงรายการ (list) ของผู้ใช้ปัจจุบันเท่านั้น
     * หมายเหตุ: ตั้งใจไม่สร้าง "งานของฉัน" อัตโนมัติอีกต่อไป
     * เพื่อให้ผู้ใช้ลบรายการสุดท้ายได้จริง โดยไม่มีอะไรมาสร้างกลับ
     */
    private function taskListsForCurrentUser()
    {
        $user = Auth::user();
        $adminAssignedListIds = $this->baseWorkOrderQuery()
            ->whereHas('creator', fn ($query) => $query->where('role', 'admin'))
            ->whereNotNull('work_order_list_id')
            ->pluck('work_order_list_id')
            ->unique();

        return WorkOrderList::with('attachments')
            ->where(function ($query) use ($user, $adminAssignedListIds) {
                $query->where('user_id', $user->id)
                    ->orWhereIn('id', $adminAssignedListIds);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * ย้ายงานเก่าที่ Admin เคยมอบหมายและยังปะปนอยู่ในรายการเดิม
     * ให้เป็นโปรเจกต์แยกของผู้รับเพียงครั้งเดียว
     */
    private function moveAdminAssignmentsToProjectGroups(User $user): void
    {
        $adminAssignments = WorkOrder::query()
            ->where('user_id', $user->id)
            ->whereHas('creator', fn ($query) => $query->where('role', 'admin'))
            ->get();

        if ($adminAssignments->isEmpty()) {
            return;
        }

        $listIds = $adminAssignments->pluck('work_order_list_id')->filter()->unique();
        $lists = WorkOrderList::whereIn('id', $listIds)
            ->get()
            ->keyBy('id');
        $tasksPerList = WorkOrder::whereIn('work_order_list_id', $listIds)
            ->selectRaw('work_order_list_id, count(*) as total')
            ->groupBy('work_order_list_id')
            ->pluck('total', 'work_order_list_id');

        DB::transaction(function () use ($adminAssignments, $lists, $tasksPerList, $user) {
            foreach ($adminAssignments as $job) {
                $currentList = $lists->get($job->work_order_list_id);
                if ($currentList && (int) $currentList->user_id !== (int) $user->id) {
                    continue;
                }

                $isDedicatedProject = $currentList
                    && $currentList->name === $job->job_topic
                    && (int) ($tasksPerList[$currentList->id] ?? 0) === 1;

                if ($isDedicatedProject) {
                    continue;
                }

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

        $query = WorkOrder::query()->with(['collaborators']);

        if ($user->role !== 'admin') {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('created_by', $user->id)
                    ->orWhere('leader_user_id', $user->id)
                    ->orWhereHas('collaborators', fn ($collaboratorQuery) => $collaboratorQuery
                        ->where('users.id', $user->id)
                        ->where('work_order_collaborators.status', 'accepted'));
            });
        }

        return $query;
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
