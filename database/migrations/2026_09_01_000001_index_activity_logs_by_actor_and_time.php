<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ดัชนีสำหรับตัวกรองหลักของหน้า Audit Log
 *
 * ตารางมีดัชนี ['action', 'created_at'] อยู่แล้ว แต่หน้าใหม่กรองด้วย "ผู้ทำรายการ + ช่วงเวลา"
 * เป็นหลัก ซึ่งดัชนีเดิมช่วยไม่ได้ และการเพิ่มบันทึกการเข้าออกระบบจะทำให้ตารางนี้โตเร็วขึ้นมาก
 */
return new class extends Migration
{
    private const INDEX = 'activity_logs_user_id_created_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        if (Schema::hasIndex('activity_logs', self::INDEX)) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], self::INDEX);
        });
    }

    /**
     * ถอนเฉพาะดัชนี ไม่มีการลบหรือย่อข้อมูลใด จึงย้อนกลับได้อย่างปลอดภัย
     */
    public function down(): void
    {
        if (! Schema::hasTable('activity_logs') || ! Schema::hasIndex('activity_logs', self::INDEX)) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
