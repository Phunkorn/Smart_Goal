<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เวลาที่ผู้ใช้เปิดดูฟีดชุมชนล่าสุด — ใช้นับ "โพสต์ใหม่" บนเมนูชุมชน
 *
 * หนึ่งแถวต่อผู้ใช้ (user_id เป็น primary key) ผู้ใช้ที่ยังไม่มีแถวคือยังไม่เคยเปิดฟีด
 * และไม่ถูกนับโพสต์ย้อนหลังทั้งหมดเป็น "ใหม่" (SocialFeedReadService::newPostCount)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_feed_reads', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestamp('seen_at');
        });
    }

    public function down(): void
    {
        // ตารางนี้สร้างใหม่ได้เองจากการเปิดฟีดครั้งถัดไป แต่ยังคงกฎเดียวกับตารางอื่น: ไม่ทิ้งข้อมูลเงียบ ๆ
        if (Schema::hasTable('social_feed_reads') && DB::table('social_feed_reads')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีเวลาเปิดอ่านฟีดชุมชนของผู้ใช้บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('social_feed_reads');
    }
};
