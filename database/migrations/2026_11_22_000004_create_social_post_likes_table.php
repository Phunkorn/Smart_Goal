<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * การถูกใจโพสต์ในชุมชน
 *
 * unique (social_post_id, user_id) คือสิ่งที่กันการถูกใจซ้ำจริง — กดรัวหรือส่งพร้อมกันหลายแท็บ
 * ฐานข้อมูลก็เก็บได้แถวเดียว (SocialPostService::like ใช้ insertOrIgnore)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['social_post_id', 'user_id'], 'social_post_likes_post_user_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('social_post_likes') && DB::table('social_post_likes')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีการถูกใจโพสต์ในชุมชนบันทึกไว้แล้ว');
        }

        Schema::dropIfExists('social_post_likes');
    }
};
