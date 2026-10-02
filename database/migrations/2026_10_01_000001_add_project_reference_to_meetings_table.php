<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผูกการประชุมกับโปรเจกต์/งานหลักได้แบบไม่บังคับ เพื่อให้รู้ว่าการประชุมใบนี้
 * เกี่ยวกับโปรเจกต์หรืองานอะไร โดยการประชุมส่วนใหญ่ยังไม่ต้องผูกกับโปรเจกต์ใดเลยได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            if (! Schema::hasColumn('meetings', 'work_order_list_id')) {
                $table->unsignedBigInteger('work_order_list_id')->nullable()->after('location')->index();
            }

            if (! Schema::hasColumn('meetings', 'work_order_id')) {
                $table->unsignedBigInteger('work_order_id')->nullable()->after('work_order_list_id')->index();
            }
        });

        // SQLite ในเทสต์ไม่รองรับการเพิ่ม foreign key ให้ตารางที่มีอยู่แล้ว (ดู add_parent_job_id_to_work_orders_table)
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('meetings', function (Blueprint $table): void {
                $table->foreign('work_order_list_id')->references('id')->on('work_order_lists')->nullOnDelete();
                $table->foreign('work_order_id')->references('job_id')->on('work_orders')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['work_order_list_id']);
                $table->dropForeign(['work_order_id']);
            }

            $table->dropColumn(['work_order_list_id', 'work_order_id']);
        });
    }
};
