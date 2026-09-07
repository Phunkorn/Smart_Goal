<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ไฟล์แนบของบันทึกงานประจำวัน (รูปหน้างาน ใบเสร็จ ฯลฯ)
 *
 * ใช้ตารางแยกตาม precedent เดิมของระบบ ที่แต่ละโดเมนมีตารางไฟล์แนบของตัวเอง
 * (job_images ของงาน, work_order_list_attachments ของโครงการ) แทนการทำให้
 * job_images.job_id เป็น nullable แล้ว dispatch แบบ polymorphic ซึ่งจะดึงแถวของ
 * บันทึกงานเข้าไปในตารางที่ชุดทดสอบไฟล์แนบของงานอ้างอิงอยู่
 *
 * ไฟล์ถูกเก็บใน private disk และเสิร์ฟผ่าน MediaController เท่านั้น
 * ห้ามสร้าง URL สาธารณะจาก file_path โดยตรง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_log_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_log_id')->constrained('work_logs')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('file_type');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_log_attachments') && DB::table('work_log_attachments')->exists()) {
            throw new RuntimeException(
                'work_log_attachments ยังมีไฟล์แนบอยู่ — ต้องสำรองไฟล์และข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_log_attachments');
    }
};
