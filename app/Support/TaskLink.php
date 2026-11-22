<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * ลิงก์ "เปิดงานใบนี้" ของผู้ใช้คนหนึ่ง — ที่เดียวที่ตัดสินว่างานใบนั้นเปิดได้จากหน้าไหน
 *
 * งานใบเดียวกันเปิดได้จากคนละหน้าตามคนที่กด: admin เปิดใน Workspace ของสมาชิก
 * หัวหน้าเปิดงานของลูกทีมใน Workspace ของสมาชิกคนนั้น (งานของลูกทีมไม่ได้อยู่ใน "งานของฉัน"
 * ของหัวหน้า) ส่วนคนอื่นเปิดที่ "งานของฉัน" ด้วย ?open_task=
 *
 * ใช้ร่วมกันระหว่างการแจ้งเตือน (NotificationService) และการค้นหาบน Topbar
 * (GlobalSearchService) เพื่อไม่ให้สองที่พาไปคนละหน้า
 *
 * ไม่ตรวจสิทธิ์เอง ผู้เรียกต้องผ่าน WorkOrderPolicy::view() มาก่อนแล้ว
 * และควรโหลดความสัมพันธ์ user ของงานมาก่อน เพื่อไม่ให้แต่ละงานยิงคิวรีของตัวเอง
 */
class TaskLink
{
    /**
     * @param  array<string, scalar>  $query  พารามิเตอร์เพิ่มเติม เช่น ['task_tab' => 'updates']
     */
    public static function open(WorkOrder $task, User $viewer, array $query = []): string
    {
        $query = ['open_task' => $task->job_id] + $query;
        $owner = $task->user;

        if ($viewer->role === 'admin' && $owner?->role === 'user' && $owner->department_id) {
            return route('admin.work-board.member', [
                'department' => $owner->department_id,
                'user' => $task->user_id,
            ] + $query);
        }

        if ($task->user_id !== $viewer->id
            && $owner?->role === 'user'
            && $viewer->overseesDepartment($owner->department_id)) {
            return route('work-board.member', [
                'department' => $owner->department_id,
                'user' => $task->user_id,
                'workspace' => 1,
            ] + $query);
        }

        if ($viewer->role === 'viewer') {
            return route('tasks.show', $task->job_id);
        }

        return route('mytasks.index', $query);
    }
}
