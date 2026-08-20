<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Services\TaskStatusTransitionService;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\TodayWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskStatusController extends Controller
{
    use RespondsWithTaskResult;
    use ValidatesAttachments;

    public function updateStatus(Request $request, $id, TaskStatusTransitionService $transitions)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $this->authorize('view', $job);

        $validated = $request->validate([
            'job_status' => ['required', 'integer', 'in:1,2,3,4,5,6'],
            'job_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'completion_attachments' => ['nullable', 'array', 'max:5'],
            'completion_attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
            'action' => ['nullable', 'string', 'in:reopen'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($request->hasFile('completion_attachments') && $job->images()->count() + count($request->file('completion_attachments', [])) > 5) {
            return $this->jsonOrBack($request, false, 'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน', 422);
        }

        $this->assertAllowedAttachments($request, 'completion_attachments');

        $job = $transitions->transition($job, $user, (int) $validated['job_status'], [
            'action' => $validated['action'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'job_progress' => $validated['job_progress'] ?? $job->job_progress,
        ]);

        if ((int) $job->job_status === 4 && $request->hasFile('completion_attachments')) {
            $this->storeFiles($request, $job, 'completion_attachments');
        }

        $message = (int) $validated['job_status'] === 4 ? 'ปิดงานสำเร็จ' : 'ปรับสถานะงานสำเร็จ';

        return $this->jsonOrBack($request, true, $message);
    }

    public function updateApproval(Request $request, $id)
    {
        $this->authorize('approve', WorkOrder::class);

        $validated = $request->validate([
            'approval_status' => ['required', 'in:approved,rejected'],
        ]);

        $job = WorkOrder::with(['user', 'creator', 'leader', 'collaborators'])->findOrFail($id);
        TodayWorkspace::normalizeLateForTransition($job);
        $before = $job->attributesToArray();

        $job->approval_status = $validated['approval_status'];
        $job->approved_by = Auth::id();
        $job->approved_at = now();

        if ($validated['approval_status'] === 'approved' && (int) $job->job_status === 1) {
            $job->job_status = 2;
        }

        $job->save();

        $job->refresh();
        AuditTrail::log('approval_updated', $job, 'Admin อัปเดตการอนุมัติงาน: '.$job->job_topic, [
            'before' => $before,
            'after' => $job->attributesToArray(),
        ]);

        if ($validated['approval_status'] === 'approved') {
            $title = 'งานได้รับอนุมัติแล้ว';
            $message = 'ผู้ดูแลระบบอนุมัติงาน "'.$job->job_topic.'" แล้ว';
        } else {
            $title = 'งานไม่ผ่านการอนุมัติ';
            $message = 'ผู้ดูแลระบบปฏิเสธคำขอเปิดงาน "'.$job->job_topic.'"';
        }

        app(NotificationService::class)->notifyTaskMembers($job, 'admin_approval', $title, $message, Auth::user());

        return back()->with('success', $title);
    }
}
