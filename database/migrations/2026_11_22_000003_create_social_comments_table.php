<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ความคิดเห็นใต้โพสต์ในชุมชน — ตอบกลับได้หนึ่งระดับ (parent_id ชี้ความคิดเห็นหลัก)
 *
 * ดัชนี (social_post_id, parent_id, id) รองรับทั้งการนับจำนวนต่อโพสต์ การดึงความคิดเห็นหลัก
 * ทีละหน้าด้วยเคอร์เซอร์ id และการดึงการตอบกลับของความคิดเห็นหลักชุดหนึ่ง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('social_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['social_post_id', 'parent_id', 'id'], 'social_comments_post_parent_id_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('social_comments') && DB::table('social_comments')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีความคิดเห็นในชุมชนบันทึกไว้แล้ว');
        }

        Schema::dropIfExists('social_comments');
    }
};
