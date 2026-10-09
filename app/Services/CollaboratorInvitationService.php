<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use App\Support\ProjectRoles;
use Illuminate\Support\Facades\DB;

/**
 * รับคนเข้าเป็น "ผู้เข้าร่วมโปรเจกต์" — เส้นทางเดียวที่ทุกจุดเพิ่มสมาชิกต้องผ่าน
 *
 * กติกา (ดู App\Support\ProjectRoles):
 * - หัวหน้าโปรเจกต์คือผู้สร้างงาน การเพิ่มสมาชิกไม่เปลี่ยนหัวหน้าโปรเจกต์
 * - คนที่ถูกเพิ่มเป็นผู้เข้าร่วมเสมอ ไม่ว่าจะแผนกเดียวกันหรือต่างแผนก
 * - เข้าร่วมได้ทันที ไม่มีคำขออนุมัติ ไม่มีสถานะรอหัวหน้าแผนกตัดสิน
 * - ต่างแผนกเป็นเพียงข้อมูลสำหรับแจ้งเตือนและหน้าติดตามของหัวหน้าแผนกต้นสังกัด
 *
 * สถานะ pending ที่ยังเหลืออยู่มาจากอีกเส้นทางหนึ่งเท่านั้น คือสมาชิกที่ถูกใส่ไว้ตอนสร้างงาน
 * ขณะที่ "ตัวงาน" ยังรออนุมัติการมอบหมายข้ามแผนกอยู่ (work_orders.approval_status)
 * เมื่อการมอบหมายถูกตัดสิน activateAfterAssignmentApproval() หรือ
 * rejectPendingAfterAssignmentRejection() จะปิดสถานะนั้นให้ครบทุกแถว
 */
class CollaboratorInvitationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * เพิ่มผู้เข้าร่วมหนึ่งคนเข้าโปรเจกต์ คืนสถานะที่บันทึกลง pivot หรือ null เมื่อไม่เข้าเกณฑ์
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

        // งานที่ตัวมันเองยังรออนุมัติการมอบหมาย ยังไม่มีโปรเจกต์ให้เข้าร่วมจริง
        // สมาชิกจึงค้างไว้จนการมอบหมายถูกตัดสิน ไม่ใช่การขออนุมัติตัวสมาชิก
        $status = $task->approval_status === 'approved' ? 'accepted' : 'pending';

        $task->collaborators()->attach($candidate->id, [
            'added_by' => $actor->id,
            'decided_by' => $status === 'accepted' ? $actor->id : null,
            'status' => $status,
            'responded_at' => $status === 'accepted' ? now() : null,
        ]);
        $task->unsetRelation('collaborators');
        $task->load('collaborators');

        AuditTrail::log('collaborator_added', $task, 'เพิ่มผู้เข้าร่วมโปรเจกต์: '.$task->job_topic, [
            'user_id' => $candidate->id,
            'status' => $status,
        ]);

        if ($status === 'accepted') {
            $this->announceParticipation($task, $candidate, $actor);
        }

        return $status;
    }

    /**
     * ปิดสถานะ pending ที่ค้างจากการมอบหมายข้ามแผนกเมื่อการมอบหมายได้รับอนุมัติ
     *
     * ทุกแถวเข้าร่วมได้ทั้งหมด เพราะอุปสรรคเดียวคือตัวงานเอง ไม่ใช่ตัวสมาชิก
     * (pivot ของผู้รับผิดชอบที่มาจาก Project Task Request ถูกตอบรับไปแล้วตั้งแต่ต้น
     * จึงไม่เข้ามาถึงที่นี่)
     */
    public function activateAfterAssignmentApproval(WorkOrder $task, User $admin): void
    {
        $task->loadMissing(['user.department', 'collaborators.department']);

        foreach ($task->collaborators->filter(fn (User $candidate) => $candidate->pivot?->status === 'pending') as $candidate) {
            $task->collaborators()->updateExistingPivot($candidate->id, [
                'status' => 'accepted',
                'decided_by' => $admin->id,
                'responded_at' => now(),
            ]);
            $task->unsetRelation('collaborators');
            $task->load('collaborators');

            if ((int) $candidate->id === (int) $task->user_id) {
                continue;
            }

            $this->announceParticipation($task, $candidate, $admin, 'โปรเจกต์ “'.$task->job_topic.'” ได้รับอนุมัติ และคุณเข้าร่วมแล้ว');
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

    /**
     * บอกผู้เข้าร่วมว่าเข้าร่วมแล้ว และบอกหัวหน้าแผนกต้นสังกัดเมื่อเป็นการข้ามแผนก
     *
     * ผู้เข้าร่วมข้ามแผนกต้องรู้จากตัวแจ้งเตือนเลยว่านี่เป็นงานข้ามแผนกของแผนกใด
     * ไม่ต้องเปิดเข้าไปในโปรเจกต์ก่อนจึงจะเข้าใจบริบทว่าตัวเองไปช่วยงานของใคร
     *
     * $lead คือประโยคขึ้นต้นที่ต่างกันตามเส้นทาง (ถูกเพิ่มทันที หรือเพิ่งได้รับอนุมัติงาน)
     * ส่วนหางประโยคที่บอกแผนกเจ้าของโปรเจกต์ใช้ชุดเดียวกันทั้งสองเส้นทาง
     */
    private function announceParticipation(WorkOrder $task, User $candidate, User $actor, ?string $lead = null): void
    {
        $candidate->loadMissing('department');
        $crossDepartment = ProjectRoles::joinsAcrossDepartments($task, $candidate);
        $projectDepartment = ProjectRoles::projectDepartmentName($task);

        $lead ??= ($actor->role === 'admin' ? 'ผู้ดูแลระบบ' : $actor->name)
            .' เพิ่มคุณเข้าร่วมโปรเจกต์ “'.$task->job_topic.'”';

        $this->notifications->notify(
            [$candidate->id],
            'collaborator_added',
            $crossDepartment ? 'คุณถูกเพิ่มเข้าร่วมโปรเจกต์ข้ามแผนก' : 'คุณถูกเพิ่มเข้าร่วมโปรเจกต์',
            $crossDepartment
                ? $lead.' — เป็นงานข้ามแผนก โปรเจกต์นี้เป็นของแผนก'.($projectDepartment ?? 'อื่น')
                : $lead,
            $task,
            $actor
        );

        /*
         * หัวหน้าแผนกต้นสังกัดของผู้เข้าร่วม "รับทราบ" ว่าลูกทีมไปร่วมโปรเจกต์ของแผนกอื่น
         *
         * เป็นการแจ้งเพื่อติดตามเท่านั้น ไม่มีคำขอให้ตัดสิน และไม่มี fallback ไปหา admin
         * เมื่อแผนกยังไม่มีหัวหน้า เพราะการเข้าร่วมเกิดขึ้นเสร็จแล้วตั้งแต่ต้น
         */
        if ($crossDepartment) {
            $this->notifications->notifyCrossDepartmentParticipation($task, $candidate, $actor);
        }
    }
}
