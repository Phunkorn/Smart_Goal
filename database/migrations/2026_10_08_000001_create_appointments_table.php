<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * นัดหมายส่วนตัวของผู้ใช้ — คนละ domain กับการประชุม
 *
 * ทำไมไม่ใช้ตาราง meetings เดิม
 * ---------------------------------------------------------------
 * การประชุมมีผู้เข้าร่วมและมีรอบการตอบรับของแต่ละคน (meeting_attendees +
 * meeting_attendee_response_events) นัดหมายไม่มีผู้ถูกเชิญเลย มีแต่ผู้บันทึก
 * การยัดนัดหมายลง meetings จะทำให้ทุกคิวรีของ RSVP ต้องกรองแถวที่ไม่มีผู้เข้าร่วม
 * ออกตลอดไป และ MeetingPolicy จะต้องตอบคำถามที่ไม่เกี่ยวกับการประชุม
 *
 * ทำไมวันที่แยกจากเวลา
 * ---------------------------------------------------------------
 * นัดหมายคือ "วันตามปฏิทินไทย" แบบเดียวกับ announcements.starts_on และ
 * work_logs.work_date ไม่ใช่ช่วงเวลาที่ข้ามวันได้เหมือน meetings.starts_at
 * เก็บเป็น date + time จึงอ่านตรงกับที่ผู้ใช้กรอก และไม่ต้องแปลงโซนเวลา
 * ตอนถามว่า "นัดหมายของวันที่เท่าไร" (ดู CLAUDE.md หัวข้อ Timezone)
 *
 * ends_at_time ไม่บังคับ เพราะนัดหมายหลายอย่างรู้แต่เวลาเริ่ม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('appointment_date')->index();
            $table->time('starts_at_time');
            $table->time('ends_at_time')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_by', 'appointment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
