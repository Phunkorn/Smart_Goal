<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * คำขอเข้าร่วมงานที่ถูกแชร์
 *
 * ทำไมไม่ใช้ work_order_collaborators เดิมที่มีสถานะ pending อยู่แล้ว — เพราะ
 * pending ที่นั่นแปลว่า "รอหัวหน้าแผนกตัดสิน" และ App\Services\AdminApprovalQuery
 * กวาดทุกแถว pending เข้าคิวคำขออนุมัติของหัวหน้าแผนกทันที ถ้าเอาคำขอเข้าร่วม
 * ในแผนกไปใส่ที่นั่น คำขอจะโผล่ผิดคิวตั้งแต่ยังไม่ถึงมือผู้แชร์ซึ่งเป็นคนตัดสิน
 * ชั้นแรก
 *
 * collaborator_status เก็บผลที่ CollaboratorInvitationService::invite() คืนมา
 * ตอนผู้แชร์อนุมัติ ('accepted' หรือ 'pending') เพราะการอนุมัติของผู้แชร์ไม่ได้
 * แปลว่าจบเสมอ — คำขอข้ามแผนกยังต้องผ่านหัวหน้าแผนกของผู้ขออีกชั้นตามกติกาเดิม
 * ถ้าไม่เก็บค่านี้ ผู้ขอจะเห็นว่า "อนุมัติแล้ว" แต่เปิดงานไม่เจอ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_share_requests')) {
            return;
        }

        Schema::create('work_order_share_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_share_id')->constrained('work_order_shares')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            // pending | approved | rejected | cancelled (cancelled = ผู้แชร์ปิดประกาศก่อนตัดสิน)
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            // 'accepted' = เข้าร่วมแล้ว, 'pending' = รอหัวหน้าแผนกของผู้ขออนุมัติอีกชั้น
            $table->string('collaborator_status', 20)->nullable();
            $table->timestamps();

            // คนเดียวกันขอเข้าร่วมประกาศเดียวซ้ำไม่ได้
            $table->unique(['work_order_share_id', 'requester_id'], 'work_order_share_requests_unique');
            $table->index(['requester_id', 'status']);
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('work_order_share_requests') && DB::table('work_order_share_requests')->exists()) {
            throw new RuntimeException(
                'work_order_share_requests ยังมีคำขอเข้าร่วมอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('work_order_share_requests');
    }
};
