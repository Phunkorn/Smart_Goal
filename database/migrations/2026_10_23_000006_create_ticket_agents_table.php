<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สิทธิ์ agent ของ Smart Ticket รายบุคคล — เป็น "override" เท่านั้น
 *
 * ค่าตั้งต้นมาจาก departments.ticket_responsible: สมาชิก active ของแผนกที่รับผิดชอบ
 * เป็น agent โดยไม่ต้องมีแถวที่นี่ สมาชิกใหม่ของแผนกจึงได้สิทธิ์ทันที
 * แถวที่นี่มีไว้เมื่อ admin ปิดสิทธิ์บางคนในแผนกนั้น หรือเปิดให้คนจากแผนกอื่น
 * กติกาทั้งหมดอ่านจาก App\Support\TicketAgentAccess ที่เดียว
 *
 * is_enabled เป็น null ได้ = "ตามแผนก" — ใช้เมื่อ admin ปิดการแจ้งเตือนของ agent ที่ได้สิทธิ์
 * จากแผนก โดยไม่ตรึงสิทธิ์ agent ของคนนั้นไว้ ถ้าภายหลังแผนกถูกปิด คนนั้นก็หมดสิทธิ์ตามแผนก
 *
 * user_id เป็น cascade ต่างจากตารางอื่นของ Ticket เพราะแถวนี้คือค่าตั้งค่า ไม่ใช่ประวัติ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_enabled')->nullable();
            $table->boolean('receives_alerts')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_agents') && DB::table('ticket_agents')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีการตั้งค่าสิทธิ์เจ้าหน้าที่ Ticket บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('ticket_agents');
    }
};
