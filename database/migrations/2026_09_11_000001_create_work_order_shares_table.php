<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประกาศแชร์งาน
 *
 * เดิมการเข้าร่วมงานเกิดได้ทางเดียวคือเจ้าของงานเป็นคนเชิญ คนที่สนใจงานหนึ่งแต่
 * เจ้าของไม่รู้จักจึงไม่มีช่องทางเข้าร่วม ตารางนี้เก็บทิศทางตรงข้าม — เจ้าของงาน
 * ประกาศงานออกมา แล้วให้ผู้ที่สนใจกดขอเข้าร่วมเอง
 *
 * ผลลัพธ์สุดท้ายยังเป็น "ผู้ร่วมงาน" ชุดเดิมใน work_order_collaborators ทุกประการ
 * ไม่ใช่สิทธิ์ชนิดใหม่ ตารางนี้จึงเก็บแค่ตัวประกาศ ไม่เก็บสิทธิ์
 *
 * department_id เป็นสแนปช็อตของแผนกผู้แชร์ ณ เวลาที่แชร์ ไม่ใช่การอ่านจาก users
 * ตอนแสดงผล เพราะถ้าอ่านสด คนที่ย้ายแผนกจะทำให้ประกาศเก่าเปลี่ยนกลุ่มผู้เห็น
 * ย้อนหลังโดยไม่มีใครสั่ง
 *
 * ไม่มี unique(work_order_id) เพราะงานที่เคยปิดประกาศแล้วต้องแชร์ใหม่ได้
 * กติกา "หนึ่งงานมีประกาศที่เปิดอยู่ได้ใบเดียว" บังคับที่
 * App\Services\WorkOrderShareService ด้วยการล็อกแถวก่อนสร้าง
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_shares')) {
            return;
        }

        Schema::create('work_order_shares', function (Blueprint $table): void {
            $table->id();
            // work_orders ใช้ job_id เป็น primary key จึงต้องระบุคอลัมน์อ้างอิงเอง
            $table->foreignId('work_order_id')->constrained('work_orders', 'job_id')->cascadeOnDelete();
            $table->foreignId('shared_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            // 'department' = เห็นเฉพาะแผนกของผู้แชร์, 'organization' = พนักงานทุกแผนกเห็น
            $table->string('scope', 20);
            // 'open' = ยังรับคนเข้าร่วมอยู่, 'closed' = ผู้แชร์ปิดประกาศแล้ว
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // ฟีดหน้าแชร์งานถามด้วยสามค่านี้เสมอ: เปิดอยู่ไหม ขอบเขตอะไร แผนกไหน
            $table->index(['status', 'scope', 'department_id'], 'work_order_shares_audience_idx');
            $table->index(['work_order_id', 'status']);
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_order_shares') && DB::table('work_order_shares')->exists()) {
            throw new RuntimeException(
                'work_order_shares ยังมีประกาศแชร์งานอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_order_shares');
    }
};
