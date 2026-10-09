<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ขอบเขตแผนกของนัดหมาย — เจ้าของแผนก + สวิตช์ "ส่วนตัว / แผนก"
 *
 * ทำไมต้องมีสองคอลัมน์ ไม่ใช่แค่ department_id
 * ---------------------------------------------------------------
 * ปฏิทินถูกเปลี่ยนเป็นปฏิทินระดับแผนก แต่ก่อนหน้านี้นัดหมายเป็นข้อมูลส่วนตัวโดยเจตนา
 * (เห็นได้เฉพาะผู้สร้างกับผู้ที่ถูกเชิญ — ดู AppointmentPolicy) ถ้ามีแต่ department_id
 * แล้วเปิดให้คนในแผนกเห็นทั้งหมด นัดหมายทุกใบที่ผู้ใช้บันทึกไว้ตอนที่ระบบยังสัญญาว่า
 * "ส่วนตัว" จะถูกเปิดเผยย้อนหลังทันทีที่ deploy ซึ่งเป็นการเปลี่ยนสัญญาต่อผู้ใช้
 *
 * visibility จึงเป็นตัวตัดสินการมองเห็น ส่วน department_id เป็นเพียงเจ้าของแผนก
 * แถวเดิมทั้งหมดถูกตั้งเป็น 'personal' (ค่า default ของคอลัมน์) จึงไม่มีข้อมูลใด
 * เปลี่ยนการมองเห็นเพราะ migration นี้ ส่วนนัดหมายที่สร้างใหม่ตั้งค่าเริ่มต้นเป็น
 * 'department' จากฟอร์ม ไม่ใช่จาก default ของฐานข้อมูล
 *
 * department_id ถูก backfill จากแผนกของผู้สร้าง เพื่อให้นัดหมายเก่าที่ผู้ใช้เลือก
 * เปลี่ยนเป็น "แผนก" ภายหลังมีเจ้าของแผนกอยู่แล้ว ไม่ต้องเดาตอนนั้น
 */
return new class extends Migration
{
    /** ค่าของ appointments.visibility — เขียนเป็น literal ตามกติกาใน CLAUDE.md ห้าม import ค่าคงที่ของ Model */
    private const VISIBILITY_PERSONAL = 'personal';

    private const VISIBILITY_DEPARTMENT = 'department';

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // nullOnDelete: แผนกถูกลบแล้วนัดหมายต้องไม่หายไปด้วย มันยังเป็นของผู้สร้างอยู่
            $table->foreignId('department_id')
                ->nullable()
                ->after('created_by')
                ->constrained('departments')
                ->nullOnDelete();

            $table->string('visibility', 16)
                ->default(self::VISIBILITY_PERSONAL)
                ->after('department_id');

            // ปฏิทินถามด้วย (visibility, department_id, appointment_date) ทุกครั้งที่วาดเดือน
            // ชื่อ index ถูกตั้งเองให้สั้น เพราะชื่ออัตโนมัติยาวเกิน 64 ตัวอักษรของ MySQL
            $table->index(['visibility', 'department_id', 'appointment_date'], 'appt_scope_date_idx');
        });

        // แผนกเจ้าของของแถวเดิม = แผนกปัจจุบันของผู้สร้าง (รวมบัญชีที่ถูกลบแบบ soft delete)
        // ทำเป็น UPDATE...JOIN ชุดเดียว ไม่วนทีละแถว เพื่อไม่ให้ตารางใหญ่ใช้เวลานาน
        DB::table('appointments')
            ->whereNull('department_id')
            ->update([
                'department_id' => DB::raw('(select users.department_id from users where users.id = appointments.created_by)'),
            ]);
    }

    /**
     * ย้อนกลับได้เฉพาะตอนที่ยังไม่มีใครใช้ขอบเขตแผนกจริง
     *
     * ถ้ามีนัดหมายที่ถูกตั้งเป็น 'department' ไว้แล้ว การ drop คอลัมน์จะทำให้ทั้ง
     * เจ้าของแผนกและการตั้งค่าการมองเห็นที่ผู้ใช้เลือกไว้หายไปเงียบ ๆ จึงหยุดไว้
     * ตามกติกาใน CLAUDE.md ว่า down() ที่ทำลายข้อมูลต้องตรวจแล้ว throw
     */
    public function down(): void
    {
        $departmentScoped = DB::table('appointments')
            ->where('visibility', self::VISIBILITY_DEPARTMENT)
            ->count();

        if ($departmentScoped > 0) {
            throw new RuntimeException(
                "ย้อน migration นี้ไม่ได้: มีนัดหมาย {$departmentScoped} รายการที่ตั้งขอบเขตเป็น 'แผนก' ไว้แล้ว ".
                'การลบคอลัมน์จะทำให้เจ้าของแผนกและการตั้งค่าการมองเห็นหายไป '.
                "ให้เปลี่ยนนัดหมายเหล่านั้นกลับเป็น 'ส่วนตัว' ก่อนหากต้องการย้อนจริง"
            );
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex('appt_scope_date_idx');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn('visibility');
        });
    }
};
