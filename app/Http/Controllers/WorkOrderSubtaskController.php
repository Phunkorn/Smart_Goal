<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderSubtask;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $detail = DB::transaction(function () use ($request, $validated, $workOrder): WorkOrderSubtask {
            $lastPosition = (int) $workOrder->subtasks()
                ->reorder()
                ->lockForUpdate()
                ->max('sort_order');

            return $workOrder->subtasks()->create([
                'created_by' => $request->user()->id,
                'title' => $validated['title'],
                'sort_order' => $workOrder->subtasks()->exists() ? $lastPosition + 1 : 0,
            ]);
        });

        AuditTrail::log('created', $detail, 'เพิ่มรายละเอียดงาน: '.$detail->title, [
            'work_order_id' => $workOrder->job_id,
            'after' => $detail->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'เพิ่มรายละเอียดงานแล้ว',
            'detail' => $this->present($detail),
        ], 201);
    }

    public function update(Request $request, WorkOrderSubtask $detail): JsonResponse
    {
        $workOrder = $detail->workOrder()->with('collaborators')->firstOrFail();
        $this->authorize('work', $workOrder);
        $request->merge(['title' => trim((string) $request->input('title'))]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);
        $before = $detail->attributesToArray();

        $detail->update(['title' => $validated['title']]);

        AuditTrail::log('updated', $detail, 'แก้ไขรายละเอียดงาน: '.$detail->title, [
            'work_order_id' => $workOrder->job_id,
            'before' => $before,
            'after' => $detail->fresh()->attributesToArray(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'แก้ไขรายละเอียดงานแล้ว',
            'detail' => $this->present($detail->fresh()),
        ]);
    }

    public function destroy(Request $request, WorkOrderSubtask $detail): JsonResponse
    {
        $workOrder = $detail->workOrder()->with('collaborators')->firstOrFail();
        $this->authorize('work', $workOrder);

        $before = $detail->attributesToArray();
        $detail->delete();
        $this->normalizePositions($workOrder);

        AuditTrail::log('deleted', $workOrder, 'ลบรายละเอียดงาน: '.$before['title'], [
            'detail' => $before,
            'deleted_by' => $request->user()->id,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'ลบรายละเอียดงานแล้ว',
        ]);
    }

    public function move(Request $request, WorkOrderSubtask $detail): JsonResponse
    {
        $validated = $request->validate([
            'target_work_order_id' => ['required', 'integer', 'exists:work_orders,job_id'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $source = $detail->workOrder()->with('collaborators')->firstOrFail();
        $target = WorkOrder::query()
            ->with(['collaborators', 'taskList'])
            ->findOrFail((int) $validated['target_work_order_id']);

        $this->authorize('work', $source);
        $this->authorize('work', $target);

        $sourceId = (int) $source->job_id;
        $targetId = (int) $target->job_id;
        $before = $detail->attributesToArray();

        DB::transaction(function () use ($detail, $source, $sourceId, $targetId, $validated): void {
            $lockedDetail = WorkOrderSubtask::query()->lockForUpdate()->findOrFail($detail->id);
            $targetIds = WorkOrderSubtask::query()
                ->where('work_order_id', $targetId)
                ->whereKeyNot($lockedDetail->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->values();

            $position = min((int) ($validated['position'] ?? $targetIds->count()), $targetIds->count());
            $targetIds->splice($position, 0, [$lockedDetail->id]);
            $lockedDetail->update(['work_order_id' => $targetId]);

            foreach ($targetIds as $index => $id) {
                WorkOrderSubtask::query()->whereKey($id)->update(['sort_order' => $index]);
            }

            if ($sourceId !== $targetId) {
                $this->normalizePositions($source);
            }
        });

        $detail->refresh();
        AuditTrail::log('moved', $detail, 'ย้ายรายละเอียดงาน: '.$detail->title, [
            'before' => $before,
            'after' => $detail->attributesToArray(),
            'source_work_order_id' => $sourceId,
            'target_work_order_id' => $targetId,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'ย้ายรายละเอียดงานแล้ว',
            'detail' => $this->present($detail),
            'target' => [
                'work_order_id' => $targetId,
                'project_id' => $target->work_order_list_id,
                'project_name' => $target->taskList?->name ?? 'งานทั่วไป',
                'task_name' => $target->job_topic,
            ],
        ]);
    }

    private function normalizePositions(WorkOrder $workOrder): void
    {
        $workOrder->subtasks()
            ->reorder()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id, int $index) => WorkOrderSubtask::query()
                ->whereKey($id)
                ->update(['sort_order' => $index]));
    }

    /** @return array<string, mixed> */
    private function present(WorkOrderSubtask $detail): array
    {
        return [
            'id' => $detail->id,
            'work_order_id' => (int) $detail->work_order_id,
            'title' => $detail->title,
            'sort_order' => (int) $detail->sort_order,
            'update_url' => route('mytasks.details.update', $detail),
            'delete_url' => route('mytasks.details.destroy', $detail),
            'move_url' => route('mytasks.details.move', $detail),
        ];
    }
}
