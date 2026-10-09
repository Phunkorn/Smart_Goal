<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประวัติการตอบรับเข้าร่วมนัดหมายของผู้ร่วมแต่ละคน แบบเพิ่มได้แต่ไม่แก้ไข
 *
 * เหตุผลเดียวกับ meeting_attendee_response_events: appointment_attendees เป็น pivot
 * ที่ไม่มีคอลัมน์ id ให้ activity_logs ชี้ถึง และผู้สร้างนัดหมาย "เชิญอีกครั้ง" ได้ไม่จำกัด
 * สถานะปัจจุบันบน pivot จึงถูกเขียนทับเรื่อย ๆ ตารางนี้คือที่เดียวที่ลำดับเหตุการณ์ยังอยู่ครบ
 *
 * ชื่อ index ต้องตั้งเอง ห้ามปล่อยให้ Laravel ตั้งให้
 * ---------------------------------------------------------------
 * ชื่อที่ Laravel จะตั้งให้คือ
 * appointment_attendee_response_events_appointment_id_user_id_occurred_at_index
 * ยาว 76 ตัวอักษร เกินเพดาน 64 ของ MySQL จะถูกปฏิเสธด้วย error 1059
 *
 * up() ถูกเขียนให้รันซ้ำได้ เพราะ MySQL ไม่มี transactional DDL ถ้าเคยตายกลางทาง
 * จะถูกเติมเฉพาะส่วนที่ยังขาด โดยไม่แตะข้อมูลเดิมแม้แถวเดียว
 */
return new class extends Migration
{
    private const TABLE = 'appointment_attendee_response_events';

    private const OCCURRENCE_INDEX = 'appointment_attendee_events_appt_user_occurred_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // ผู้ลงมือ: ผู้ร่วมเองเมื่อตอบรับ/ปฏิเสธ และผู้สร้างนัดหมายเมื่อเชิญหรือเชิญอีกครั้ง
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 20);
                $table->text('decline_reason')->nullable();
                $table->timestamp('occurred_at');

                $table->index(['appointment_id', 'user_id', 'occurred_at'], self::OCCURRENCE_INDEX);
            });

            return;
        }

        if (! Schema::hasIndex(self::TABLE, self::OCCURRENCE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['appointment_id', 'user_id', 'occurred_at'], self::OCCURRENCE_INDEX);
            });
        }
    }

    public function down(): void
    {
        // ประวัติการตอบกลับกู้คืนจากที่อื่นไม่ได้ จึงต้องหยุดไว้แทนที่จะลบเงียบ ๆ
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'ไม่สามารถถอยกลับ migration นี้ได้ มีประวัติการตอบรับเข้าร่วมนัดหมายบันทึกไว้แล้ว'
            );
        }

        Schema::dropIfExists(self::TABLE);
    }
};
