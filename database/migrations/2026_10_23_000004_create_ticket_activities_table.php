<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline ของ Ticket — เพิ่มได้อย่างเดียว ไม่แก้ไขย้อนหลัง
 *
 * ทุกการกระทำสำคัญ (สร้าง เข้าร่วม ออก เปลี่ยนสถานะ บันทึกงาน ตั้งผู้ประสานงาน) เป็นหนึ่งแถว
 * เปิดงานใหม่หลังปิดจึงไม่ทำให้ประวัติเดิมหาย
 *
 * แยกจาก activity_logs เพราะนั่นคือ audit ของผู้ดูแลระบบที่ admin ทุกคนอ่านได้และถูก prune
 * ส่วนนี่คือข้อมูลของงานที่ผู้แจ้งและทีม IT ต้องเห็นตลอดอายุ Ticket
 *
 * user_id เป็น null ได้ทั้งเมื่อผู้แจ้งเป็นบุคคลภายนอก และเมื่อบัญชีถูกลบถาวร
 * ชื่อที่แสดงจึงอ่านจาก user_name (snapshot)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 150);
            $table->string('type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_activities') && DB::table('ticket_activities')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีประวัติการดำเนินงานของ Ticket บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('ticket_activities');
    }
};
