<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use Illuminate\Support\Facades\DB;

class CollaboratorInvitationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Attach one eligible user using the collaborator approval contract shared
     * by task creation and the task workspace.
     */
    public function invite(WorkOrder $task, User $candidate, User $actor): ?string
    {
        if (! $candidate->is_active || $candidate->role !== 'user') {
            return null;
        }

        if (in_array((int) $candidate->id, array_filter([
            (int) $actor->id,
            (int) $task->user_id,
            (int) $task->created_by,
            (int) $task->leader_user_id,
        ]), true)) {
            return null;
        }

        if ($task->collaborators()->where('users.id', $candidate->id)->exists()) {
            return null;
        }

        $task->loadMissing('user.department');
        $candidate->loadMissing('department');
        $taskDepartmentId = $task->department_id ?: $task->user?->department_id;
        $sameDepartment = $taskDepartmentId
            && (int) $candidate->department_id === (int) $taskDepartmentId;
        $status = $task->approval_status === 'approved'
            && ($actor->role === 'admin' || $sameDepartment)
                ? 'accepted'
                : 'pending';

        $task->collaborators()->attach($candidate->id, [
            'added_by' => $actor->id,
            'decided_by' => $status === 'accepted' ? $actor->id : null,
            'status' => $status,
            'responded_at' => $status === 'accepted' ? now() : null,
        ]);
        $task->unsetRelation('collaborators');
        $task->load('collaborators');

        AuditTrail::log('collaborator_added', $task, 'เพิ่มผู้ร่วมงานในงาน: '.$task->job_topic, [
            'user_id' => $candidate->id,
            'status' => $status,
        ]);

        if ($status === 'accepted') {
            $this->notifications->notify(
                [$candidate->id],
                'collaborator_added',
                'ถูกเพิ่มเข้าร่วมงาน',
                ($actor->role === 'admin' ? 'ผู้ดูแลระบบ' : $actor->name).' เพิ่มคุณเข้าร่วมงาน “'.$task->job_topic.'”',
                $task,
                $actor
            );

            return $status;
        }

        if ($task->approval_status !== 'approved') {
            return $status;
        }

        $this->notifyApprovers($task, $candidate, $actor);

        return $status;
    }

    /**
     * Resolve collaborator invitations only after the main cross-department
     * assignment has been approved. The assignee pivot used by Project Task
     * Request is accepted by the main decision and never enters this queue.
     */
    public function activateAfterAssignmentApproval(WorkOrder $task, User $admin): void
    {
        $task->loadMissing(['user.department', 'collaborators.department']);
        $taskDepartmentId = $task->department_id ?: $task->user?->department_id;

        foreach ($task->collaborators->filter(fn (User $candidate) => $candidate->pivot?->status === 'pending') as $candidate) {
            if ((int) $candidate->id === (int) $task->user_id) {
                $task->collaborators()->updateExistingPivot($candidate->id, [
                    'status' => 'accepted',
                    'decided_by' => $admin->id,
                    'responded_at' => now(),
                ]);

                continue;
            }

            $sameDepartment = $taskDepartmentId
                && (int) $candidate->department_id === (int) $taskDepartmentId;
            $inviter = $candidate->pivot?->added_by ? User::find($candidate->pivot->added_by) : null;

            if ($sameDepartment || $inviter?->role === 'admin') {
                $task->collaborators()->updateExistingPivot($candidate->id, [
                    'status' => 'accepted',
                    'decided_by' => $admin->id,
                    'responded_at' => now(),
                ]);
                $task->unsetRelation('collaborators');
                $task->load('collaborators');
                $this->notifications->notify(
                    [$candidate->id],
                    'collaborator_added',
                    'ถูกเพิ่มเข้าร่วมงาน',
                    'งาน “'.$task->job_topic.'” ได้รับอนุมัติ และคุณถูกเพิ่มเป็นผู้ร่วมงานแล้ว',
                    $task,
                    $admin
                );

                continue;
            }

            $this->notifyApprovers($task, $candidate, $inviter ?? $admin);
        }
    }

    public function rejectPendingAfterAssignmentRejection(WorkOrder $task, User $admin): void
    {
        DB::table('work_order_collaborators')
            ->where('work_order_id', $task->job_id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'decided_by' => $admin->id,
                'responded_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function notifyApprovers(WorkOrder $task, User $candidate, User $actor): void
    {
        $candidate->loadMissing('department');
        $this->notifications->notify(
            $this->notifications->departmentApprovalRecipientIds($candidate->department_id),
            'collaborator_approval_request',
            'ขออนุมัติผู้ร่วมงานข้ามแผนก',
            $actor->name.' ขอเพิ่ม '.$candidate->name.' ('.($candidate->department?->department_name ?? 'ไม่ระบุแผนก').') เข้าร่วมงาน “'.$task->job_topic.'”',
            $task,
            $actor
        );
    }
}
