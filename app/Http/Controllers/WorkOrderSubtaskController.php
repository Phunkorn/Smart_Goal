<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * งานย่อย (เดิมเรียก "รายละเอียดงาน") ของงานหนึ่งใบ
 *
 * งานย่อยคือ WorkOrder ที่มี parent_job_id ไม่ใช่แถวข้อความในตารางแยกอีกต่อไป
 * มันจึงได้สถานะ ความสำคัญ วันที่เริ่ม กำหนดส่ง ผู้รับผิดชอบ ผู้ร่วมงาน ไฟล์แนบ
 * และคอมเมนต์จากกลไกของงานปกติทั้งหมด และแก้ไขได้ในโมดัลรายละเอียดงานตัวเดียวกัน
 * คอนโทรลเลอร์นี้จึงดูแลเฉพาะการสร้าง เปลี่ยนชื่อ ย้ายงานแม่ และลบเท่านั้น
 */
class WorkOrderSubtaskController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $workOrder->loadMissing('collaborators');
        $this->authorize('work', $workOrder);
        $request->merge(['title' => trim((string) $request->input('title'))]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $child = DB::transaction(function () use ($request, $validated, $workOrder): WorkOrder {
            $siblings = WorkOrder::query()
                ->where('parent_job_id', $workOrder->job_id)
                ->lockForUpdate();

            $lastPosition = (int) $siblings->max('parent_sort_order');
            $hasSiblings = WorkOrder::query()->where('parent_job_id', $workOrder->job_id)->exists();

            return WorkOrder::create([
                // งานย่อยตั้งต้นด้วยบริบทของงานแม่ ผู้ใช้ค่อยเปลี่ยนผู้รับผิดชอบหรือวันที่
                // ในโมดัลรายละเอียดงานได้ทีหลัง
                'user_id' => $workOrder->user_id,
                'created_by' => $request->user()->id,
                'assigned_by' => $request->user()->id,
                'leader_user_id' => $workOrder->leader_user_id,
                'department_id' => $workOrder->department_id,
                'work_order_list_id' => $workOrder->work_order_list_id,
                'parent_job_id' => $workOrder->job_id,
                'parent_sort_order' => $hasSiblings ? $lastPosition + 1 : 0,
                'job_topic' => $validated['title'],
                'job_priority' => $workOrder->job_priority,
                'job_status' => 2,
                'approval_status' => $workOrder->approval_status,
                'approved_by' => $workOrder->approved_by,
                'approved_at' => $workOrder->approved_at,
                // work_orders.job_start_at / job_due_at เป็น NOT NULL ในฐานข้อมูลจริง
                // งานย่อยจึงต้องมีช่วงเวลาเสมอ ตั้งต้นจากงานแม่แล้วผู้ใช้ค่อยแก้ในโมดัล
                'job_start_at' => $workOrder->job_start_at ?? now(),
                'job_due_at' => $workOrder->job_due_at ?? $workOrder->job_start_at ?? now(),
            ]);
        });

        AuditTrail::log('created', $child, 'เพิ่มงานย่อย: '.$child->job_topic, [
            'work_order_id' => $workOrder->job_id,
            'after' => $child->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มงานย่อยแล้ว',
            'detail' => $this->present($child),
        ], 201);
    }

    public function update(Request $request, WorkOrder $detail): JsonResponse
    {
        $parent = $this->parentOf($detail);
        $this->authorize('work', $parent);
        $request->merge(['title' => trim((string) $request->input('title'))]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);
        $before = $detail->attributesToArray();

        $detail->update(['job_topic' => $validated['title']]);

        AuditTrail::log('updated', $detail, 'แก้ไขชื่องานย่อย: '.$detail->job_topic, [
            'work_order_id' => $parent->job_id,
            'before' => $before,
            'after' => $detail->fresh()->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'แก้ไขงานย่อยแล้ว',
            'detail' => $this->present($detail->fresh()),
        ]);
    }

    public function destroy(Request $request, WorkOrder $detail): JsonResponse
    {
        $parent = $this->parentOf($detail);
        $this->authorize('work', $parent);

        $before = $detail->attributesToArray();

        AuditTrail::trash($detail, $request->user(), [
            'work_order' => $before,
        ]);
        AuditTrail::log('deleted', $detail, 'ลบงานย่อย: '.$detail->job_topic, [
            'before' => $before,
            'deleted_by' => $request->user()->id,
        ]);

        // งานย่อยเป็น WorkOrder จึงเป็น soft delete และกู้คืนได้ด้วยกลไกถังขยะเดิม
        $detail->delete();
        $this->normalizePositions($parent);

        return response()->json([
            'ok' => true,
            'message' => 'ลบงานย่อยแล้ว',
        ]);
    }

    public function move(Request $request, WorkOrder $detail): JsonResponse
    {
        $validated = $request->validate([
            'target_work_order_id' => ['required', 'integer', 'exists:work_orders,job_id'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $source = $this->parentOf($detail);
        $target = WorkOrder::query()
            ->with(['collaborators', 'taskList'])
            ->findOrFail((int) $validated['target_work_order_id']);

        $this->authorize('work', $source);
        $this->authorize('work', $target);

        // งานย่อยเป็นงานจริง การให้มันไปอยู่ใต้ตัวเองหรือใต้งานย่อยของตัวเอง
        // จะสร้างวงวนที่ทำให้หน้าบอร์ดวาดซ้ำไม่รู้จบ
        abort_if($this->wouldCycle($detail, $target), 422, 'ย้ายงานย่อยไปไว้ใต้ตัวเองหรืองานย่อยของตัวเองไม่ได้');

        $sourceId = (int) $source->job_id;
        $targetId = (int) $target->job_id;
        $before = $detail->attributesToArray();

        DB::transaction(function () use ($detail, $source, $sourceId, $targetId, $validated): void {
            $lockedDetail = WorkOrder::query()->lockForUpdate()->findOrFail($detail->job_id);
            $targetIds = WorkOrder::query()
                ->where('parent_job_id', $targetId)
                ->where('job_id', '!=', $lockedDetail->job_id)
                ->orderBy('parent_sort_order')
                ->orderBy('job_id')
                ->lockForUpdate()
                ->pluck('job_id')
                ->values();

            $position = min((int) ($validated['position'] ?? $targetIds->count()), $targetIds->count());
            $targetIds->splice($position, 0, [$lockedDetail->job_id]);
            $lockedDetail->update(['parent_job_id' => $targetId]);

            foreach ($targetIds as $index => $id) {
                WorkOrder::query()->whereKey($id)->update(['parent_sort_order' => $index]);
            }

            if ($sourceId !== $targetId) {
                $this->normalizePositions($source);
            }
        });

        $detail->refresh();
        AuditTrail::log('moved', $detail, 'ย้ายงานย่อย: '.$detail->job_topic, [
            'before' => $before,
            'after' => $detail->attributesToArray(),
            'source_work_order_id' => $sourceId,
            'target_work_order_id' => $targetId,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'ย้ายงานย่อยแล้ว',
            'detail' => $this->present($detail),
            'target' => [
                'work_order_id' => $targetId,
                'project_id' => $target->work_order_list_id,
                'project_name' => $target->taskList?->name ?? 'งานทั่วไป',
                'task_name' => $target->job_topic,
            ],
        ]);
    }

    /** งานแม่ของงานย่อย — เรียกกับงานที่ไม่ใช่งานย่อยไม่ได้ */
    private function parentOf(WorkOrder $detail): WorkOrder
    {
        abort_if($detail->parent_job_id === null, 404);

        return WorkOrder::query()->with('collaborators')->findOrFail($detail->parent_job_id);
    }

    private function wouldCycle(WorkOrder $detail, WorkOrder $target): bool
    {
        $cursor = $target;
        $guard = 0;

        while ($cursor && $guard++ < 50) {
            if ((int) $cursor->job_id === (int) $detail->job_id) {
                return true;
            }

            $cursor = $cursor->parent_job_id
                ? WorkOrder::query()->find($cursor->parent_job_id)
                : null;
        }

        return false;
    }

    private function normalizePositions(WorkOrder $workOrder): void
    {
        WorkOrder::query()
            ->where('parent_job_id', $workOrder->job_id)
            ->orderBy('parent_sort_order')
            ->orderBy('job_id')
            ->pluck('job_id')
            ->each(fn (int $id, int $index) => WorkOrder::query()
                ->whereKey($id)
                ->update(['parent_sort_order' => $index]));
    }

    /** @return array<string, mixed> */
    private function present(WorkOrder $detail): array
    {
        $detail->loadMissing(['user', 'collaborators', 'images', 'updates']);

        return [
            'id' => $detail->job_id,
            'work_order_id' => (int) $detail->parent_job_id,
            'title' => $detail->job_topic,
            'sort_order' => (int) $detail->parent_sort_order,
            'status' => (int) $detail->job_status,
            'priority' => (int) $detail->job_priority,
            'start' => TodayWorkspace::calendarDate($detail->job_start_at),
            'due' => TodayWorkspace::calendarDate($detail->job_due_at),
            'assignee' => $detail->user?->name,
            'collaborator_count' => $detail->collaborators->count(),
            'attachment_count' => $detail->images->count(),
            'comment_count' => $detail->updates->where('is_comment', true)->count(),
            'update_url' => route('mytasks.details.update', $detail),
            'delete_url' => route('mytasks.details.destroy', $detail),
            'move_url' => route('mytasks.details.move', $detail),
        ];
    }
}
