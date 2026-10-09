<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * บทบาทในโปรเจกต์หนึ่งใบ — แหล่งความจริงเดียวของคำว่า "หัวหน้าโปรเจกต์" และ "ผู้เข้าร่วม"
 *
 * กติกา:
 *   หัวหน้าโปรเจกต์ (leader) = ผู้สร้างโปรเจกต์/งานใบนั้น คือ work_orders.created_by
 *   ผู้เข้าร่วม (participant)  = ทุกคนที่เหลือในทีม ทั้งผู้รับผิดชอบงานและคนที่ถูกเพิ่มเข้ามา
 *
 * การถูกเพิ่มเข้าโปรเจกต์ไม่ทำให้ใครกลายเป็นหัวหน้าโปรเจกต์ และแผนกไม่ได้กำหนดบทบาท
 * ต่างแผนกเป็นเพียงป้าย "ข้ามแผนก" ที่ใช้แสดงผล แจ้งเตือน และหน้าติดตามของหัวหน้าแผนก
 * ไม่ใช่เงื่อนไขที่ต้องขออนุมัติก่อนเข้าร่วม
 *
 * แผนกเจ้าของโปรเจกต์ใช้นิยามเดียวกับทั้งระบบ (CrossDepartmentWork::destinationDepartmentId())
 * เพื่อไม่ให้ "ป้ายข้ามแผนกบนหน้าจอ" กับ "สิทธิ์ดูงานใน WorkOrderPolicy" หลุดจากกัน
 */
final class ProjectRoles
{
    public const LEADER = 'leader';

    public const PARTICIPANT = 'participant';

    /**
     * หัวหน้าโปรเจกต์ต้องระบุได้เสมอ งานที่นำเข้าจากระบบเดิมบางใบไม่มี created_by
     * จึงไล่ไปที่หัวหน้างานแล้วผู้รับผิดชอบ เพื่อไม่ให้มีโปรเจกต์ที่ไม่มีหัวหน้าเลย
     */
    public static function leaderId(WorkOrder $task): ?int
    {
        $id = $task->created_by ?: $task->leader_user_id ?: $task->user_id;

        return $id === null ? null : (int) $id;
    }

    public static function leader(WorkOrder $task): ?User
    {
        $leaderId = self::leaderId($task);

        if ($leaderId === null) {
            return null;
        }

        /*
         * อ่านจาก relation ที่โหลดมาแล้วก่อนเสมอ หน้าจอวาดงานหลายสิบใบต่อหนึ่งคำขอ
         * การปล่อยให้ lazy load ที่นี่คือ N+1 ที่ซ่อนอยู่ใน Blade
         */
        foreach (['creator', 'leader', 'assignee', 'user'] as $relation) {
            if (! $task->relationLoaded($relation)) {
                continue;
            }

            $candidate = $task->{$relation};

            if ($candidate && (int) $candidate->id === $leaderId) {
                return $candidate;
            }
        }

        return User::find($leaderId);
    }

    public static function isLeader(WorkOrder $task, User|int|null $member): bool
    {
        $memberId = $member instanceof User ? (int) $member->id : ($member === null ? null : (int) $member);

        return $memberId !== null && self::leaderId($task) === $memberId;
    }

    /**
     * คนคนนี้เข้าร่วมโปรเจกต์นี้แบบข้ามแผนกหรือไม่
     *
     * ตอบเฉพาะ "ที่มาของคน" เทียบกับแผนกเจ้าของโปรเจกต์ ไม่เกี่ยวกับสิทธิ์หรือการอนุมัติ
     * คนที่ยังไม่ถูกจัดเข้าแผนก หรือโปรเจกต์ที่ยังไม่มีแผนกเจ้าของ ตอบ false เพราะไม่มี
     * สองแผนกให้เทียบกัน การขึ้นป้ายในกรณีนั้นจะกลายเป็นข้อมูลที่ยืนยันไม่ได้
     */
    public static function joinsAcrossDepartments(WorkOrder $task, ?User $member): bool
    {
        $projectDepartmentId = CrossDepartmentWork::destinationDepartmentId($task);

        return $projectDepartmentId !== null
            && $member?->department_id !== null
            && (int) $member->department_id !== $projectDepartmentId;
    }

    public static function projectDepartmentName(WorkOrder $task): ?string
    {
        return CrossDepartmentWork::destinationDepartmentName($task);
    }

    /**
     * ผู้เข้าร่วมทั้งหมดของโปรเจกต์ เรียงจากผู้รับผิดชอบงานไปยังคนที่ถูกเพิ่มเข้ามา
     *
     * หัวหน้าโปรเจกต์ไม่อยู่ในรายการนี้ เพราะหน้าจอแสดงสองส่วนแยกกันอยู่แล้ว และคนหนึ่งคน
     * ต้องปรากฏครั้งเดียวเท่านั้น (ผู้สร้างที่มอบหมายงานให้ตัวเองคือหัวหน้าโปรเจกต์ ไม่ใช่
     * ผู้เข้าร่วมของตัวเอง)
     *
     * @return Collection<int, array{user: User, status: string, is_assignee: bool, cross_department: bool}>
     */
    public static function participants(WorkOrder $task): Collection
    {
        $leaderId = self::leaderId($task);
        $seen = [];
        $participants = collect();

        $add = function (?User $member, string $status, bool $isAssignee) use (&$seen, &$participants, $leaderId, $task): void {
            if (! $member || (int) $member->id === $leaderId || in_array((int) $member->id, $seen, true)) {
                return;
            }

            $seen[] = (int) $member->id;
            $participants->push([
                'user' => $member,
                'status' => $status,
                'is_assignee' => $isAssignee,
                'cross_department' => self::joinsAcrossDepartments($task, $member),
            ]);
        };

        // ผู้รับผิดชอบงานเป็นผู้เข้าร่วมที่ถือความรับผิดชอบมากที่สุด จึงมาก่อนเสมอ
        $add(self::assigneeOf($task), 'accepted', true);

        $collaborators = $task->relationLoaded('collaborators')
            ? $task->collaborators
            : $task->collaborators()->get();

        foreach ($collaborators as $member) {
            $add($member, $member->pivot?->status ?? 'accepted', false);
        }

        return $participants->values();
    }

    /**
     * ผู้รับผิดชอบงาน — user() กับ assignee() ชี้ไปที่ user_id เดียวกัน แต่เป็น relation
     * ต่างตัวกัน จึงต้องใช้ตัวที่ controller โหลดมาแล้วเพื่อไม่ให้เกิดคิวรีเพิ่มต่อหนึ่งงาน
     */
    private static function assigneeOf(WorkOrder $task): ?User
    {
        foreach (['assignee', 'user'] as $relation) {
            if ($task->relationLoaded($relation)) {
                return $task->{$relation};
            }
        }

        return $task->user;
    }
}
