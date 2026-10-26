<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * แผนกที่รับผิดชอบ Smart Ticket
 *
 * ค่าเริ่มต้นเป็นปิดทุกแผนก และไม่เดาว่าแผนกไหนคือ IT จากชื่อ — admin เลือกเองจาก
 * ข้อมูล departments จริงที่หน้าตั้งค่า ระหว่างที่ยังไม่มีแผนกรับผิดชอบ
 * Ticket ใหม่แจ้ง admin แทน งานจึงไม่หาย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('ticket_responsible')->default(false)->after('department_name');
        });
    }

    public function down(): void
    {
        // ลบคอลัมน์ทั้งที่ admin ตั้งแผนกรับผิดชอบไว้แล้ว = ทีม IT หมดสิทธิ์ agent เงียบ ๆ
        if (DB::table('departments')->where('ticket_responsible', true)->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีแผนกที่ถูกตั้งเป็นผู้รับผิดชอบ Ticket แล้ว');
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('ticket_responsible');
        });
    }
};
