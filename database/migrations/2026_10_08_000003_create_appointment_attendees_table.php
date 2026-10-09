<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ผู้ร่วมนัดหมาย พร้อมสถานะตอบรับของแต่ละคน
 *
 * โครงสร้างสะท้อน meeting_attendees แบบ 1:1 โดยตั้งใจ แต่เป็นคนละตาราง เพราะนัดหมาย
 * กับการประชุมเป็นคนละ domain การใช้ตารางร่วมจะบังคับให้ทุกคิวรีต้องกรองว่าแถวนี้
 * เป็นของอะไรตลอดไป และทำให้ลบนัดหมายแล้วกระทบ cascade ของการประชุม
 *
 * ชื่อ index ตั้งเองทั้งหมด ห้ามปล่อยให้ Laravel ตั้งให้
 * ---------------------------------------------------------------
 * Laravel ตั้งชื่อ composite index เป็น <table>_<col1>_<col2>_index ซึ่งกับชื่อตารางยาว
 * ของโปรเจกต์นี้จะเกินเพดาน 64 ตัวอักษรของ MySQL แล้ว migration จะตายกลางทาง
 * (เคยเกิดกับ meeting_attendee_response_events มาแล้ว)
 */
return new class extends Migration
{
    private const TABLE = 'appointment_attendees';

    private const UNIQUE_INDEX = 'appointment_attendees_appointment_user_unique';

    private const STATUS_INDEX = 'appointment_attendees_appointment_status_index';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // การถูกเพิ่มเข้านัดหมายไม่เท่ากับตอบรับแล้ว แถวใหม่จึงเริ่มที่ pending เสมอ
            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'user_id'], self::UNIQUE_INDEX);
            $table->index(['appointment_id', 'status'], self::STATUS_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
