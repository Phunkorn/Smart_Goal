<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สิทธิ์ "ดูงานของแผนก" ที่ admin มอบให้พนักงานเป็นรายคน — อ่านอย่างเดียว
 *
 * ไม่ใช่ role ใหม่และไม่ใช่ permission framework ใหม่ สิทธิ์จริงยังบังคับที่
 * WorkOrderPolicy::view() กับ WorkOrder::scopeVisibleInProjectsFor() ที่เดิม
 * ตารางนี้เก็บแต่ "ข้อเท็จจริง" ว่า admin ตั้งค่าอะไรไว้
 *
 * ทำไมต้องมีคอลัมน์โหมด ไม่ใช่เดาจากตาราง pivot
 * ---------------------------------------------------------------
 * โหมด own_department ตั้งใจไม่เก็บแผนกลง pivot เลย แผนกถูกอ่านจาก
 * users.department_id ตอนคิวรี สิทธิ์จึง "ย้ายตามคน" เมื่อ admin ย้ายแผนก
 * ถ้าเก็บเป็นแถว pivot ของแผนกปัจจุบัน วันที่ย้ายแผนกสิทธิ์จะกลายเป็น
 * "ดูแผนกเก่า" เงียบ ๆ ซึ่งเป็นสิทธิ์ข้ามแผนกที่ไม่มีใครสั่ง
 * และ custom ที่เลือกแผนกตัวเองไว้ใบเดียวจะแยกจาก own_department ไม่ออกเลย
 *
 * ชื่อ index ตั้งเองทั้งหมด ห้ามปล่อยให้ Laravel ตั้งให้
 * ---------------------------------------------------------------
 * ชื่อ composite index อัตโนมัติของ Laravel กับชื่อตารางยาวของโปรเจกต์นี้
 * เคยเกินเพดาน 64 ตัวอักษรของ MySQL แล้ว migration ตายกลางทาง
 * (เคยเกิดกับ meeting_attendee_response_events มาแล้ว)
 * SQLite ที่ชุดทดสอบใช้ไม่บังคับเพดานนี้ ความต่างจึงไม่โผล่จนขึ้นฐานข้อมูลจริง
 */
return new class extends Migration
{
    private const TABLE = 'user_task_visibility_departments';

    private const UNIQUE_INDEX = 'user_task_visibility_departments_user_department_unique';

    private const DEPARTMENT_INDEX = 'user_task_visibility_departments_department_index';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'task_visibility_scope')) {
            Schema::table('users', function (Blueprint $table): void {
                // ค่าตั้งต้นคือ involved — พนักงานทุกคนที่มีอยู่แล้วต้องไม่ได้สิทธิ์เพิ่มจาก deploy นี้
                $table->string('task_visibility_scope', 20)
                    ->default('involved')
                    ->after('is_department_head');
            });
        }

        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'department_id'], self::UNIQUE_INDEX);
            // ใช้ตอบคำถามย้อนทาง "ใครมองเห็นงานของแผนกนี้" ในหน้าจัดการของ admin
            $table->index('department_id', self::DEPARTMENT_INDEX);
        });
    }

    /**
     * ย้อนกลับได้เฉพาะเมื่อยังไม่มีใครถูกมอบสิทธิ์
     *
     * ตารางนี้กับคอลัมน์โหมดเก็บ "การตัดสินใจของ admin" ซึ่งไม่มีทางสร้างใหม่จาก
     * ข้อมูลอื่นในระบบได้เลย การ drop ทิ้งเงียบ ๆ คือการลบสิ่งที่ผู้ใช้ตั้งไว้
     * จึงปฏิเสธแทน ให้คนที่ย้อน migration ตัดสินใจเองอย่างตั้งใจ
     */
    public function down(): void
    {
        $hasGrants = (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists())
            || (Schema::hasColumn('users', 'task_visibility_scope')
                && DB::table('users')->where('task_visibility_scope', '!=', 'involved')->exists());

        if ($hasGrants) {
            throw new RuntimeException(
                'ยังมีพนักงานที่ถูกมอบสิทธิ์ดูงานของแผนกอยู่ '
                .'ให้ตั้งค่าทุกคนกลับเป็น "เฉพาะงานที่เกี่ยวข้อง" ก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists(self::TABLE);

        if (Schema::hasColumn('users', 'task_visibility_scope')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('task_visibility_scope');
            });
        }
    }
};
