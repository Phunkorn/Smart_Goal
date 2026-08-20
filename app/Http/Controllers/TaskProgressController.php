<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkOrder;
use App\Models\WorkOrderUpdate;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskProgressController extends Controller
{
    use RespondsWithTaskResult;

    public function updateProgress(Request $request, $id)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $this->authorize('update', $job);
        TodayWorkspace::normalizeLateForTransition($job);

        if ($job->approval_status !== 'approved') {
            return $this->jsonOrBack($request, false, 'งานนี้ยังไม่ได้รับอนุมัติ', 422);
        }

        if ((int) $job->job_status === 4 && $user?->role !== 'admin') {
            return $this->jsonOrBack($request, false, 'งานนี้ปิดแล้ว ไม่สามารถอัปเดตความคืบหน้าได้', 422);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        $before = $job->attributesToArray();
        $subtaskCount = $job->subtasks()->count();
        $completedSubtaskCount = $job->subtasks()->where('is_completed', true)->count();
        $canOverrideProgress = $user?->role === 'admin'
            && $subtaskCount === 0
            && (int) $job->job_status !== 4
            && array_key_exists('progress', $validated)
            && $validated['progress'] !== null;
        $progress = $canOverrideProgress
            ? (int) $validated['progress']
            : ($subtaskCount > 0
                ? (int) round(($completedSubtaskCount / $subtaskCount) * 100)
                : (int) $job->job_progress);

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
        AuditTrail::log('progress_updated', $job, 'เพิ่มความคิดเห็น/อัปเดตงาน: '.$job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
            'progress' => $progress,
            'note' => Str::limit($validated['note'], 200),
        ]);

        return $this->jsonOrBack($request, true, 'อัปเดตความคืบหน้าสำเร็จ');
    }
}
