<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รองรับสามฟีเจอร์ของความคิดเห็นในงาน: ปักหมุด, ตอบกลับข้อความเดิม (quote-reply)
 * และกล่าวถึงเพื่อนร่วมงานด้วย @mention
 *
 * ปักหมุดได้ทีละ 1 ข้อความต่องาน — บังคับที่ชั้นแอป (TaskCommentService::pin())
 * ไม่ใช่ constraint ของฐานข้อมูล เพราะ "ปักได้ทีละหนึ่ง" ต้องตัดหมุดเดิมออกก่อนเสมอ
 * ซึ่งเป็นตรรกะ ไม่ใช่กติกาความสมบูรณ์ของข้อมูลที่ unique index ทำแทนได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_updates', function (Blueprint $table): void {
            $table->foreignId('reply_to_id')->nullable()->after('is_comment')
                ->constrained('work_order_updates')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable()->after('reply_to_id');
            $table->foreignId('pinned_by')->nullable()->after('pinned_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('work_order_update_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_update_id')->constrained('work_order_updates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_order_update_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_update_mentions');

        Schema::table('work_order_updates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropColumn('pinned_at');
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
