<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รูปภาพที่แนบมากับความคิดเห็นในงาน
 *
 * work_order_updates มีแต่คอลัมน์ note การจะส่งภาพหน้าจอประกอบคำถามจึงต้อง
 * ไปแนบเป็นไฟล์อ้างอิงของงานแทน ซึ่งปนกับเอกสารส่งมอบงานจริงและไม่ได้ผูกกับ
 * บทสนทนาที่กำลังคุยกันอยู่
 *
 * แยกตารางแทนการเพิ่มคอลัมน์ เพราะหนึ่งความคิดเห็นแนบได้หลายรูป และเพื่อให้ใช้
 * รูปแบบเดียวกับตารางไฟล์แนบอื่นทั้งหมดของระบบ (file_path / original_name /
 * file_type / uploaded_by / softDeletes)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_update_attachments')) {
            return;
        }

        Schema::create('work_order_update_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_update_id')
                ->constrained('work_order_updates')
                ->cascadeOnDelete();

            // ความกว้างเท่ากับตารางไฟล์แนบอื่น MIME ของ Office 2007+ ยาวถึง 73 ตัวอักษร
            $table->string('file_path', 255);
            $table->string('original_name', 255);
            $table->string('file_type', 255);
            $table->unsignedBigInteger('byte_size')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ไฟล์ที่ลบต้องกู้คืนได้ 30 วันเหมือนไฟล์แนบทุกชนิดในระบบ
            $table->softDeletes();

            $table->index('work_order_update_id', 'work_order_update_attachments_update_index');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_order_update_attachments')
            && DB::table('work_order_update_attachments')->exists()) {
            throw new RuntimeException(
                'work_order_update_attachments ยังมีรูปที่แนบไว้ในความคิดเห็น — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_order_update_attachments');
    }
};
