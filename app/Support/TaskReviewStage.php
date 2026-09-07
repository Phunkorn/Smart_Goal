<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * ขั้น "รอตรวจสอบ" มีอยู่จริงเฉพาะงานที่ทำร่วมกับผู้อื่น
 *
 * งานที่ผู้ใช้เปิดเอง รับผิดชอบเอง และเป็นผู้อนุมัติของตัวเองไม่มีใครตรวจงานให้
 * TaskStatusTransitionService จึงปฏิเสธการเปลี่ยนไปสถานะ 3 ของงานแบบนั้นเสมอ
 * ที่นี่คือความจริงชุดเดียวกันในรูปแบบที่หน้าจอถามได้ เพื่อไม่ให้บอร์ดและตาราง
 * เสนอสถานะที่ระบบไม่ยอมรับ ซึ่งทำให้ผู้ใช้สับสนว่าใครเป็นคนตรวจงานของตัวเอง
 */
final class TaskReviewStage
{
    public static function approverId(WorkOrder $task): ?int
    {
        return $task->created_by ?: ($task->leader_user_id ?: $task->user_id);
    }

    public static function isSelfTask(WorkOrder $task, User $actor): bool
    {
        return (int) $task->user_id === (int) $actor->id
            && (int) self::approverId($task) === (int) $actor->id;
    }

    /**
     * งานใบนี้ควรแสดงสถานะ "รอตรวจสอบ" ให้ผู้ใช้คนนี้เห็นหรือไม่
     *
     * งานที่อยู่ในสถานะ 3 อยู่แล้วต้องแสดงเสมอ ไม่เช่นนั้นป้ายสถานะปัจจุบัน
     * จะหายไปจากรายการตัวเลือกของตัวมันเอง
     */
    public static function appliesTo(WorkOrder $task, User $actor): bool
    {
        return (int) $task->job_status === 3 || ! self::isSelfTask($task, $actor);
    }

    /**
     * มีงานอย่างน้อยหนึ่งใบในชุดนี้ที่มีขั้นตรวจสอบหรือไม่
     *
     * มุมมองตารางแบ่งคอลัมน์ตามสถานะ ไม่ใช่ตามงาน จึงตัดสินทั้งคอลัมน์จากชุดงานที่แสดงอยู่
     */
    public static function appliesToAny(iterable $tasks, User $actor): bool
    {
        foreach ($tasks as $task) {
            if (self::appliesTo($task, $actor)) {
                return true;
            }
        }

        return false;
    }
}
