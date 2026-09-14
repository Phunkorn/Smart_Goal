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
     *
     * $actorHasFinalSay: ผู้เรียกยืนยันแล้วว่าผู้กระทำมีอำนาจชี้ขาดการรับคนเข้างานใบนี้
     * เอง จึงไม่ต้องส่งคำขอต่อให้ใครอีก ใช้กับเส้นทางที่ตรวจอำนาจมาแล้วจากภายนอก เช่น
     * หัวหน้าแผนกที่ดูแลแผนกปลายทางของงานอนุมัติคำขอเข้าร่วมงานที่ตัวเองแชร์
     * (App\Services\WorkOrderShareService::decidesAlone())
     *
     * ค่าเริ่มต้นเป็น false เพื่อให้ผู้เรียกเดิมทุกจุดได้พฤติกรรมเดิมทุกประการ — กติกา
     * ของการเชิญผู้ร่วมงานแบบเดิมคือ "หัวหน้าแผนกของคนที่ถูกยืมตัวเป็นผู้อนุมัติ"
     * ซึ่งตั้งใจให้เป็นแบบนั้น ห้ามเปลี่ยนโดยไม่ตั้งใจ
     */
    public function invite(WorkOrder $task, User $candidate, User $actor, bool $actorHasFinalSay = false): ?string
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
        /*
         * ผู้เชิญที่เป็นผู้อนุมัติของคนคนนี้อยู่แล้ว ไม่ต้องขออนุมัติจากตัวเอง
         *
         * โมเดลของการเชิญคือ "ยืมตัวคน" ผู้อนุมัติจึงเป็นหัวหน้าแผนกของ candidate
         * เสมอ (ดู notifyApprovers) ซึ่งถูกต้อง แต่มีช่องที่ตรรกะนี้วนกลับมาที่ตัวเอง:
         * หัวหน้าแผนกเชิญลูกน้องของตัวเองเข้างานที่ปลายทางเป็นแผนกอื่น $sameDepartment
         * เป็น false สถานะจึงเป็น pending แล้วผู้อนุมัติที่ถูกเลือกคือผู้เชิญคนเดิม
         *
         * ผลคือคำขอค้างเงียบ — notifyApprovalRequest() ตัดผู้รับที่เป็นคนลงมือออก
         * แจ้งเตือนฉบับเดียวที่จะออกจึงหายไป ไม่มีใครรู้ว่ามีอะไรรออยู่ และคนที่ถูกเชิญ
         * ไม่เคยเข้ามาในงาน
         *
         * การรับเข้าเลยไม่ได้เพิ่มอำนาจให้ใคร ผู้เชิญกดอนุมัติเองได้อยู่แล้ว แค่ตัดพิธี
         * ที่ไม่มีปลายทางออก
         */
        $actorDecidesAlone = $actor->overseesDepartment($candidate->department_id);

        $status = $task->approval_status === 'approved'
            && ($actorHasFinalSay || $actor->role === 'admin' || $sameDepartment
                || $actorDecidesAlone || $candidate->isDepartmentHead())
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

            // เกณฑ์ชุดเดียวกับ invite() — ผู้เชิญที่เป็นผู้อนุมัติของ candidate อยู่แล้ว
            // ไม่ต้องรอใครอีก ถ้าไม่ตรงกันสองที่นี้ คำขอจะค้างในคิวที่ไม่มีใครไปกดได้
            $inviterDecidesAlone = $inviter?->overseesDepartment($candidate->department_id) ?? false;

            if ($sameDepartment || $inviter?->role === 'admin' || $inviterDecidesAlone || $candidate->isDepartmentHead()) {
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
        $notified = $this->notifications->notifyApprovalRequest(
            $this->notifications->departmentApprovalRecipientIds($candidate->department_id),
            'collaborator_approval_request',
            'ขออนุมัติผู้ร่วมงานข้ามแผนก',
            $actor->name.' ขอเพิ่ม '.$candidate->name.' ('.($candidate->department?->department_name ?? 'ไม่ระบุแผนก').') เข้าร่วมงาน “'.$task->job_topic.'”',
            $task,
            $actor,
            $candidate
        );

        /*
         * คำขอที่ไม่มีผู้รับเลยคือคำขอที่ไม่มีวันถูกตัดสิน
         *
         * หลังจากกันกรณีผู้เชิญเป็นผู้อนุมัติของตัวเองแล้ว กรณีนี้ไม่ควรเกิดอีก แต่ถ้า
         * เกิดขึ้นจริง (เช่นหัวหน้าแผนกถูกปิดบัญชีระหว่างทาง) ต้องเหลือร่องรอยไว้
         * ไม่ใช่เงียบหายไปพร้อมกับผู้ร่วมงานที่ค้างอยู่ pending ตลอดกาล
         */
        if ($notified->isEmpty()) {
            AuditTrail::log('collaborator_approval_unrouted', $task,
                'คำขอผู้ร่วมงานไม่มีผู้อนุมัติที่รับเรื่องได้: '.$task->job_topic, [
                    'user_id' => $candidate->id,
                    'department_id' => $candidate->department_id,
                ]);
        }
    }
}
