<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * การถูกเพิ่มเข้าประชุมไม่เท่ากับตอบรับแล้ว ผู้เข้าร่วมแต่ละคนจึงต้องมีสถานะตอบกลับของตัวเอง
 * บนแถว pivot เดิม ไม่สร้างตารางผู้เข้าร่วมใบใหม่ซ้ำกับ meeting_attendees ที่มีอยู่แล้ว
 *
 * ประวัติการตอบกลับย้อนหลังอยู่ที่ meeting_attendee_response_events แถวนี้เก็บเฉพาะสถานะปัจจุบัน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table): void {
            if (! Schema::hasColumn('meeting_attendees', 'status')) {
                $table->string('status', 20)->default('pending')->after('user_id');
            }

            if (! Schema::hasColumn('meeting_attendees', 'responded_at')) {
                $table->timestamp('responded_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('meeting_attendees', 'decline_reason')) {
                $table->text('decline_reason')->nullable()->after('responded_at');
            }
        });

        Schema::table('meeting_attendees', function (Blueprint $table): void {
            $table->index(['meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        // คอลัมน์สามตัวนี้คือคำตอบของผู้เข้าร่วมทั้งหมด การถอยกลับจะลบคำตอบที่ตอบไว้แล้วทิ้ง
        // ซึ่งกู้คืนจากที่อื่นไม่ได้ จึงต้องหยุดไว้แทนที่จะลบเงียบ ๆ
        $respondedCount = DB::table('meeting_attendees')->where('status', '!=', 'pending')->count();

        if ($respondedCount > 0) {
            throw new RuntimeException(
                "ไม่สามารถถอยกลับ migration นี้ได้ มีผู้เข้าร่วม {$respondedCount} รายการที่ตอบรับหรือปฏิเสธการประชุมไว้แล้ว"
            );
        }

        Schema::table('meeting_attendees', function (Blueprint $table): void {
            $table->dropIndex(['meeting_id', 'status']);
            $table->dropColumn(['status', 'responded_at', 'decline_reason']);
        });
    }
};
