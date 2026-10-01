<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประกาศของหัวหน้าแผนก — แสดงให้ผู้รับเห็นใน "สรุปประจำวัน" (Daily Brief)
 *
 * ทำไม department_id เก็บซ้ำไว้ที่ประกาศ แทนการอ่านจากผู้สร้าง
 * ---------------------------------------------------------------
 * ประกาศ "เฉพาะแผนก" ต้องไปถึงแผนกที่ผู้สร้างสังกัด "ตอนประกาศ" ถ้าอ่านจาก
 * users.department_id ทุกครั้ง ย้ายหัวหน้าไปแผนกอื่นเมื่อไรประกาศเก่าทั้งหมด
 * จะย้ายตามไปโผล่ให้คนแผนกใหม่เห็นเงียบ ๆ
 *
 * restrictOnDelete ทั้งสอง FK ด้วยเหตุผลเดียวกับ workspace_boards: ผู้ใช้ถูกลบแบบ
 * soft delete เสมอ และ DepartmentController::destroy() ดักไว้ก่อนแล้วว่าห้ามลบแผนก
 * ที่ยังมีข้อมูลผูกอยู่ ระดับฐานข้อมูลจึงย้ำกติกาเดียวกัน
 *
 * ทำไมวันที่เป็น date ไม่ใช่ datetime
 * ---------------------------------------------------------------
 * starts_on / ends_on คือ "วันตามปฏิทินไทย" แบบเดียวกับ work_logs.work_date
 * สถานะ รอแสดง/กำลังแสดง/หมดอายุ คำนวณจากการเทียบกับวันนี้ตามเวลาไทย
 * (App\Support\AnnouncementDesign) จึงไม่เก็บคอลัมน์สถานะ และไม่ต้องมี cron คอยเปลี่ยน
 *
 * ค่า audience เป็น string literal ตามกฎ CLAUDE.md ห้าม import ค่าคงที่เข้ามา
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();

            // 'department' = เฉพาะแผนกของผู้สร้าง, 'all' = ทุกแผนก
            $table->string('audience', 20)->default('department');
            $table->string('title', 160);
            $table->text('body');
            $table->date('starts_on');
            // NULL = แสดงไปเรื่อย ๆ จนกว่าผู้สร้างจะลบ
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['audience', 'starts_on', 'ends_on'], 'announcements_audience_window_index');
            $table->index(['department_id', 'starts_on'], 'announcements_department_start_index');
            $table->index('created_by', 'announcements_creator_index');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('announcements') && DB::table('announcements')->exists()) {
            throw new RuntimeException(
                'announcements ยังมีประกาศอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('announcements');
    }
};
