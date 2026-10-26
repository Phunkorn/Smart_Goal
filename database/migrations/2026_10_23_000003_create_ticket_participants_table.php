<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เจ้าหน้าที่ IT ที่เข้าร่วม Ticket — หลายคนต่อหนึ่ง Ticket ไม่มีผู้ถือเพียงคนเดียว
 *
 * unique (ticket_id, user_id) กันแถวซ้ำเมื่อกดเข้าร่วมพร้อมกัน การออกจาก Ticket
 * ไม่ลบแถว แต่ตั้ง left_at เพื่อให้ KPI รายคนยังนับ Ticket ที่เคยร่วมได้
 *
 * ticket_id เป็น restrictOnDelete — ระบบไม่มีการลบ Ticket การลบที่หลุดมาจะถูกกันไว้
 * แทนที่จะพาประวัติการเข้าร่วมหายไปด้วย
 * user_id เป็น nullOnDelete และเก็บ user_name เป็น snapshot ด้วยเหตุผลเดียวกับ tickets
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 150);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_participants') && DB::table('ticket_participants')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีประวัติการเข้าร่วม Ticket บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('ticket_participants');
    }
};
