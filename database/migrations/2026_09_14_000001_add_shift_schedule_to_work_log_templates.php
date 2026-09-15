<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ตารางเวรของงานประจำ
 *
 * เดิมงานประจำหนึ่งรายการมีชุดวันในสัปดาห์ (weekday_mask) ชุดเดียว และทุกคนในงานได้รายการ
 * ทุกวันที่ถึงกำหนดเหมือนกัน งานที่สลับเวรกันคนละสัปดาห์ (เช่นเช็คคอม Call Center)
 * จึงต้องเข้าไปเอาวันออกเองทุกสัปดาห์
 *
 * schedule_type:
 *   weekly — แบบเดิม ทุกคนในงานได้รายการตาม weekday_mask (แม่แบบเดิมทั้งหมดเป็นแบบนี้)
 *   dates  — ตามตารางเวร ได้รายการเฉพาะคนที่มีแถวใน work_log_template_shifts ของวันนั้น
 *
 * หนึ่งวันลงได้หลายคน (เช็คสองคน) จึงให้ unique ที่ (แม่แบบ, วัน, คน)
 * ค่าในไฟล์นี้เป็นค่าคงที่ ณ ตอนเขียน migration ไม่อ้างถึงค่าคงที่ของ Model
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_log_templates', function (Blueprint $table): void {
            $table->string('schedule_type', 20)->default('weekly')->after('kind');
        });

        Schema::create('work_log_template_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_log_template_id')->constrained('work_log_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_log_template_id', 'work_date', 'user_id'], 'work_log_template_shifts_unique');
            $table->index(['user_id', 'work_date'], 'work_log_template_shifts_user_day_index');
        });
    }

    /**
     * ย้อนได้เฉพาะเมื่อยังไม่มีการใช้ตารางเวรจริง ไม่ลบเวรหรือเปลี่ยนแม่แบบกลับเงียบ ๆ
     */
    public function down(): void
    {
        if (Schema::hasTable('work_log_template_shifts') && DB::table('work_log_template_shifts')->exists()) {
            throw new RuntimeException(
                'work_log_template_shifts ยังมีตารางเวรอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        if (Schema::hasColumn('work_log_templates', 'schedule_type')
            && DB::table('work_log_templates')->where('schedule_type', '!=', 'weekly')->exists()) {
            throw new RuntimeException(
                'มีงานประจำแบบตารางเวรอยู่ — ย้อนแล้วงานเหล่านี้จะกลายเป็นทำทุกวัน ต้องจัดการข้อมูลก่อน'
            );
        }

        Schema::dropIfExists('work_log_template_shifts');

        Schema::table('work_log_templates', function (Blueprint $table): void {
            $table->dropColumn('schedule_type');
        });
    }
};
