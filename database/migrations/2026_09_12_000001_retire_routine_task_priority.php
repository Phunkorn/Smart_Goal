<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เลิกใช้ความสำคัญระดับ 1 ("routine") ของงาน
 *
 * เจ้าของระบบเลิกใช้ระดับนี้แล้ว UI จึงไม่มีตัวเลือกให้เลือกอีก และ validation ไม่รับค่า 1
 * ถ้าไม่ย้ายข้อมูลเก่า งานที่เคยตั้งเป็น routine จะตกไปใช้ค่าสำรองของหน้าจอ
 * แล้วแสดงเป็น "สำคัญไม่ด่วน" ทั้งที่ฐานข้อมูลยังเก็บเลข 1 อยู่ — หน้าจอกับข้อมูลจะไม่ตรงกัน
 * และไม่มีทางแก้ผ่านหน้าจอได้เลย เพราะปุ่มของระดับนั้นหายไปแล้ว
 *
 * ย้ายไประดับ 5 ("ไม่รีบ ไม่มีกำหนด") ซึ่งใกล้เคียงความหมายเดิมที่สุด
 *
 * ระดับความสำคัญของ "โปรเจกต์" (work_order_lists.priority) ใช้สเกลคนละชุด (1-3 = ต่ำ/กลาง/สูง)
 * และยังใช้ระดับ 1 อยู่ จึงไม่ถูกแตะ
 */
return new class extends Migration
{
    /** ตารางที่เก็บความสำคัญของงานด้วยสเกลเดียวกัน */
    private const TABLES = ['work_orders', 'work_order_list_task_requests'];

    private const RETIRED = 1;

    private const REPLACEMENT = 5;

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('job_priority', self::RETIRED)
                ->update(['job_priority' => self::REPLACEMENT]);
        }
    }

    /**
     * ย้อนกลับไม่ได้ และต้องไม่แกล้งทำเป็นย้อนได้
     *
     * หลัง up() แล้ว งานที่เคยเป็น routine กับงานที่ผู้ใช้ตั้งเป็น "ไม่รีบ ไม่มีกำหนด" เองมาแต่แรก
     * มีค่าเท่ากันทุกประการ ไม่มีคอลัมน์ใดเหลือไว้ให้แยกสองกลุ่มนี้ออกจากกัน
     * การย้อนทั้งหมดกลับเป็น 1 จะทำลายค่าที่ผู้ใช้ตั้งเอง ซึ่งกู้คืนไม่ได้
     *
     * ถ้าต้องการระดับ routine กลับมาจริง ให้เพิ่มกลับที่ UI แล้วตั้งค่าทีละงานตามจริง
     */
    public function down(): void
    {
        $affected = 0;

        foreach (self::TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $affected += DB::table($table)->where('job_priority', self::REPLACEMENT)->count();
        }

        if ($affected > 0) {
            throw new RuntimeException(
                'ย้อน migration นี้ไม่ได้: งานที่เคยเป็น routine ถูกรวมกับงานระดับ "ไม่รีบ ไม่มีกำหนด" ไปแล้ว '
                .'('.$affected.' รายการ) และแยกออกจากกันไม่ได้อีก การย้อนกลับจะทับค่าที่ผู้ใช้ตั้งเอง'
            );
        }
    }
};
