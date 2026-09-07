<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * แม่แบบงานประจำ — ระบบใช้สร้างรายการของ "วันนี้" ให้อัตโนมัติ
 * ผู้ใช้จึงไม่ต้องพิมพ์งานเดิมซ้ำทุกเช้า
 *
 * weekday_mask เก็บวันทำงานเป็น bitmask (bit0 = จันทร์ ... bit6 = อาทิตย์)
 * จันทร์–ศุกร์ = 31 เลือกใช้ bitmask แทนตาราง pivot เพราะเป็น boolean คงที่
 * เจ็ดตัวที่อ่านพร้อมกันเสมอทีละแม่แบบ pivot จะเพิ่ม join บนเส้นทางที่ถูกเรียก
 * ทุกครั้งที่เปิดหน้า โดยไม่ได้ความยืดหยุ่นอะไรกลับมา
 *
 * last_materialized_on เป็น cursor ไว้ short-circuit การสร้างรายการซ้ำ
 * ให้เหลือศูนย์ insert เมื่อวันนี้สร้างไปแล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_log_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('work_log_category_id')->nullable()->constrained('work_log_categories')->nullOnDelete();
            $table->string('kind', 20)->default('routine');
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->unsignedTinyInteger('weekday_mask')->default(31);
            $table->time('default_start_time')->nullable();
            $table->unsignedSmallInteger('default_duration_minutes')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('last_materialized_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_log_templates') && DB::table('work_log_templates')->exists()) {
            throw new RuntimeException(
                'work_log_templates ยังมีแม่แบบงานประจำอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_log_templates');
    }
};
