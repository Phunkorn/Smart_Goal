<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use App\Services\NotificationService;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use App\Support\WorkOrderApprovalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectTaskRequestController extends Controller
{
    public function store(Request $request, WorkOrderList $list, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $this->authorize('requestTask', $list);
        abort_if($list->archived_at !== null, 422, 'โปรเจกต์นี้ถูกจัดเก็บแล้ว กรุณาเปิดโปรเจกต์อีกครั้งก่อนเพิ่มงาน');
        $request->session()->flash('project_task_request_list_id', $list->id);

        $validated = $request->validateWithBag('projectTaskRequest', [
            'request_type' => ['required', Rule::in(['task', 'subtask'])],
            'parent_job_id' => [
                'exclude_unless:request_type,subtask',
                'nullable',
                'required_if:request_type,subtask',
                'integer',
                Rule::exists('work_orders', 'job_id')->where(fn ($query) => $query
                    ->where('work_order_list_id', $list->id)
                    ->whereNull('parent_job_id')
                    ->whereNull('deleted_at')
                    ->where('job_status', '!=', 4)),
            ],
            'job_topic' => ['required', 'string', 'max:255'],
            'job_priority' => ['required', 'integer', 'in:2,3,4,5'],
            'job_start_at' => ['required', 'date'],
            'job_due_at' => ['required', 'date', 'after_or_equal:job_start_at'],
        ]);

        $actor = $request->user();
        $topic = trim($validated['job_topic']);
        $taskRequest = DB::transaction(function () use ($list, $actor, $topic, $validated, $notifications): WorkOrderListTaskRequest {
            $lockedList = WorkOrderList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::forUser($actor)->authorize('requestTask', $lockedList);
            abort_if($lockedList->archived_at !== null, 422, 'โปรเจกต์นี้ถูกจัดเก็บแล้ว กรุณาเปิดโปรเจกต์อีกครั้งก่อนเพิ่มงาน');
            $pendingCount = $lockedList->taskRequests()
                ->where('requester_id', $actor->id)
                ->where('status', 'pending')
                ->count();

            if ($pendingCount >= WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT) {
                throw ValidationException::withMessages([
                    'job_topic' => 'คุณมีคำขอที่รอพิจารณาในโปรเจกต์นี้ครบ '.WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT.' รายการแล้ว',
                ])->errorBag('projectTaskRequest');
            }

            $alreadyPending = $lockedList->taskRequests()
                ->where('requester_id', $actor->id)
                ->where('status', 'pending')
                ->where('parent_job_id', $validated['request_type'] === 'subtask' ? $validated['parent_job_id'] : null)
                ->where('job_topic', $topic)
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'job_topic' => 'มีคำขอชื่อนี้ที่กำลังรอการพิจารณาอยู่แล้ว',
                ])->errorBag('projectTaskRequest');
            }

            $taskRequest = $lockedList->taskRequests()->create([
                'requester_id' => $actor->id,
                'parent_job_id' => $validated['request_type'] === 'subtask' ? $validated['parent_job_id'] : null,
                'status' => 'pending',
                'job_topic' => $topic,
                'job_priority' => $validated['job_priority'],
                'job_start_at' => TodayWorkspace::parseBusinessInput($validated['job_start_at'], TodayWorkspace::DEFAULT_START_TIME),
                'job_due_at' => TodayWorkspace::parseBusinessInput($validated['job_due_at'], TodayWorkspace::DEFAULT_DUE_TIME),
            ]);

            // บอกให้ชัดตั้งแต่ข้อความแจ้งเตือนว่าเป็นงานย่อยของงานไหน ผู้พิจารณาจะได้ไม่ต้องเดาจากชื่อ
            $requestedItem = $taskRequest->parent_job_id
                ? 'งานย่อย “'.$taskRequest->job_topic.'” ภายใต้ “'.($taskRequest->parentTask?->job_topic ?? '-').'”'
                : 'งาน “'.$taskRequest->job_topic.'”';

            $notifications->notifyDetached(
                [$lockedList->user_id],
                'project_task_request_submitted',
                'มีคำขอเพิ่มงานในโปรเจกต์',
                $actor->name.' ขอเพิ่ม'.$requestedItem.' ใน '.$lockedList->name,
                $actor,
                ['task_request_id' => $taskRequest->id],
                ['work_order_list_id' => $lockedList->id]
            );

            return $taskRequest;
        });

        return $this->respond($request, 'ส่งคำขอเพิ่มงานแล้ว', 201, ['task_request_id' => $taskRequest->id]);
    }

    public function approve(Request $request, WorkOrderListTaskRequest $taskRequest, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $taskRequest->loadMissing('project');
        $this->authorize('reviewTaskRequests', $taskRequest->project);

        $outcome = DB::transaction(function () use ($request, $taskRequest, $notifications): array {
            $locked = WorkOrderListTaskRequest::query()
                ->with(['project', 'requester.department'])
                ->lockForUpdate()
                ->findOrFail($taskRequest->id);

            if ($locked->status !== 'pending') {
                return ['error' => 'คำขอนี้ถูกพิจารณาโดยผู้ใช้อื่นแล้ว', 'status' => 409];
            }

            Gate::forUser($request->user())->authorize('reviewTaskRequests', $locked->project);
            if (! $locked->requester?->is_active) {
                return ['error' => 'ผู้ขอไม่พร้อมรับมอบหมายงาน จึงยังอนุมัติคำขอนี้ไม่ได้', 'status' => 422];
            }

            if (! Gate::forUser($locked->requester)->allows('requestTask', $locked->project)) {
                return ['error' => 'ผู้ขอไม่ได้เป็นผู้ร่วมงานที่ได้รับการยอมรับในโปรเจกต์นี้แล้ว', 'status' => 422];
            }

            $parentTask = null;
            $parentSortOrder = 0;
            if ($locked->parent_job_id !== null) {
                $parentTask = WorkOrder::query()
                    ->whereKey($locked->parent_job_id)
                    ->where('work_order_list_id', $locked->work_order_list_id)
                    ->whereNull('parent_job_id')
                    ->where('job_status', '!=', 4)
                    ->lockForUpdate()
                    ->first();

                if (! $parentTask) {
                    return ['error' => 'งานหลักที่เลือกไม่พร้อมรับงานย่อยแล้ว กรุณาส่งคำขอใหม่', 'status' => 422];
                }

                $parentSortOrder = ((int) WorkOrder::query()
                    ->where('parent_job_id', $parentTask->job_id)
                    ->max('parent_sort_order')) + 1;
            }

            $owner = $request->user()->loadMissing('department');
            $approval = WorkOrderApprovalResolver::resolve($owner, $owner);

            $workOrder = WorkOrder::create([
                'user_id' => $owner->id,
                'created_by' => $owner->id,
                'assigned_by' => $owner->id,
                'leader_user_id' => $approval['leader_user_id'],
                'department_id' => $owner->department_id,
                'work_order_list_id' => $locked->work_order_list_id,
                'parent_job_id' => $parentTask?->job_id,
                'parent_sort_order' => $parentSortOrder,
                'job_topic' => $locked->job_topic,
                'job_details' => $locked->job_details,
                'job_priority' => $locked->job_priority,
                'job_status' => 2,
                'approval_status' => $approval['approval_status'],
                'approved_by' => $approval['approved_by'],
                'approved_at' => $approval['approved_at'],
                'job_start_at' => $locked->job_start_at,
                'job_due_at' => $locked->job_due_at,
            ]);

            $collaboratorStatus = $workOrder->approval_status === 'approved' ? 'accepted' : 'pending';
            $workOrder->collaborators()->attach($locked->requester_id, [
                'added_by' => $owner->id,
                'decided_by' => $collaboratorStatus === 'accepted' ? $owner->id : null,
                'status' => $collaboratorStatus,
                'responded_at' => $collaboratorStatus === 'accepted' ? now() : null,
            ]);

            $locked->update([
                'status' => 'approved',
                'decided_by' => $owner->id,
                'decided_at' => now(),
                'decision_reason' => null,
                'work_order_id' => $workOrder->job_id,
            ]);
            $notifications->resolveProjectTaskRequest($locked);

            AuditTrail::log('project_task_request_approved', $workOrder, 'อนุมัติคำขอเพิ่มงาน: '.$workOrder->job_topic, [
                'task_request_id' => $locked->id,
                'requester_id' => $locked->requester_id,
                'approval_status' => $approval['approval_status'],
            ]);

            if ($workOrder->approval_status === 'approved') {
                $notifications->notify(
                    [$locked->requester_id],
                    'project_task_request_approved',
                    'คำขอเพิ่มงานได้รับการอนุมัติ',
                    'งาน “'.$locked->job_topic.'” ถูกสร้างในโปรเจกต์แล้ว',
                    $workOrder,
                    $owner
                );
            } else {
                // The project owner approved the request, but the resulting cross-department
                // assignment is still private until an admin approves it.
                $notifications->notifyDetached(
                    [$locked->requester_id],
                    'project_task_request_approved',
                    'คำขอเพิ่มงานได้รับการอนุมัติ',
                    'งาน “'.$locked->job_topic.'” ถูกสร้างแล้วและกำลังรอผู้ดูแลระบบอนุมัติการมอบหมาย',
                    $owner,
                    ['task_request_id' => $locked->id],
                    [
                        'work_order_id' => $workOrder->job_id,
                        'work_order_list_id' => $workOrder->work_order_list_id,
                    ]
                );

                $workOrder->loadMissing(['user', 'collaborators']);
                $notifications->notifyAssignmentCreated(
                    $workOrder,
                    $owner,
                    $locked->requester,
                    false
                );
            }

            return ['work_order' => $workOrder];
        });

        if (isset($outcome['error'])) {
            return $this->respondError($request, $outcome['error'], $outcome['status']);
        }

        /** @var WorkOrder $workOrder */
        $workOrder = $outcome['work_order'];

        return $this->respond($request, 'อนุมัติและสร้างงานแล้ว', 200, ['job_id' => $workOrder->job_id]);
    }

    public function reject(Request $request, WorkOrderListTaskRequest $taskRequest, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $taskRequest->loadMissing('project');
        $this->authorize('reviewTaskRequests', $taskRequest->project);
        $request->session()->flash('project_task_request_decision_id', $taskRequest->id);

        $validated = $request->validateWithBag('projectTaskRequestDecision', [
            'decision_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $outcome = DB::transaction(function () use ($request, $taskRequest, $validated, $notifications): array {
            $locked = WorkOrderListTaskRequest::query()->with('project')->lockForUpdate()->findOrFail($taskRequest->id);
            if ($locked->status !== 'pending') {
                return ['error' => 'คำขอนี้ถูกพิจารณาโดยผู้ใช้อื่นแล้ว', 'status' => 409];
            }

            Gate::forUser($request->user())->authorize('reviewTaskRequests', $locked->project);

            $locked->update([
                'status' => 'rejected',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'decision_reason' => $validated['decision_reason'] ?? null,
            ]);
            $notifications->resolveProjectTaskRequest($locked);

            $notifications->notifyDetached(
                [$locked->requester_id],
                'project_task_request_rejected',
                'คำขอเพิ่มงานไม่ได้รับการอนุมัติ',
                'คำขอ “'.$locked->job_topic.'” ถูกปฏิเสธ'.($locked->decision_reason ? ': '.$locked->decision_reason : ''),
                $request->user(),
                ['task_request_id' => $locked->id],
                ['work_order_list_id' => $locked->work_order_list_id]
            );

            return ['rejected' => true];
        });

        if (isset($outcome['error'])) {
            return $this->respondError($request, $outcome['error'], $outcome['status']);
        }

        return $this->respond($request, 'ปฏิเสธคำขอแล้ว');
    }

    private function respond(Request $request, string $message, int $status = 200, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message] + $data, $status);
        }

        return back()->with('project_task_request_success', $message);
    }

    private function respondError(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], $status);
        }

        return back()->with('project_task_request_error', $message);
    }
}
