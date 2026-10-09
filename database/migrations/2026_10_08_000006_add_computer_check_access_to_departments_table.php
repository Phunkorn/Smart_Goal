<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สิทธิ์ใช้งาน "ตรวจเช็กคอมพิวเตอร์ประจำวัน" รายแผนก
 *
 * ทำไมเป็นคอลัมน์บน departments ไม่ใช่ตารางสิทธิ์ใหม่
 * ---------------------------------------------------------------
 * CLAUDE.md ห้ามสร้างระบบ permission ซ้ำซ้อน และสิ่งที่ต้องตอบมีคำถามเดียวคือ
 * "แผนกนี้ใช้ฟังก์ชันนี้ได้หรือไม่" ซึ่งเป็นค่า boolean ของแผนกหนึ่งแผนก
 * ตารางกลางแบบ department_feature จะเพิ่มชั้นข้อมูลโดยไม่ตอบอะไรเพิ่ม
 * ส่วนการบังคับใช้ยังอยู่ที่ ComputerCheckSessionPolicy ที่เดียวตามเดิม
 *
 * ค่าเริ่มต้นเป็นปิด ตามข้อกำหนด แต่แผนกที่ "ใช้อยู่แล้วจริง" ถูกเปิดให้ตอน migrate
 * ---------------------------------------------------------------
 * ถ้าปิดทุกแผนกอย่างเคร่งครัด ทีมที่เดินตรวจเครื่องอยู่ทุกวันจะเข้าหน้านี้ไม่ได้
 * ทันทีที่ deploy จนกว่า admin จะเข้าไปติ๊กให้ ซึ่งเป็นการตัดงานที่กำลังทำอยู่
 * จึงเปิดให้เฉพาะแผนกที่มีรอบตรวจอยู่ในฐานข้อมูลแล้ว — หลักฐานว่าใช้งานจริง
 * ไม่ใช่การเปิดให้ทั้งระบบ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('computer_check_enabled')
                ->default(false)
                ->after('department_name');
        });

        // แผนกเจ้าของรอบตรวจอ่านจาก computer_check_sessions.department_id ก่อน
        // แล้ว fallback ไปที่แผนกของผู้ตรวจคนที่ 1 — นิยามเดียวกับ
        // ComputerCheckSessionPolicy::sessionDepartmentId() เพื่อไม่ให้มีสองคำตอบ
        $usedDepartmentIds = DB::table('computer_check_sessions')
            ->leftJoin('users', 'users.id', '=', 'computer_check_sessions.checker_1_id')
            ->selectRaw('coalesce(computer_check_sessions.department_id, users.department_id) as department_id')
            ->pluck('department_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($usedDepartmentIds !== []) {
            DB::table('departments')
                ->whereIn('id', $usedDepartmentIds)
                ->update(['computer_check_enabled' => true]);
        }
    }

    /**
     * ย้อนกลับได้เสมอ — การลบคอลัมน์นี้ทำให้ทุกแผนกกลับไปใช้งานได้เหมือนก่อนมี
     * ระบบสิทธิ์ ซึ่งเป็นสถานะเดิมของระบบจริง ไม่มีข้อมูลของผู้ใช้สูญหาย
     * (รอบตรวจและผลการตรวจอยู่ในตารางของตัวเองและไม่ถูกแตะ)
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('computer_check_enabled');
        });
    }
};
