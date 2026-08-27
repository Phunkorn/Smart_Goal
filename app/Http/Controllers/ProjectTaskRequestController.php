<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use App\Services\NotificationService;
use App\Support\AuditTrail;
use App\Support\WorkOrderApprovalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectTaskRequestController extends Controller
{
    public function store(Request $request, WorkOrderList $list, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $this->authorize('requestTask', $list);
        $request->session()->flash('project_task_request_list_id', $list->id);

        $validated = $request->validateWithBag('projectTaskRequest', [
            'job_topic' => ['required', 'string', 'max:255'],
            'request_details' => ['nullable', 'string', 'max:5000'],
            'job_priority' => ['required', 'integer', 'in:1,2,3,4,5'],
            'job_start_at' => ['required', 'date'],
            'job_due_at' => ['required', 'date', 'after_or_equal:job_start_at'],
        ]);

        $actor = $request->user();
        $topic = trim($validated['job_topic']);
        $taskRequest = DB::transaction(function () use ($list, $actor, $topic, $validated, $notifications): WorkOrderListTaskRequest {
            $lockedList = WorkOrderList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::forUser($actor)->authorize('requestTask', $lockedList);
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
                ->where('job_topic', $topic)
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'job_topic' => 'มีคำขอชื่อนี้ที่กำลังรอการพิจารณาอยู่แล้ว',
                ])->errorBag('projectTaskRequest');
            }

            $taskRequest = $lockedList->taskRequests()->create([
                'requester_id' => $actor->id,
                'status' => 'pending',
                'job_topic' => $topic,
                'job_details' => $validated['request_details'] ?? null,
                'job_priority' => $validated['job_priority'],
                'job_start_at' => $validated['job_start_at'],
                'job_due_at' => $validated['job_due_at'],
            ]);

            $notifications->notifyDetached(
                [$lockedList->user_id],
                'project_task_request_submitted',
                'มีคำขอเพิ่มงานในโปรเจกต์',
                $actor->name.' ขอเพิ่มงาน “'.$taskRequest->job_topic.'” ใน '.$lockedList->name,
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

            $owner = $request->user()->loadMissing('department');
            $approval = WorkOrderApprovalResolver::resolve($owner, $locked->requester);

            $workOrder = WorkOrder::create([
                'user_id' => $locked->requester_id,
                'created_by' => $owner->id,
                'assigned_by' => $owner->id,
                'leader_user_id' => $approval['leader_user_id'],
                'department_id' => $locked->requester->department_id ?? $owner->department_id,
                'work_order_list_id' => $locked->work_order_list_id,
                'job_topic' => $locked->job_topic,
                'job_details' => $locked->job_details,
                'job_priority' => $locked->job_priority,
                'job_status' => 1,
                'approval_status' => $approval['approval_status'],
                'approved_by' => $approval['approved_by'],
                'approved_at' => $approval['approved_at'],
                'job_progress' => 0,
                'job_start_at' => $locked->job_start_at,
                'job_due_at' => $locked->job_due_at,
            ]);

            $locked->update([
                'status' => 'approved',
                'decided_by' => $owner->id,
                'decided_at' => now(),
                'decision_reason' => null,
                'work_order_id' => $workOrder->job_id,
            ]);

            AuditTrail::log('project_task_request_approved', $workOrder, 'อนุมัติคำขอเพิ่มงาน: '.$workOrder->job_topic, [
                'task_request_id' => $locked->id,
                'requester_id' => $locked->requester_id,
                'approval_status' => $approval['approval_status'],
            ]);

            $notifications->notify(
                [$locked->requester_id],
                'project_task_request_approved',
                'คำขอเพิ่มงานได้รับการอนุมัติ',
                'งาน “'.$locked->job_topic.'” ถูกสร้างในโปรเจกต์แล้ว',
                $workOrder,
                $owner
            );

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
