<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ย้ายรายละเอียดงานเดิม (work_order_subtasks) ขึ้นมาเป็นงานย่อยจริงใน work_orders
 *
 * แถวเดิมใน work_order_subtasks ไม่ถูกลบทิ้ง เพื่อให้ยังกู้คืนหรือตรวจสอบย้อนหลังได้
 * ถ้ามีงานย่อยชื่อเดียวกันอยู่แล้วจะข้าม การรัน migration ซ้ำจึงไม่สร้างงานซ้ำ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_order_subtasks')) {
            return;
        }

        DB::table('work_order_subtasks')
            ->orderBy('work_order_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->chunk(200, function ($subtasks): void {
                foreach ($subtasks as $subtask) {
                    $parent = DB::table('work_orders')->where('job_id', $subtask->work_order_id)->first();

                    if (! $parent) {
                        continue;
                    }

                    $exists = DB::table('work_orders')
                        ->where('parent_job_id', $parent->job_id)
                        ->where('job_topic', $subtask->title)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('work_orders')->insert([
                        'user_id' => $parent->user_id,
                        'created_by' => $subtask->created_by ?? $parent->created_by,
                        'assigned_by' => $parent->assigned_by,
                        'leader_user_id' => $parent->leader_user_id,
                        'department_id' => $parent->department_id,
                        'work_order_list_id' => $parent->work_order_list_id,
                        'parent_job_id' => $parent->job_id,
                        'parent_sort_order' => (int) $subtask->sort_order,
                        'job_topic' => $subtask->title,
                        'job_priority' => $parent->job_priority,
                        // 2 = กำลังทำ, 4 = เสร็จแล้ว — ค่าเดียวกับที่หน้าบอร์ดใช้อยู่
                        'job_status' => $subtask->is_completed ? 4 : 2,
                        // work_orders.job_start_at / job_due_at เป็น NOT NULL งานย่อยจึงต้องมีช่วงเวลาเสมอ
                        // ใช้ของงานแม่ก่อน แล้วค่อยตกไปใช้วันที่สร้างเมื่องานแม่ไม่มีค่า
                        'job_start_at' => $parent->job_start_at ?? $parent->created_at ?? now(),
                        'job_due_at' => $parent->job_due_at ?? $parent->created_at ?? now(),
                        'job_completed_at' => $subtask->is_completed ? ($subtask->updated_at ?? now()) : null,
                        'approval_status' => $parent->approval_status,
                        'approved_by' => $parent->approved_by,
                        'approved_at' => $parent->approved_at,
                        'created_at' => $subtask->created_at ?? now(),
                        'updated_at' => $subtask->updated_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // งานย่อยที่ถูกย้ายขึ้นมาอาจถูกแก้ไข มอบหมาย หรือมีคอมเมนต์ต่อไปแล้ว
        // การลบทิ้งเพื่อย้อน migration จึงเป็นการทำลายงานจริง ไม่ใช่การคืนสภาพ
        throw new RuntimeException('งานย่อยถูกแปลงเป็นงานจริงแล้ว ย้อน migration นี้อัตโนมัติไม่ได้');
    }
};
