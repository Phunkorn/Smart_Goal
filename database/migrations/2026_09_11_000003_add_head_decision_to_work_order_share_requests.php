<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * การตัดสินชั้นที่สองของคำขอเข้าร่วมงานที่ถูกแชร์
 *
 * คำขอข้ามแผนกที่ผู้แชร์เป็นพนักงานธรรมดายังต้องผ่านหัวหน้าแผนกของผู้แชร์อีกขั้น
 * (สถานะ awaiting_head) การตัดสินชั้นนั้นเป็นคนละเหตุการณ์กับการตัดสินของผู้แชร์
 *
 * ถ้าเอาไปทับ decided_by / decided_at ซึ่งเป็นของผู้แชร์ จะไม่เหลือหลักฐานว่าใครเป็น
 * คนรับผู้ขอเข้ามาตั้งแต่แรก ทั้งที่เป็นคำถามแรกที่ถูกถามเวลาย้อนตรวจ
 *
 * หมายเหตุ: หัวหน้าแผนกที่ดูแลแผนกปลายทางของงานเป็นผู้มีอำนาจสูงสุดของงานนั้นอยู่แล้ว
 * เมื่อเขาเป็นผู้แชร์เอง คำขอจะข้ามสถานะ awaiting_head ไปเลย คอลัมน์คู่นี้จึงว่าง
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('work_order_share_requests', 'head_decided_by')) {
            return;
        }

        Schema::table('work_order_share_requests', function (Blueprint $table): void {
            $table->foreignId('head_decided_by')->nullable()->after('decided_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('head_decided_at')->nullable()->after('head_decided_by');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasColumn('work_order_share_requests', 'head_decided_by')
            && DB::table('work_order_share_requests')->whereNotNull('head_decided_by')->exists()) {
            throw new RuntimeException(
                'มีคำขอที่หัวหน้าแผนกตัดสินไปแล้ว — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::table('work_order_share_requests', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['head_decided_by']);
            }

            $table->dropColumn(['head_decided_by', 'head_decided_at']);
        });
    }
};
