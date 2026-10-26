<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รายการสาขา/สถานที่ของ Smart Ticket — admin เป็นผู้จัดการ
 *
 * ไม่มีการลบแถว มีแต่ปิดใช้งาน (is_active) เพราะ Ticket เก่าอ้างถึงสาขาเหล่านี้
 * และรายงานย้อนหลังอ่านชื่อจาก snapshot บน tickets.location_name อยู่แล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // รายการสาขาเป็นข้อมูลที่ admin กรอกเอง กู้คืนจากที่อื่นไม่ได้
        if (Schema::hasTable('ticket_locations') && DB::table('ticket_locations')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีรายการสาขาของ Smart Ticket บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('ticket_locations');
    }
};
