<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผู้ร่วมงานของ "แม่แบบงานประจำ"
 *
 * งานที่ต้องทำทุกเช้าหลายอย่างเป็นหน้าที่ของทีม ไม่ใช่ของคนเดียว เช่นการเข้าไป
 * ตรวจเครื่องคอมพิวเตอร์ช่วง 08:30–08:50 ที่ผลัดกันหรือทำด้วยกันสองคน เดิมแม่แบบ
 * เป็นของส่วนตัวล้วน ๆ คนที่ถูกเลือกไว้จึงไม่เห็นอะไรเลยในรายการของตัวเอง และ
 * ต้องมาพิมพ์งานเดิมซ้ำเองทุกวัน
 *
 * ตารางนี้บอกว่า "ใครบ้างที่ต้องได้รายการของแม่แบบนี้ในไทม์ไลน์ของตัวเอง"
 * โดยแต่ละคนยังได้แถวใน work_logs ของตัวเอง (unique index ของ work_logs คือ
 * template + user + วัน อยู่แล้ว) เพราะการยืนยันว่าทำแล้วเป็นเรื่องของแต่ละคน
 * ไม่ใช่การกดแทนกันได้
 *
 * เจ้าของแม่แบบ (work_log_templates.user_id) เป็นคนเดียวที่แก้ไขและลบได้
 * คนที่ถูกเพิ่มเป็นผู้รับงาน ไม่ใช่ผู้ร่วมแก้ไขการตั้งค่า
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_log_template_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_log_template_id')->constrained('work_log_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_log_template_id', 'user_id'], 'work_log_template_participants_unique');
            $table->index('user_id');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_log_template_participants')
            && DB::table('work_log_template_participants')->exists()) {
            throw new RuntimeException(
                'work_log_template_participants ยังมีผู้ร่วมงานอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_log_template_participants');
    }
};
