<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * งานย่อยเลิกเป็นแถวข้อความใน work_order_subtasks แล้วกลายเป็นงานจริงที่มีงานแม่
 *
 * การผูกงานย่อยกับ work_orders ทำให้ได้สถานะ ความสำคัญ วันที่ ผู้รับผิดชอบ ผู้ร่วมงาน
 * ไฟล์แนบ และคอมเมนต์จากโครงสร้างเดิมทั้งหมด โดยไม่ต้องสร้างตารางคู่ขนานชุดใหม่
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('work_orders', 'parent_job_id')) {
                $table->unsignedBigInteger('parent_job_id')->nullable()->after('work_order_list_id')->index();
            }

            if (! Schema::hasColumn('work_orders', 'parent_sort_order')) {
                $table->unsignedInteger('parent_sort_order')->default(0)->after('parent_job_id');
            }
        });

        // SQLite ในเทสต์ไม่รองรับการเพิ่ม foreign key ให้ตารางที่มีอยู่แล้ว
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('work_orders', function (Blueprint $table): void {
                $table->foreign('parent_job_id')
                    ->references('job_id')
                    ->on('work_orders')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // ทิ้งคอลัมน์นี้คือทิ้งความสัมพันธ์งานแม่-งานย่อยทั้งหมด งานย่อยจะกลายเป็นงานลอย
        // ในทุกหน้าจอทันที จึงต้องหยุดไว้ก่อนแทนที่จะลบข้อมูลเงียบ ๆ
        if (Schema::hasColumn('work_orders', 'parent_job_id')
            && DB::table('work_orders')->whereNotNull('parent_job_id')->exists()) {
            throw new RuntimeException('ยังมีงานย่อยที่ผูกกับงานแม่อยู่ ย้ายหรือลบงานย่อยก่อนจึงจะย้อน migration นี้ได้');
        }

        Schema::table('work_orders', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['parent_job_id']);
            }

            $table->dropColumn(['parent_job_id', 'parent_sort_order']);
        });
    }
};
