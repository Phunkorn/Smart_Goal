<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

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
     * @param  Collection<int, WorkOrder>  $tasks
     */
    public static function restrictChildren(Collection $tasks, User $subject): void
    {
        foreach ($tasks as $task) {
            if (! $task->relationLoaded('children') || ! self::isOutsider($task, $subject)) {
                continue;
            }

            $task->setRelation('children', $task->children
                ->filter(fn (WorkOrder $child) => self::participates($child, $subject))
                ->values());
        }
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

    public static function participates(WorkOrder $task, User $subject): bool
    {
        if (self::isOwner($task, $subject)) {
            return true;
        }

        $collaborators = $task->relationLoaded('collaborators')
            ? $task->collaborators
            : $task->collaborators()->get();

        return $collaborators->contains(
            fn (User $person) => (int) $person->id === (int) $subject->id && $person->pivot?->status === 'accepted'
        );
    }

    private static function isOwner(WorkOrder $task, User $subject): bool
    {
        return in_array((int) $subject->id, array_map('intval', array_filter([
            $task->user_id,
            $task->created_by,
            $task->leader_user_id,
        ])), true);
    }
}
