<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ใบตรวจคอมพิวเตอร์เป็นของ "แผนก" ไม่ใช่ของทั้งระบบ
 *
 * unique (check_date, shift) เดิมบังคับว่าทั้งระบบมีใบตรวจได้วันละกะละหนึ่งใบ
 * ซึ่งขัดกับสิทธิ์ใช้งานที่เป็นรายแผนก (departments.computer_check_enabled)
 * เมื่อ admin เปิดสิทธิ์ให้สองแผนก แผนกที่กดเริ่มรอบเป็นคนที่สองจะได้ใบของ
 * แผนกแรกกลับมา ซึ่งตัวเองไม่ได้เป็นผู้ตรวจ จึงติ๊กผลไม่ได้เลยและหน้าจอไม่บอก
 * ว่าทำไม — กติกาที่ถูกต้องคือหนึ่งแผนก + หนึ่งวัน + หนึ่งกะ ได้หนึ่งใบ
 *
 * ชื่อ index ถูกตั้งเองให้สั้นโดยตั้งใจ ชื่อที่ Laravel สร้างจากสามคอลัมน์ของ
 * ตารางนี้ยาวเกิน 64 ตัวอักษรของ MySQL ซึ่งจะพังตอน migrate บนเครื่องจริง
 * แม้ SQLite ในเทสต์จะยอมรับ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_check_sessions', function (Blueprint $table): void {
            $table->dropUnique('computer_check_sessions_day_shift_unique');
            $table->unique(
                ['department_id', 'check_date', 'shift'],
                'cc_sessions_dept_day_shift_unique'
            );
        });
    }

    /**
     * ย้อนกลับเป็น unique ที่แคบกว่า — ต้องปฏิเสธถ้าข้อมูลจริงเข้าไม่ได้
     *
     * ถ้ามีสองแผนกที่ตรวจวันเดียวกันกะเดียวกันอยู่แล้ว การสร้าง unique เดิม
     * จะล้มกลางทางและทิ้งตารางไว้แบบไม่มี index ใด ๆ ตามกฎของ CLAUDE.md
     * down() ที่ทำให้ข้อมูลเข้าไม่ได้ต้องโยน error ไม่ใช่ลบแถวทิ้งเงียบ ๆ
     */
    public function down(): void
    {
        $clashes = DB::table('computer_check_sessions')
            ->select('check_date', 'shift')
            ->groupBy('check_date', 'shift')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($clashes > 0) {
            throw new RuntimeException(
                'มีใบตรวจของหลายแผนกในวันและกะเดียวกันอยู่แล้ว '
                .'ย้อน migration นี้ไม่ได้จนกว่าจะรวมหรือย้ายข้อมูลออกก่อน'
            );
        }

        Schema::table('computer_check_sessions', function (Blueprint $table): void {
            $table->dropUnique('cc_sessions_dept_day_shift_unique');
            $table->unique(['check_date', 'shift'], 'computer_check_sessions_day_shift_unique');
        });
    }
};
