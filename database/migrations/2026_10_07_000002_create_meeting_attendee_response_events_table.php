<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประวัติการตอบรับเข้าร่วมประชุมของผู้เข้าร่วมแต่ละคน แบบเพิ่มได้แต่ไม่แก้ไข
 *
 * ต้องเป็นตารางของตัวเองไม่ใช่ activity_logs เพราะ activity_logs ชี้ไปที่แถวเป้าหมายด้วย
 * subject_type + subject_id แต่ meeting_attendees เป็น pivot ที่ไม่มีคอลัมน์ id ให้ชี้
 * (ดู create_meeting_attendees_table) และผู้สร้างประชุม "เชิญอีกครั้ง" ได้ไม่จำกัดครั้ง
 * สถานะปัจจุบันบน pivot จึงถูกเขียนทับเรื่อย ๆ ตารางนี้คือที่เดียวที่ลำดับเหตุการณ์ยังอยู่ครบ
 */
return new class extends Migration
{
    private const TABLE = 'meeting_attendee_response_events';

    /**
     * ชื่อ index ต้องตั้งเอง ห้ามปล่อยให้ Laravel ตั้งให้
     *
     * ชื่ออัตโนมัติของคอลัมน์ชุดนี้คือ
     * meeting_attendee_response_events_meeting_id_user_id_occurred_at_index
     * ซึ่งยาว 69 ตัวอักษร เกินเพดานชื่อ identifier ของ MySQL ที่ 64 ตัวอักษร
     * MySQL จึงปฏิเสธด้วย error 1059 ตอนสร้าง index
     *
     * SQLite ที่ชุดทดสอบใช้ไม่บังคับเพดานนี้ ความต่างจึงไม่โผล่จนขึ้นฐานข้อมูลจริง
     */
    private const OCCURRENCE_INDEX = 'meeting_attendee_events_meeting_user_occurred_index';

    public function up(): void
    {
        /*
         * ตารางอาจมีอยู่แล้วบนฐานข้อมูลจริง และต้องไม่ถูกสร้างทับ
         *
         * รอบแรกที่ migration นี้ทำงาน CREATE TABLE กับ foreign key ผ่านไปแล้ว แต่คำสั่ง
         * สร้าง index ล้มเหลวเพราะชื่อยาวเกินเพดาน (ดู OCCURRENCE_INDEX) MySQL ไม่มี
         * transaction ครอบ DDL ตารางจึงค้างอยู่โดย migration ไม่ถูกบันทึกว่ารันแล้ว
         * การรันซ้ำจึงไปเจอ "table already exists" ซึ่งเป็นอาการ ไม่ใช่สาเหตุ
         *
         * ที่นี่จึงแยกสองเส้นทาง: ฐานข้อมูลใหม่สร้างครบในครั้งเดียว ส่วนฐานข้อมูลที่ค้าง
         * อยู่ครึ่งทางจะถูกเติมเฉพาะ index ที่ยังขาด โดยไม่แตะข้อมูลเดิมแม้แถวเดียว
         */
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // ผู้ลงมือ: ผู้เข้าร่วมเองเมื่อตอบรับ/ปฏิเสธ และผู้สร้างประชุมเมื่อเชิญหรือเชิญอีกครั้ง
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 20);
                $table->text('decline_reason')->nullable();
                $table->timestamp('occurred_at');

                $table->index(['meeting_id', 'user_id', 'occurred_at'], self::OCCURRENCE_INDEX);
            });

            return;
        }

        if (! Schema::hasIndex(self::TABLE, self::OCCURRENCE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['meeting_id', 'user_id', 'occurred_at'], self::OCCURRENCE_INDEX);
            });
        }
    }

    public function down(): void
    {
        /*
         * ตารางนี้เป็นประวัติที่เพิ่มได้แต่ไม่แก้ไข และเป็นที่เดียวที่ลำดับการตอบรับยังอยู่ครบ
         * (สถานะบน pivot ถูกเขียนทับทุกครั้งที่เชิญอีกครั้ง) การ rollback จึงต้องไม่ลบ
         * ประวัติจริงไปเงียบ ๆ ถ้าต้องการลบจริงให้ลบข้อมูลด้วยคำสั่งที่ตั้งใจก่อน
         */
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'ตาราง '.self::TABLE.' มีประวัติการตอบรับอยู่แล้ว การ rollback จะลบประวัตินั้นทิ้ง '
                .'ถ้ายืนยันว่าต้องการลบ ให้ล้างข้อมูลในตารางนี้ก่อนแล้วจึง rollback อีกครั้ง'
            );
        }

        Schema::dropIfExists(self::TABLE);
    }
};
