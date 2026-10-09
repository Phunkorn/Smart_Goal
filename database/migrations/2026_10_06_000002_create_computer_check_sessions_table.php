<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รอบการตรวจเช็กคอมพิวเตอร์ประจำวัน — หนึ่งแถวเท่ากับแบบฟอร์มกระดาษหนึ่งใบ
 *
 * หนึ่งรอบ = หนึ่งวัน + หนึ่งกะ (เช้า/บ่าย) ตามที่กระดาษจริงมีช่อง AM/PM ให้ติ๊ก
 * unique (check_date, shift) จึงบังคับกติกานั้นที่ระดับฐานข้อมูล ไม่ใช่แค่ในโค้ด
 * เพราะการกดเริ่มตรวจจากสองเครื่องพร้อมกันจะสร้างใบซ้ำได้ถ้าเช็กแต่ในโค้ด
 *
 * ความสัมพันธ์กับบันทึกงานประจำวัน
 * ---------------------------------------------------------------
 * work_log_id เป็น nullable เพราะรอบที่ยังตรวจไม่เสร็จยังไม่มีบันทึกงาน
 * บันทึกงานถูกสร้างครั้งเดียวตอนกด "บันทึกผลการตรวจ" — หนึ่งรอบได้หนึ่งรายการ
 * ไม่ใช่หนึ่งรายการต่อหนึ่งเครื่อง (ดู ComputerCheckService::complete)
 *
 * nullOnDelete() เพราะบันทึกงานเป็น soft delete ก็จริง แต่ถ้าถูกลบถาวรภายหลัง
 * ผลการตรวจที่เกิดขึ้นจริงไม่ควรหายตามไปด้วย ใบตรวจยังเปิดย้อนหลังได้
 *
 * department_id เก็บเป็น snapshot ตอนเริ่มรอบ ด้วยเหตุผลเดียวกับ work_logs:
 * ประวัติต้องอยู่กับหัวหน้าแผนกที่ดูแลอยู่ในตอนนั้น แม้ผู้ตรวจจะย้ายแผนกภายหลัง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_check_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_log_id')->nullable()->constrained('work_logs')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('check_date');
            // 'AM' = รอบเช้า, 'PM' = รอบบ่าย — ตรงกับช่องติ๊กบนกระดาษ
            $table->string('shift', 2);
            $table->foreignId('checker_1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('checker_2_id')->nullable()->constrained('users')->nullOnDelete();
            // เวลาเริ่มถูกเก็บอัตโนมัติตอนกดเริ่มรอบ ไม่ใช่ค่าที่ผู้ใช้กรอก
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            // 'in_progress' | 'completed'
            $table->string('status', 20)->default('in_progress');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['check_date', 'shift'], 'computer_check_sessions_day_shift_unique');
            $table->index(['check_date', 'status'], 'computer_check_sessions_day_status_index');
            $table->index('department_id', 'computer_check_sessions_department_index');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('computer_check_sessions') && DB::table('computer_check_sessions')->exists()) {
            throw new RuntimeException(
                'computer_check_sessions ยังมีรอบการตรวจอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('computer_check_sessions');
    }
};
