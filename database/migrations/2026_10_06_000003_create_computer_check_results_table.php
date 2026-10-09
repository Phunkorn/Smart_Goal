<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผลการตรวจรายเครื่องของหนึ่งรอบ — หนึ่งแถวเท่ากับโต๊ะหนึ่งตัวบนผังห้อง
 *
 * ทำไมเป็น string สามค่า ไม่ใช่ boolean
 * ---------------------------------------------------------------
 * 'pending' (ยังไม่ได้ตรวจ) ต้องแยกออกจาก 'issue' (ตรวจแล้วและมีปัญหา) ให้ชัด
 * ถ้าใช้ boolean ช่องที่ยังไม่ได้ติ๊กกับช่องที่ติ๊กว่าเสียจะอ่านเป็นค่าเดียวกัน
 * แล้วรายงานจะบอกไม่ได้ว่า "ยังตรวจไม่ครบ" ต่างจาก "ตรวจครบแล้วทุกอย่างปกติ"
 * ค่าที่ใช้ได้: 'pending' | 'normal' | 'issue'
 *
 * ทำไมสร้างครบทุกเครื่องตั้งแต่เริ่มรอบ
 * ---------------------------------------------------------------
 * ComputerCheckService::start() สร้างแถว 'pending' ให้ทุกเครื่องที่ใช้งานอยู่
 * ทันทีที่เปิดรอบ แบบตรวจย้อนหลังจึงประกอบจาก "แถวผลของรอบนั้น" ได้โดยตรง
 * ไม่ต้องไปอ่านรายการเครื่องปัจจุบัน — ถ้าภายหลังมีการเพิ่มหรือปิดเครื่อง
 * ใบเก่าก็ยังมีจำนวนเครื่องเท่าเดิมตามวันที่ตรวจจริง (requirement เรื่อง reproducible)
 *
 * ตำแหน่งโต๊ะไม่ถูกคัดลอกมาเก็บที่นี่โดยตั้งใจ ผังมีแหล่งความจริงเดียวคือ
 * computer_check_workstations ตามกฎ "Floor Plan ชุดเดียว" ใน CLAUDE.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_check_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('computer_check_session_id')->constrained('computer_check_sessions')->cascadeOnDelete();
            $table->foreignId('computer_check_workstation_id')->constrained('computer_check_workstations')->cascadeOnDelete();

            $table->string('keyboard_mouse_status', 10)->default('pending');
            $table->string('speaker_status', 10)->default('pending');
            $table->string('microphone_status', 10)->default('pending');
            $table->string('internet_status', 10)->default('pending');
            $table->string('computer_status', 10)->default('pending');

            // รายละเอียดปัญหา เช่น "Microphone — ไม่มีเสียง" บังคับกรอกเมื่อมีอุปกรณ์ใดเป็น issue
            $table->text('note')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            // หนึ่งเครื่องมีผลได้ใบเดียวต่อหนึ่งรอบ — กันการบันทึกซ้ำจากสองแท็บ
            $table->unique(
                ['computer_check_session_id', 'computer_check_workstation_id'],
                'computer_check_results_session_station_unique'
            );
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('computer_check_results') && DB::table('computer_check_results')->exists()) {
            throw new RuntimeException(
                'computer_check_results ยังมีผลการตรวจอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('computer_check_results');
    }
};
