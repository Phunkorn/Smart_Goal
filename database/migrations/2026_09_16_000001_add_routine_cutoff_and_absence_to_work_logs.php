<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ปิดรอบงานประจำ 17:00 และการระบุว่าผู้ร่วมงานไม่มา
 *
 * work_logs.status ได้ค่าใหม่สามค่า (คอลัมน์เป็น string อยู่แล้ว ไม่ต้องแก้ชนิด):
 *   not_started — ถึง 17:00 แล้วยังไม่กดเริ่ม
 *   unfinished  — กดเริ่มแล้วแต่ถึง 17:00 ยังไม่กดเสร็จ
 *   absent      — ผู้ร่วมงานคนอื่นระบุตอนกดเริ่มว่าคนนี้ไม่มา
 *
 * เหตุผลแยกคอลัมน์ให้ชัด: not_started / absent ใช้ skip_reason (กลุ่ม "ไม่ได้ทำ")
 * ส่วน unfinished ใช้ unfinished_reason ห้ามรวมกัน
 *
 * work_log_templates.accountable_from
 *   วันแรกที่ต้องรับผิดชอบระบุเหตุผลย้อนหลัง แม่แบบที่มีอยู่ก่อน migration นี้ถูกตั้งเป็นเวลาที่รัน
 *   เพื่อไม่ให้ทุกคนต้องตอบเหตุผลย้อนหลังของวันก่อนที่กติกานี้จะมีอยู่ แม่แบบใหม่ปล่อยว่าง (ใช้ created_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_logs', function (Blueprint $table): void {
            $table->string('unfinished_reason', 500)->nullable()->after('skip_reason');
            $table->foreignId('absent_marked_by')->nullable()->after('unfinished_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('absent_marked_at')->nullable()->after('absent_marked_by');
            $table->timestamp('cutoff_closed_at')->nullable()->after('absent_marked_at');
            $table->timestamp('explained_at')->nullable()->after('cutoff_closed_at');
        });

        Schema::table('work_log_templates', function (Blueprint $table): void {
            $table->timestamp('accountable_from')->nullable()->after('is_active');
        });

        DB::table('work_log_templates')->update(['accountable_from' => now()]);
    }

    /**
     * ย้อนแล้วสถานะปิดรอบ เหตุผล และบันทึกว่าใครไม่มาจะหายทั้งหมด จึงปฏิเสธเมื่อมีข้อมูลแล้ว
     */
    public function down(): void
    {
        $hasData = DB::table('work_logs')
            ->where(fn ($query) => $query
                ->whereIn('status', ['not_started', 'unfinished', 'absent'])
                ->orWhereNotNull('unfinished_reason')
                ->orWhereNotNull('absent_marked_by')
                ->orWhereNotNull('explained_at'))
            ->exists();

        if ($hasData) {
            throw new RuntimeException(
                'work_logs มีรายการปิดรอบ/ไม่มา/เหตุผลย้อนหลังอยู่แล้ว — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::table('work_logs', function (Blueprint $table): void {
            $table->dropForeign(['absent_marked_by']);
            $table->dropColumn(['unfinished_reason', 'absent_marked_by', 'absent_marked_at', 'cutoff_closed_at', 'explained_at']);
        });

        Schema::table('work_log_templates', function (Blueprint $table): void {
            $table->dropColumn('accountable_from');
        });
    }
};
