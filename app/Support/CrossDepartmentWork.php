<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * กติกากลางของ "งานข้ามแผนก" — ใช้ร่วมกันระหว่างบอร์ด ตัวกรอง และหน้าคำขออนุมัติ
 *
 * แผนกปลายทางของงานต้องนิยามตรงกับ WorkOrderPolicy::destinationDepartmentId() และ
 * WorkOrder::scopeInDepartmentOf() ทุกประการ คือใช้ department_id ของงานก่อน แล้วจึงตกไป
 * ใช้แผนกของผู้รับผิดชอบเมื่องานไม่ได้ระบุไว้
 */
final class CrossDepartmentWork
{
    public const JOINED = 'joined';

    public const ASSIGNED = 'assigned';

    /**
     * ธงบนตัวโมเดลที่บอกว่า children ของงานใบนี้ถูกตัดด้วยสิทธิ์ไปแล้ว
     *
     * ไม่ใช่คอลัมน์ในฐานข้อมูล เป็นค่าที่มีอายุเท่ากับ request เดียว และไม่ถูกบันทึก
     * เพราะไม่อยู่ใน $fillable และไม่มีการ save() งานที่ผ่าน restrictChildren()
     */
    private const RESTRICTED_CHILDREN_FLAG = 'children_restricted_by_access';

    public static function destinationDepartmentId(WorkOrder $task): ?int
    {
        $id = $task->department_id ?: $task->user?->department_id;

        return $id === null ? null : (int) $id;
    }

    public static function destinationDepartmentName(WorkOrder $task): ?string
    {
        return $task->department?->department_name
            ?? $task->user?->department?->department_name;
    }

    /**
     * ป้ายบอกว่างานใบนี้ข้ามแผนกจากมุมของ $subject (เจ้าของ Workspace ที่กำลังถูกดู)
     *
     *   joined   — $subject อยู่คนละแผนกกับปลายทางของงาน คือไปร่วมงานของแผนกอื่น
     *   assigned — ผู้สร้างงานอยู่คนละแผนกกับปลายทาง คือถูกมอบหมายเข้ามาจากแผนกอื่น
     *
     * @return array{kind: string, department: string, label: string}|null
     */
    public static function marker(WorkOrder $task, ?User $subject): ?array
    {
        $destination = self::destinationDepartmentId($task);

        if ($destination === null) {
            return null;
        }

        if ($subject?->department_id !== null && (int) $subject->department_id !== $destination) {
            $department = self::destinationDepartmentName($task) ?? 'แผนกอื่น';

            return [
                'kind' => self::JOINED,
                'department' => $department,
                'label' => 'ร่วมงานข้ามแผนก · '.$department,
            ];
        }

        $origin = $task->creator?->department_id;

        if ($origin !== null && (int) $origin !== $destination) {
            $department = $task->creator?->department?->department_name ?? 'แผนกอื่น';

            return [
                'kind' => self::ASSIGNED,
                'department' => $department,
                'label' => 'มอบหมายข้ามแผนกจาก '.$department,
            ];
        }

        return null;
    }

    /**
     * $subject เป็นคนนอกแผนกของงานใบนี้หรือไม่
     *
     * ผู้รับผิดชอบ ผู้สร้าง และหัวหน้างานไม่ใช่คนนอก แม้อยู่ต่างแผนก เพราะเป็นเจ้าของเนื้องาน
     * (เช่นแผนกบัญชีมอบหมายงานให้ IT — ผู้มอบหมายยังต้องเห็นงานย่อยครบ)
     */
    public static function isOutsider(WorkOrder $task, User $subject): bool
    {
        if ($subject->role === 'admin' || self::isOwner($task, $subject)) {
            return false;
        }

        $destination = self::destinationDepartmentId($task);

        return $destination !== null && (int) $subject->department_id !== $destination;
    }

