<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * นิยามเดียวของ "ผู้ใช้มีส่วนร่วมกับงานใบนี้โดยตรง"
 *
 * เกณฑ์นี้เคยถูกเขียนซ้ำอยู่สี่ที่ — WorkOrder::scopeInvolving(), scopeContributedBy(),
 * WorkOrderPolicy::isTaskParticipant() และ CrossDepartmentWork::participates() — ทั้งที่
 * ต้องตอบเหมือนกันทุกครั้ง เวลาเกณฑ์ไม่ตรงกันจะเกิดสภาพที่ policy บอกว่า "ดูได้"
 * แต่คิวรีไม่คืนงานใบนั้นมาให้เห็น ซึ่งเป็นต้นเหตุของบั๊กงานย่อยข้ามแผนกโดยตรง
 *
 * "มีส่วนร่วมโดยตรง" = ผู้รับผิดชอบ ผู้สร้างงาน หัวหน้างาน หรือผู้ร่วมงานที่ตอบรับแล้ว
 *
 * ที่นี่ไม่มีการยกเว้นให้ admin และไม่ตัดสินเรื่องแผนก เพราะทั้งสองอย่างเป็นเรื่องของสิทธิ์
 * ซึ่งเป็นหน้าที่ของ WorkOrderPolicy ไม่ใช่คำถามว่า "ใครทำงานใบนี้"
 */
final class TaskParticipation
{
    /** สถานะผู้ร่วมงานที่นับว่าเข้าร่วมแล้วจริง — คำเชิญที่ยังรอตอบรับไม่นับ */
    public const ACCEPTED = 'accepted';

    /**
     * เงื่อนไขคิวรีของ "ผู้มีส่วนร่วมโดยตรง"
     *
     * ครอบเงื่อนไขทั้งก้อนไว้ใน where() ของตัวเอง ผู้เรียกจึงต่อ where() อื่นข้างนอกได้
     * โดยไม่ทำให้ OR ภายในรั่วออกไปคลุมเงื่อนไขของผู้เรียก
     */
    public static function matches(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query->where(function (Builder $participant) use ($userId): void {
            $participant->where('user_id', $userId)
                ->orWhere('created_by', $userId)
                ->orWhere('leader_user_id', $userId)
                ->orWhereHas('collaborators', fn (Builder $collaborators) => $collaborators
                    ->where('users.id', $userId)
                    ->where('work_order_collaborators.status', self::ACCEPTED));
        });
    }

    /**
     * งานที่มี "งานย่อยอย่างน้อยหนึ่งใบ" ซึ่งผู้ใช้มีส่วนร่วมโดยตรง
     *
     * นี่คือเงื่อนไขของ contextual visibility: การเข้าร่วมงานย่อยทำให้เห็นงานแม่
     * เพื่อเป็นบริบท แต่ไม่ได้ทำให้กลายเป็นผู้มีส่วนร่วมของงานแม่
     *
     * นับเฉพาะงานย่อยที่อนุมัติแล้ว เกณฑ์เดียวกับสาขา approved ของ scopeInvolving()
     */
    public static function matchesThroughChild(Builder $query, User|int $user): Builder
    {
        return $query->whereHas('children', fn (Builder $child) => self::matches(
            $child->where('approval_status', 'approved'),
            $user
        ));
    }

    /**
     * คำถามเดียวกันกับ matches() แต่ถามกับงานที่โหลดมาแล้วหนึ่งใบ
     *
     * ใช้ความสัมพันธ์ที่ eager-load มาแล้วเมื่อมี ไม่งั้นหน้า Workspace จะยิงคิวรีเพิ่มต่องาน
     */
    public static function participates(WorkOrder $task, User $user): bool
    {
        if (self::isOwner($task, $user)) {
            return true;
        }

        if ($task->relationLoaded('collaborators')) {
            return $task->collaborators->contains(
                fn ($person) => (int) $person->id === (int) $user->id
                    && $person->pivot?->status === self::ACCEPTED
            );
        }

        return $task->collaborators()
            ->where('users.id', $user->id)
            ->wherePivot('status', self::ACCEPTED)
            ->exists();
    }

    /**
     * ผู้ใช้มีส่วนร่วมโดยตรงกับงานย่อยของงานใบนี้อย่างน้อยหนึ่งใบหรือไม่
     *
     * ต้องระวังว่า children ที่โหลดมาอาจถูก CrossDepartmentWork::restrictChildren() ตัดแล้ว
     * ซึ่งไม่เป็นปัญหาสำหรับคำถามนี้ เพราะการตัดนั้นเหลือไว้เฉพาะงานย่อยที่ผู้ใช้ร่วมอยู่
     * ถ้าคำตอบเป็นจริงก่อนตัด มันก็ยังจริงหลังตัด
     */
    public static function participatesInChild(WorkOrder $task, User $user): bool
    {
        if ($task->relationLoaded('children')) {
            return $task->children->contains(
                fn (WorkOrder $child) => $child->approval_status === 'approved'
                    && self::participates($child, $user)
            );
        }

        return self::matches(
            $task->children()->getQuery()->where('approval_status', 'approved'),
            $user
        )->exists();
    }

    /** ผู้รับผิดชอบ ผู้สร้างงาน หรือหัวหน้างานของงานใบนี้ */
    public static function isOwner(WorkOrder $task, User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', array_filter([
            $task->user_id,
            $task->created_by,
            $task->leader_user_id,
        ])), true);
    }
}
