<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผู้ร่วมงานของบันทึกงานประจำวัน
 *
 * งานปฏิบัติการหลายอย่างทำกันมากกว่าหนึ่งคนโดยธรรมชาติ เช่น ตรวจสอบคอมพิวเตอร์
 * ตอนเช้าที่ขึ้นไปกันสองคน การให้ทั้งคู่ต้องพิมพ์บันทึกของตัวเองแยกกันทำให้เสีย
 * เวลาซ้ำซ้อน และทำให้รายงานนับงานเดียวกันเป็นสองรายการ
 *
 * ต่างจาก work_order_collaborators ของงานโครงการตรงที่ไม่มีสถานะรออนุมัติ
 * เพราะบันทึกงานประจำวันเป็นการบันทึกสิ่งที่เกิดขึ้นไปแล้ว ไม่ใช่การมอบหมายงาน
 * การเพิ่มคนจึงมีผลทันที ตามเจตนาที่ต้องการลดขั้นตอนให้สั้นที่สุด
 *
 * เงื่อนไขว่าใครถูกเพิ่มได้ (แผนกเดียวกัน บัญชีเปิดใช้งาน role = user) บังคับที่
 * App\Services\WorkLogParticipantService ซึ่งอิงกติกาเดียวกับ
 * CollaboratorInvitationService ของงานโครงการ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_log_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_log_id')->constrained('work_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // คนเดียวกันถูกเพิ่มซ้ำในบันทึกเดียวไม่ได้
            $table->unique(['work_log_id', 'user_id'], 'work_log_participants_unique');
            $table->index('user_id');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_log_participants') && DB::table('work_log_participants')->exists()) {
            throw new RuntimeException(
                'work_log_participants ยังมีผู้ร่วมงานอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_log_participants');
    }
};