    /**
     * ผู้ร่วมงานข้ามแผนกเห็นเฉพาะงานย่อยที่ตัวเองมีส่วนร่วม
     *
     * คนในแผนกเดียวกับงานยังเห็นงานย่อยครบทุกใบเหมือนเดิม การตัดทำที่ relation children
     * ที่เดียว ทุก partial ที่อ่าน $task->children (แถวบอร์ด ตัวนับ การ์ดตาราง) จึงเห็นชุดเดียวกัน
     *
     * งานแม่ที่ปรากฏเพราะงานย่อยของผู้ดู (WorkOrderPolicy::viewAsContext) ถูกตัดด้วยกติกา
     * เดียวกัน เพราะสิทธิ์ที่ได้มาจากงานย่อยใบหนึ่งต้องไม่เปิดงานย่อยใบอื่นให้โดยอัตโนมัติ
     *
     * @param  Collection<int, WorkOrder>  $tasks
     */
    public static function restrictChildren(Collection $tasks, User $subject): void
    {
        foreach ($tasks as $task) {
            if (! $task->relationLoaded('children')) {
                continue;
            }

            if (! self::isOutsider($task, $subject) && ! self::seenOnlyAsContext($task, $subject)) {
                continue;
            }

            $task->setRelation('children', $task->children
                ->filter(fn (WorkOrder $child) => self::participates($child, $subject))
                ->values());
            $task->setAttribute(self::RESTRICTED_CHILDREN_FLAG, true);
        }
    }

    /**
     * ความสัมพันธ์ children ของงานใบนี้ถูกตัดด้วยสิทธิ์ไปแล้วหรือยัง
     *
     * ใครที่นับงานย่อยเพื่อ "ตัดสินกฎ" (เช่นด่านปิดงานใน TaskStatusTransitionService)
     * ต้องถามก่อน แล้วกลับไปนับจากฐานข้อมูลเมื่อชุดในมือไม่ใช่ชุดเต็ม ไม่งั้นตัวเลขจะต่ำ
     * กว่าความจริงแล้วหน้าจอจะบอกว่าทำได้ทั้งที่ server ปฏิเสธ
     */
    public static function childrenWereRestricted(WorkOrder $task): bool
    {
        return (bool) $task->getAttribute(self::RESTRICTED_CHILDREN_FLAG);
    }

    /**
     * job_id ของงานย่อยที่ยังเหลืออยู่หลัง restrictChildren()
     *
     * @param  Collection<int, WorkOrder>  $tasks
     * @return array<int>
     */
    public static function visibleChildIds(Collection $tasks): array
    {
        return $tasks
            ->filter(fn (WorkOrder $task) => $task->relationLoaded('children'))
            ->flatMap(fn (WorkOrder $task) => $task->children->pluck('job_id'))
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** เกณฑ์เดียวกับ policy และ scope ของโมเดล — อยู่ที่ App\Support\TaskParticipation */
    public static function participates(WorkOrder $task, User $subject): bool
    {
        return TaskParticipation::participates($task, $subject);
    }

    /**
     * งานใบนี้ปรากฏกับผู้ดูเพราะงานย่อยของเขาเท่านั้นหรือไม่
     *
     * ถาม Gate เพื่อให้คำตอบมาจาก WorkOrderPolicy::viewAsContext() ที่เดียว ไม่ใช่
     * เขียนเงื่อนไขซ้ำที่นี่ การเช็คถูกวางไว้หลัง isOutsider() ซึ่งตัดงานส่วนใหญ่ไปแล้ว
     * และ viewAsContext() คืนเท็จทันทีเมื่อผู้ดูเป็นผู้มีส่วนร่วม จึงไม่เพิ่มคิวรีให้งานปกติ
     */
    private static function seenOnlyAsContext(WorkOrder $task, User $subject): bool
    {
        return Gate::forUser($subject)->allows('viewAsContext', $task);
    }

    private static function isOwner(WorkOrder $task, User $subject): bool
    {
        return TaskParticipation::isOwner($task, $subject);
    }
}
