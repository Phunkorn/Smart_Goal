<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CollaboratorInvitationService;
use App\Services\NotificationService;
use App\Services\TaskStatusTransitionService;
use App\Support\ApprovalPresenter;
use App\Support\AttachmentPolicy;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\TaskAttachmentPresenter;
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
            'completion_attachments' => ['nullable', 'array', 'max:'.AttachmentPolicy::MAX_FILES],
            'completion_attachments.*' => ['file', 'mimes:'.AttachmentPolicy::mimesRule(), 'max:'.AttachmentPolicy::MAX_KILOBYTES],
            'action' => ['nullable', 'string', 'in:reopen'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($request->hasFile('completion_attachments') && $job->images()->count() + count($request->file('completion_attachments', [])) > AttachmentPolicy::MAX_FILES) {
            return $this->jsonOrBack($request, false, AttachmentPolicy::tooManyMessage($job->images()->count()), 422);
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
            return response()->json(array_filter([
                'ok' => true,
                'message' => $message,
                'job_id' => $job->job_id,
                'job_status' => (int) $job->job_status,
                'transitions' => $transitions->capabilities($job, $user),
                // สิทธิ์ของ Workspace เปลี่ยนพร้อมสถานะงาน จึงต้องส่งค่าชุดใหม่กลับไป
                // ไม่เช่นนั้นงานที่เพิ่งเปิดอีกครั้งจะยังแนบไฟล์/คอมเมนต์ไม่ได้จนกว่าจะ reload
                'interactions' => $this->interactionPayload($job, $user),
                // ปิดหรือเปิดงานย่อยทำให้ "ด่านงานย่อย" ของงานแม่เปลี่ยน สถานะใหม่ของงานแม่
                // จึงต้องมาจาก server ด้วย ไม่ใช่ให้ฝั่งหน้าจอนับแถวงานย่อยใน DOM เอง
                // ซึ่งเป็นแหล่งความจริงที่สองและนับผิดได้เมื่อแถวถูกตัดด้วยสิทธิ์
                'parent' => $this->parentPayload($job, $user, $transitions),
            ], static fn ($value) => $value !== null));
        }

        return back()->with('success', $message);
    }

    /**
     * สถานะและสิทธิ์ชุดใหม่ของงานแม่ — null เมื่องานที่เปลี่ยนไม่ใช่งานย่อย
     *
     * @return array<string, mixed>|null
     */
    private function parentPayload(WorkOrder $job, User $user, TaskStatusTransitionService $transitions): ?array
    {
        if ($job->parent_job_id === null) {
            return null;
        }

        $parent = WorkOrder::with('collaborators')->find($job->parent_job_id);

        if ($parent === null || ! $user->can('view', $parent)) {
            return null;
        }

        return [
            'job_id' => (int) $parent->job_id,
            'job_status' => (int) $parent->job_status,
            'transitions' => $transitions->capabilities($parent, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function interactionPayload(WorkOrder $job, User $user): array
    {
        $canUpload = $user->can('work', $job);
        $canComment = $user->can('comment', $job);

        return [
            'is_closed' => (int) $job->job_status === 4,
            'can_upload' => $canUpload,
            'upload_url' => $canUpload ? route('tasks.attachments.store', $job->job_id) : null,
            'files' => TaskAttachmentPresenter::files($job->loadMissing('images'), $canUpload),
            'can_comment' => $canComment,
            'comment_url' => $canComment ? route('tasks.comments.store', $job) : null,
        ];
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
            $approver = Auth::user();
            $approver?->loadMissing('department');
            AuditTrail::log('approval_updated', $job, ApprovalPresenter::approverLabel($approver).' '.ApprovalPresenter::decisionVerb($validated['approval_status']).'การมอบหมายงาน: '.$job->job_topic, [
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
