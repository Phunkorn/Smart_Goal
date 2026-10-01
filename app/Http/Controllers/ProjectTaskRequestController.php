<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
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

class ProjectTaskRequestController extends Controller
{
    /**
     * เพิ่มงานหรืองานย่อยในโปรเจกต์ของคนอื่นทันที ไม่ต้องรอเจ้าของโปรเจกต์อนุมัติอีกต่อไป
     * (กติกาเปลี่ยนตามที่เจ้าของระบบกำหนด — ดูเหตุผลที่ WorkOrderListPolicy::requestTask())
     *
     * งานใหม่ยังคงให้เจ้าของโปรเจกต์เป็น user_id/leader เหมือนกับตอนที่ยังต้องอนุมัติ
     * (คงพฤติกรรมเดิมของ WorkOrderPolicy ซึ่งไม่มีเช็ค "เจ้าของโปรเจกต์" แยกต่างหาก จึงต้อง
     * พึ่งการเป็น user_id/created_by/leader_user_id ของทุกงานในโปรเจกต์ตัวเองเพื่อให้เห็น/จัดการ
     * งานนั้นได้) ส่วนผู้ขอเข้าร่วมเป็น collaborator สถานะ accepted ทันที ไม่ต้องรอใครตอบรับ
     */
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

        $workOrder = DB::transaction(function () use ($list, $actor, $topic, $validated, $notifications): WorkOrder {
            $lockedList = WorkOrderList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::forUser($actor)->authorize('requestTask', $lockedList);
            abort_if($lockedList->archived_at !== null, 422, 'โปรเจกต์นี้ถูกจัดเก็บแล้ว กรุณาเปิดโปรเจกต์อีกครั้งก่อนเพิ่มงาน');

            $owner = User::query()->with('department')->findOrFail($lockedList->user_id);

            $parentTask = null;
            $parentSortOrder = 0;
            if ($validated['request_type'] === 'subtask') {
                $parentTask = WorkOrder::query()
                    ->whereKey($validated['parent_job_id'])
                    ->where('work_order_list_id', $lockedList->id)
                    ->whereNull('parent_job_id')
                    ->where('job_status', '!=', 4)
                    ->lockForUpdate()
                    ->first();

                // เผื่อกรณีชนกัน: งานหลักถูกปิดไปในช่วงสั้น ๆ ระหว่างตรวจ validation กับ lock แถวจริง
                abort_if(! $parentTask, 422, 'งานหลักที่เลือกไม่พร้อมรับงานย่อยแล้ว กรุณาลองใหม่');

                $parentSortOrder = ((int) WorkOrder::query()
                    ->where('parent_job_id', $parentTask->job_id)
                    ->max('parent_sort_order')) + 1;
            }

            $approval = WorkOrderApprovalResolver::resolve($owner, $owner);

            $workOrder = WorkOrder::create([
                'user_id' => $owner->id,
                'created_by' => $owner->id,
                'assigned_by' => $owner->id,
                'leader_user_id' => $approval['leader_user_id'],
                'department_id' => $owner->department_id,
                'work_order_list_id' => $lockedList->id,
                'parent_job_id' => $parentTask?->job_id,
                'parent_sort_order' => $parentSortOrder,
                'job_topic' => $topic,
                'job_details' => null,
                'job_priority' => $validated['job_priority'],
                'job_status' => 2,
                'approval_status' => $approval['approval_status'],
                'approved_by' => $approval['approved_by'],
                'approved_at' => $approval['approved_at'],
                'job_start_at' => TodayWorkspace::parseBusinessInput($validated['job_start_at'], TodayWorkspace::DEFAULT_START_TIME),
                'job_due_at' => TodayWorkspace::parseBusinessInput($validated['job_due_at'], TodayWorkspace::DEFAULT_DUE_TIME),
            ]);

            $workOrder->collaborators()->attach($actor->id, [
                'added_by' => $owner->id,
                'decided_by' => $owner->id,
                'status' => 'accepted',
                'responded_at' => now(),
            ]);

            // บอกให้ชัดตั้งแต่ข้อความแจ้งเตือนว่าเป็นงานย่อยของงานไหน เจ้าของโปรเจกต์จะได้ไม่ต้องเดาจากชื่อ
            $addedItem = $parentTask
                ? 'งานย่อย “'.$topic.'” ภายใต้ “'.$parentTask->job_topic.'”'
                : 'งาน “'.$topic.'”';

            AuditTrail::log('project_task_added', $workOrder, $actor->name.' เพิ่ม'.$addedItem.' ใน '.$lockedList->name, [
                'requester_id' => $actor->id,
                'work_order_list_id' => $lockedList->id,
            ]);

            $notifications->notify(
                [$owner->id],
                'project_task_added',
                'มีงานใหม่ในโปรเจกต์',
                $actor->name.' เพิ่ม'.$addedItem.' ใน '.$lockedList->name,
                $workOrder,
                $actor
            );

            return $workOrder;
        });

        return $this->respond($request, 'เพิ่มงานแล้ว', 201, ['job_id' => $workOrder->job_id]);
    }

    private function respond(Request $request, string $message, int $status = 200, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message] + $data, $status);
        }

        return back()->with('project_task_request_success', $message);
    }
}
