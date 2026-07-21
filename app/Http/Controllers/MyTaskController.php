<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderSubtask;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $defaultList = $taskLists->first();

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

        $visibleLists = $taskLists->where('is_visible', true)->values();
        $activeTasks = $workOrders->reject(fn (WorkOrder $workOrder) => (int) $workOrder->job_status === 4)->values();
        $completedTasks = $workOrders->filter(fn (WorkOrder $workOrder) => (int) $workOrder->job_status === 4)->values();
        $availableCollaborators = User::with('department')
            ->where('role', 'user')
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('tasks.index', compact(
            'taskLists',
            'visibleLists',
            'activeTasks',
            'completedTasks',
            'availableCollaborators'
        ));
    }

    public function storeQuickTask(Request $request): JsonResponse
    {
        $user = Auth::user();

        abort_if($user->role === 'viewer', 403);

        $taskLists = $this->taskListsForCurrentUser();

        $validated = $request->validate([
            'job_topic' => 'required|string|max:255',
            'work_order_list_id' => 'nullable|exists:work_order_lists,id',
        ]);

        $list = $taskLists->firstWhere('id', (int) ($validated['work_order_list_id'] ?? 0)) ?? $taskLists->first();

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

        AuditTrail::log('created', $workOrder, 'Created quick task: ' . $workOrder->job_topic, [
            'after' => $workOrder->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'สร้างงานแล้ว',
            'job_id' => $workOrder->job_id,
        ], 201);
    }

    /**
     * สร้างงานแบบเต็มรูปแบบจากหน้า "งานของฉัน" (หัวข้อ, รายละเอียด, ผู้รับผิดชอบ,
     * ผู้ร่วมงาน, วันเริ่ม-สิ้นสุด, ความสำคัญ, ไฟล์แนบ)
     *
     * กติกาการอนุมัติ:
     * - มอบหมายให้ตัวเอง หรือมอบหมายให้พนักงานแผนกเดียวกัน => สร้างงานได้ทันที (approved)
     * - มอบหมายให้พนักงานต่างแผนก => สถานะ "รออนุมัติ" และแจ้งเตือนไปยัง Admin ทุกคน
     *   ให้เป็นผู้ตัดสินใจรับหรือปฏิเสธงานแทน (ผ่านปุ่มอนุมัติ/ปฏิเสธที่มีอยู่แล้วในหน้าบอร์ด)
     */
    public function store(Request $request): JsonResponse
    {
        $actor = Auth::user();

        abort_if($actor->role === 'viewer', 403);

        $validated = $request->validate([
            'job_topic' => ['required', 'string', 'max:255'],
            'job_details' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'exists:users,id'],
            'collaborators' => ['nullable', 'array'],
            'collaborators.*' => ['integer', 'exists:users,id'],
            'job_start_at' => ['required', 'date'],
            'job_due_at' => ['required', 'date', 'after_or_equal:job_start_at'],
            'job_priority' => ['nullable', 'integer', 'in:1,2,3'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:' . implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:' . self::ATTACHMENT_MAX_KB],
        ]);

        $this->assertAllowedAttachments($request, 'attachments');

        $assignee = User::with('department')->find($validated['user_id'] ?? $actor->id);
        abort_unless($assignee && $assignee->role !== 'viewer', 422, 'ผู้รับผิดชอบไม่ถูกต้อง');

        $sameDepartment = (int) $assignee->id === (int) $actor->id
            || ($assignee->department_id && $actor->department_id
                && (int) $assignee->department_id === (int) $actor->department_id);

        $job = DB::transaction(function () use ($validated, $actor, $assignee, $sameDepartment, $request) {
            // Create a dedicated project group so the new work card is rendered beneath its group-head.
            $list = WorkOrderList::create([
                'user_id' => $actor->id,
                'name' => $validated['job_topic'],
                'is_visible' => true,
                'sort_order' => (int) WorkOrderList::where('user_id', $actor->id)->max('sort_order') + 1,
            ]);

            $job = WorkOrder::create([
                'user_id' => $assignee->id,
                'created_by' => $actor->id,
                'leader_user_id' => $actor->id,
                'department_id' => $assignee->department_id ?? $actor->department_id,
                'work_order_list_id' => $list->id,
                'job_topic' => $validated['job_topic'],
                'job_details' => $validated['job_details'] ?? null,
                'job_priority' => $validated['job_priority'] ?? 2,
                'job_status' => 1,
                'approval_status' => $sameDepartment ? 'approved' : 'pending',
                'approved_by' => $sameDepartment ? $actor->id : null,
                'approved_at' => $sameDepartment ? now() : null,
                'job_progress' => 0,
                'job_start_at' => Carbon::parse($validated['job_start_at']),
                'job_due_at' => Carbon::parse($validated['job_due_at']),
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

            $this->storeFiles($request, $job, 'attachments');

            return $job;
        });

        $job->refresh();

        AuditTrail::log('created', $job, ($sameDepartment ? 'User created task: ' : 'User requested cross-department task: ') . $job->job_topic, [
            'after' => $job->attributesToArray(),
        ]);

        if ($sameDepartment) {
            if ((int) $assignee->id !== (int) $actor->id) {
                $this->notifyUsers([$assignee->id], $job, 'task_assigned', 'มีงานใหม่', $actor->name . ' มอบหมายงาน "' . $job->job_topic . '" ให้คุณ');
            }
            $message = 'สร้างงานสำเร็จ';
        } else {
            $this->notifyAdmins($job, 'cross_department_pending', 'มีคำขอเปิดงานข้ามแผนกรอตรวจสอบ',
                $actor->name . ' ต้องการมอบหมายงาน "' . $job->job_topic . '" ให้ ' . $assignee->name . ' (ต่างแผนก) กรุณาตรวจสอบและอนุมัติ/ปฏิเสธ');
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

        abort_if($user->role === 'viewer', 403);

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

    public function toggleList(Request $request, WorkOrderList $list): JsonResponse
    {
        abort_unless($list->user_id === Auth::id(), 403);

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

    public function destroyList(WorkOrderList $list): JsonResponse
    {
        $user = Auth::user();

        abort_if($user->role === 'viewer', 403);
        abort_unless($list->user_id === $user->id, 403);

        DB::transaction(function () use ($list, $user) {
            AuditTrail::trash($list, $user, [
                'list' => $list->attributesToArray(),
                'work_order_count' => $list->workOrders()->count(),
            ]);
            AuditTrail::log('deleted', $list, 'Deleted task list: ' . $list->name, [
                'before' => $list->attributesToArray(),
            ]);

            $list->workOrders()->eachById(function (WorkOrder $workOrder) {
                AuditTrail::trash($workOrder, Auth::user(), [
                    'work_order' => $workOrder->attributesToArray(),
                    'deleted_with_list' => true,
                ]);
                AuditTrail::log('deleted', $workOrder, 'Deleted task with list: ' . $workOrder->job_topic, [
                    'before' => $workOrder->attributesToArray(),
                ]);
                $workOrder->delete();
            }, 100, 'job_id');

            $list->delete();
        });

        return response()->json([
            'ok' => true,
            'message' => 'ลบรายการและงานในรายการเรียบร้อยแล้ว',
        ]);
    }

    public function destroy(int $job_id): JsonResponse
    {
        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorizeWorkOrderDeletion($workOrder);

        AuditTrail::trash($workOrder, Auth::user(), [
            'work_order' => $workOrder->attributesToArray(),
        ]);
        AuditTrail::log('deleted', $workOrder, 'Deleted task: ' . $workOrder->job_topic, [
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
        $this->authorizeWorkOrderAccess($workOrder);

        $validated = $request->validate([
            'completed' => 'required|boolean',
        ]);

        if ((int) $workOrder->job_status === 4 && ! $validated['completed']) {
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
        $this->authorizeWorkOrderAccess($workOrder);

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
        $this->authorizeWorkOrderAccess($subtask->workOrder);

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
        $this->authorizeWorkOrderAccess($workOrder);

        if ((int) $workOrder->job_status === 4 && (int) $validated['job_status'] !== 4) {
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

        AuditTrail::log('status_changed', $workOrder, 'Changed task status: ' . $workOrder->job_topic, [
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
            'job_priority' => 'required|integer|in:1,2,3',
        ]);

        $workOrder = $this->baseWorkOrderQuery()->findOrFail($job_id);
        $this->authorizeWorkOrderAccess($workOrder);

        abort_if((int) $workOrder->job_status === 4, 422, 'งานนี้ปิดแล้ว ไม่สามารถเปลี่ยนความสำคัญได้');

        $before = $workOrder->attributesToArray();
        $workOrder->update(['job_priority' => $validated['job_priority']]);

        AuditTrail::log('priority_changed', $workOrder, 'Changed task priority: ' . $workOrder->job_topic, [
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
        $this->authorizeWorkOrderAccess($workOrder);

        $before = $workOrder->attributesToArray();
        $workOrder->update(['job_due_at' => $validated['job_due_at']]);

        AuditTrail::log('due_date_changed', $workOrder, 'Changed task due date: ' . $workOrder->job_topic, [
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

    /**
     * ดึงรายการ (list) ของผู้ใช้ปัจจุบันเท่านั้น
     * หมายเหตุ: ตั้งใจไม่สร้าง "งานของฉัน" อัตโนมัติอีกต่อไป
     * เพื่อให้ผู้ใช้ลบรายการสุดท้ายได้จริง โดยไม่มีอะไรมาสร้างกลับ
     */
    private function taskListsForCurrentUser()
    {
        $user = Auth::user();

        return WorkOrderList::where('user_id', $user->id)
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
        $lists = WorkOrderList::where('user_id', $user->id)
            ->whereIn('id', $listIds)
            ->get()
            ->keyBy('id');
        $tasksPerList = WorkOrder::whereIn('work_order_list_id', $listIds)
            ->selectRaw('work_order_list_id, count(*) as total')
            ->groupBy('work_order_list_id')
            ->pluck('total', 'work_order_list_id');

        DB::transaction(function () use ($adminAssignments, $lists, $tasksPerList, $user) {
            foreach ($adminAssignments as $job) {
                $currentList = $lists->get($job->work_order_list_id);
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

    private function authorizeWorkOrderAccess(WorkOrder $workOrder): void
    {
        $user = Auth::user();

        $canUpdate = $user->role === 'admin'
            || $workOrder->user_id === $user->id
            || $workOrder->created_by === $user->id
            || $workOrder->leader_user_id === $user->id
            || $workOrder->collaborators()
                ->where('users.id', $user->id)
                ->where('work_order_collaborators.status', 'accepted')
                ->exists();

        abort_unless($canUpdate, 403);
    }

    private function authorizeWorkOrderDeletion(WorkOrder $workOrder): void
    {
        $user = Auth::user();

        $canDelete = $user->role === 'admin'
            || $workOrder->user_id === $user->id
            || $workOrder->created_by === $user->id
            || $workOrder->leader_user_id === $user->id;

        abort_unless($canDelete, 403);
    }
}
