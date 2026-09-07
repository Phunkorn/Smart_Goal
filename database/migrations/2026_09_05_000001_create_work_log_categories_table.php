<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * หมวดงาน (เช่น IT Support, ซ่อมบำรุง) ของ "บันทึกงานประจำวัน"
 *
 * เก็บเป็นตาราง lookup ไม่ใช่ enum/string ในตาราง work_logs เพราะหมวดงานเป็น
 * เรื่องเฉพาะองค์กรและจะเพิ่มเรื่อย ๆ ถ้าเป็น enum จะต้องเขียน migration และ
 * deploy ใหม่ทุกครั้งที่อยากเพิ่มหมวดหนึ่งหมวด ซึ่งไม่คุ้มบน shared hosting
 *
 * แถวเริ่มต้นถูก seed ใน up() ด้วยค่า literal (ไม่ import ค่าคงที่จาก Model
 * ตามกฎของ CLAUDE.md) เพื่อให้ production ได้ข้อมูลตั้งต้นโดยไม่ต้องรัน seeder
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_log_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('tone', 20)->default('gray');
            $table->string('icon', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();

        DB::table('work_log_categories')->insert([
            ['name' => 'IT Support', 'tone' => 'blue', 'icon' => 'bi-headset', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ดูแลระบบ', 'tone' => 'purple', 'icon' => 'bi-hdd-network', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ซ่อมบำรุง', 'tone' => 'amber', 'icon' => 'bi-tools', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ติดตั้งอุปกรณ์', 'tone' => 'teal', 'icon' => 'bi-pc-display', 'sort_order' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ประชุม/อบรม', 'tone' => 'cyan', 'icon' => 'bi-people', 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'งานเอกสาร', 'tone' => 'gray', 'icon' => 'bi-file-earmark-text', 'sort_order' => 6, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'อื่น ๆ', 'tone' => 'gray', 'icon' => 'bi-three-dots', 'sort_order' => 99, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     *
     * ตรวจเฉพาะแถวที่ "ไม่ใช่ค่าตั้งต้น" เพราะ up() เป็นคนใส่ค่าตั้งต้นเองอยู่แล้ว
     * การย้อน migration ที่ยังไม่มีใครเพิ่มหมวดของตัวเองจึงไม่ถือว่าเสียข้อมูล
     */
    public function down(): void
    {
        if (! Schema::hasTable('work_log_categories')) {
            return;
        }

        $seeded = ['IT Support', 'ดูแลระบบ', 'ซ่อมบำรุง', 'ติดตั้งอุปกรณ์', 'ประชุม/อบรม', 'งานเอกสาร', 'อื่น ๆ'];

        if (DB::table('work_log_categories')->whereNotIn('name', $seeded)->exists()) {
            throw new RuntimeException(
                'work_log_categories มีหมวดงานที่ผู้ใช้เพิ่มเองอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_log_categories');
    }
};
