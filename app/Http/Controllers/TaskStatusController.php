<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkOrder;
use App\Services\CollaboratorInvitationService;
use App\Services\NotificationService;
use App\Services\TaskStatusTransitionService;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaskStatusController extends Controller
{
    use RespondsWithTaskResult;
    use ValidatesAttachments;

    public function updateStatus(Request $request, $id, TaskStatusTransitionService $transitions)
    {
        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $user = Auth::user();

        $ability = (int) $job->job_status === 4
            ? 'reopen'
            : ($user->role === 'admin' ? 'overrideStatus' : 'work');
        $this->authorize($ability, $job);

        $validated = $request->validate([
            'job_status' => ['required', 'integer', 'in:2,3,4,5,6'],
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
        ]);

        if ((int) $job->job_status === 4 && $request->hasFile('completion_attachments')) {
            $this->storeFiles($request, $job, 'completion_attachments');
        }

        $message = (int) $validated['job_status'] === 4 ? 'ปิดงานสำเร็จ' : 'ปรับสถานะงานสำเร็จ';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'job_id' => $job->job_id,
                'job_status' => (int) $job->job_status,
                'transitions' => $transitions->capabilities($job, $user),
            ]);
        }

        return back()->with('success', $message);
    }

    public function updateApproval(Request $request, $id, CollaboratorInvitationService $invitations)
    {
        $this->authorize('approve', WorkOrder::class);

        $validated = $request->validate([
            'approval_status' => ['required', 'in:approved,rejected'],
        ]);

        $result = DB::transaction(function () use ($id, $validated, $invitations): array {
            $job = WorkOrder::query()
                ->with(['user', 'creator', 'leader', 'collaborators'])
                ->lockForUpdate()
                ->findOrFail($id);

            $this->authorize('approve', $job);

            if ($job->approval_status !== 'pending') {
                return ['changed' => false, 'job' => $job];
            }

            $before = $job->attributesToArray();
            $job->forceFill([
                'approval_status' => $validated['approval_status'],
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ])->save();

            $job->refresh();
            if ($validated['approval_status'] === 'approved') {
                $invitations->activateAfterAssignmentApproval($job, Auth::user());
            } else {
                $invitations->rejectPendingAfterAssignmentRejection($job, Auth::user());
            }
            AuditTrail::log('approval_updated', $job, 'Admin อัปเดตการอนุมัติงาน: '.$job->job_topic, [
                'before' => $before,
                'after' => $job->attributesToArray(),
            ]);
            app(NotificationService::class)->notifyAssignmentDecision(
                $job,
                Auth::user(),
                $validated['approval_status']
            );

            return ['changed' => true, 'job' => $job];
        });

        if (! $result['changed']) {
            return $this->jsonOrBack(
                $request,
                false,
                'คำขอนี้ถูกอนุมัติหรือปฏิเสธไปแล้ว กรุณารีเฟรชรายการ',
                409
            );
        }

        $title = $validated['approval_status'] === 'approved'
            ? 'อนุมัติการมอบหมายงานแล้ว'
            : 'ปฏิเสธการมอบหมายงานแล้ว';

        return $this->jsonOrBack($request, true, $title);
    }
}
