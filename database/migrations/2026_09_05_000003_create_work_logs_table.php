<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกงานประจำวัน — งานปฏิบัติการรายวันที่ไม่ใช่งานโครงการ
 * (งานประจำ / งานแทรก / งานนอกสถานที่)
 *
 * แยกจาก work_orders โดยตั้งใจ เพราะธรรมชาติของงานต่างกัน งานโครงการมีเป้าหมาย
 * และจุดจบ ผ่าน state machine job_status พร้อมขั้นตอนอนุมัติ ส่วนบันทึกงานประจำวัน
 * เป็นการบันทึกว่า "วันนี้เวลาหมดไปกับอะไร" การยัดรวมกันจะทำให้ตัวเลข KPI ของ
 * โครงการเพี้ยน และทำให้ state machine ของงานโครงการซับซ้อนขึ้นโดยไม่จำเป็น
 * การเชื่อมกับโครงการ/งานเป็น optional (nullable FK) สำหรับกรณีที่เกี่ยวข้องกันจริง
 *
 * หมายเหตุเรื่องเวลา:
 * - work_date เป็นคอลัมน์ของตัวเอง ไม่ derive จาก started_at เพราะ (1) รายการที่
 *   ไม่ระบุเวลาเลยก็มีได้ (2) การ group ตามวันของกรุงเทพต้องไม่แปลง timezone
 *   ทีละแถวใน SQL และ (3) งานข้ามคืน 22:30–01:15 ต้องนับเป็นวันที่ "เริ่ม"
 * - duration_minutes เก็บค่าจริงแทนการคำนวณสดจาก started_at/ended_at เพราะหน้า
 *   สรุปและรายงานต้อง SUM() เวลา ถ้าคำนวณสดต้องใช้ฟังก์ชันวันที่ของ SQL ซึ่ง
 *   ฐานข้อมูลทดสอบเป็น SQLite แต่ production เป็น MySQL และยังรองรับกรณี
 *   "ทำ backup 30 นาที" ที่จำเวลานาฬิกาไม่ได้ ค่านี้เขียนโดย WorkLogService เท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('work_log_category_id')->nullable()->constrained('work_log_categories')->nullOnDelete();
            $table->foreignId('work_log_template_id')->nullable()->constrained('work_log_templates')->nullOnDelete();
            $table->foreignId('work_order_list_id')->nullable()->constrained('work_order_lists')->nullOnDelete();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('kind', 20)->default('routine');
            $table->string('status', 20)->default('open');
            $table->string('source', 20)->default('manual');
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('requester_name', 120)->nullable();
            $table->date('work_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedBigInteger('open_timer_owner_id')->nullable();
            $table->timestamp('auto_closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // work_orders ใช้ job_id เป็น primary key ไม่ใช่ id จึงต้องประกาศ FK เอง
            // foreignId()->constrained() จะเดาเป็น work_orders.id ซึ่งไม่มีอยู่จริง
            $table->foreign('job_id')->references('job_id')->on('work_orders')->nullOnDelete();

            $table->index(['user_id', 'work_date'], 'work_logs_user_day_index');
            $table->index(['department_id', 'work_date'], 'work_logs_department_day_index');
            $table->index(['work_date', 'kind'], 'work_logs_day_kind_index');

            // กันงานประจำถูกสร้างซ้ำในวันเดียวกัน ไม่ว่าจะมาจาก page load, artisan
            // command หรือสองแท็บพร้อมกัน template_id ที่เป็น NULL (บันทึกที่ผู้ใช้
            // สร้างเอง) ไม่ชนกันเพราะ NULL ไม่ถือว่าซ้ำทั้งบน MySQL และ SQLite
            $table->unique(['work_log_template_id', 'user_id', 'work_date'], 'work_logs_template_day_unique');

            // บังคับ "หนึ่งคนจับเวลาได้ทีละงานเดียว" ที่ระดับฐานข้อมูล เพราะการเช็ค
            // ในโค้ดอย่างเดียวถูก race ได้เมื่อกดจากสองแท็บพร้อมกัน คอลัมน์นี้เท่ากับ
            // user_id ขณะจับเวลา และเป็น NULL เมื่อหยุดแล้ว (NULL จึงไม่ชนกัน)
            $table->unique('open_timer_owner_id', 'work_logs_open_timer_unique');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_logs') && DB::table('work_logs')->exists()) {
            throw new RuntimeException(
                'work_logs ยังมีบันทึกงานอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_logs');
    }
};
