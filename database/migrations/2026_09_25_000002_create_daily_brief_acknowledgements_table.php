<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * บันทึกว่าผู้ใช้กด "รับทราบ" สรุปประจำวันของวันไหนแล้ว
 *
 * ต้องเก็บในฐานข้อมูล ไม่ใช่ session เพราะกติกาคือ "วันนั้นไม่แสดงซ้ำแม้ Logout
 * แล้ว Login ใหม่" ส่วน session ถูก invalidate ตอน logout เสมอ
 *
 * brief_date คือวันตามปฏิทินไทย (App\Support\TodayWorkspace::businessNow())
 * unique (user_id, brief_date) ทำให้การกดซ้ำหรือสองแท็บยิงพร้อมกันได้แถวเดียวเสมอ
 *
 * แยกเป็นตารางของตัวเองแทนการเพิ่มคอลัมน์ใน users เพื่อไม่แตะตารางเดิม และเก็บ
 * ประวัติการรับทราบไว้ ซึ่งใช้ตัดสินป้าย "ใหม่" ของประกาศด้วย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_brief_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('brief_date');
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['user_id', 'brief_date'], 'daily_brief_ack_user_date_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('daily_brief_acknowledgements') && DB::table('daily_brief_acknowledgements')->exists()) {
            throw new RuntimeException(
                'daily_brief_acknowledgements ยังมีข้อมูลการรับทราบอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('daily_brief_acknowledgements');
    }
};
