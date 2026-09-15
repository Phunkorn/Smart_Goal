<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผลการปิดงาน และสำเนาของผู้ร่วมงานนอกสถานที่
 *
 * has_issue / issue_details
 *   ตอนกดเสร็จงาน ผู้ใช้เลือกได้ว่า "เสร็จสิ้น" หรือ "พบปัญหา" พร้อมรายละเอียด
 *   เก็บแยกจาก late_completion_reason เพราะงานที่เสร็จตรงเวลาก็พบปัญหาได้
 *
 * shared_from_work_log_id
 *   งานนอกสถานที่ที่ไปกันหลายคน คนสร้างเพิ่มเพื่อนร่วมงานได้ในครั้งเดียว
 *   แต่ละคนได้รายการของตัวเองในบันทึกงาน และปิดงานของตัวเองแยกกัน
 *   คอลัมน์นี้ชี้กลับไปที่รายการของคนสร้าง เพื่อซิงก์และลบสำเนาที่ยังไม่ปิดตามต้นฉบับ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_logs', function (Blueprint $table): void {
            $table->boolean('has_issue')->default(false)->after('late_completion_reason');
            $table->text('issue_details')->nullable()->after('has_issue');
            $table->foreignId('shared_from_work_log_id')->nullable()->after('work_log_template_id')
                ->constrained('work_logs')->nullOnDelete();
        });
    }

    /**
     * ย้อนแล้วผลการปิดงานและความเชื่อมโยงของสำเนาจะหายทั้งหมด จึงปฏิเสธเมื่อมีข้อมูลอยู่แล้ว
     */
    public function down(): void
    {
        $hasData = DB::table('work_logs')
            ->where(fn ($query) => $query
                ->where('has_issue', true)
                ->orWhereNotNull('issue_details')
                ->orWhereNotNull('shared_from_work_log_id'))
            ->exists();

        if ($hasData) {
            throw new RuntimeException(
                'work_logs มีผลการปิดงานแบบพบปัญหาหรือสำเนาของผู้ร่วมงานอยู่แล้ว — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::table('work_logs', function (Blueprint $table): void {
            $table->dropForeign(['shared_from_work_log_id']);
            $table->dropColumn(['has_issue', 'issue_details', 'shared_from_work_log_id']);
        });
    }
};
