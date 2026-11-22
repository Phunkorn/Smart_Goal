<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * โพสต์ในชุมชน (Social Community)
 *
 * ทุกบัญชีเห็นทุกโพสต์ ฟีดเรียงจากใหม่ไปเก่าด้วย id (เคอร์เซอร์ id < before) จึงไม่ต้องมีดัชนีเพิ่ม
 * นอกจาก primary key ส่วน (deleted_at, id) ช่วยกรองโพสต์ที่ถูกลบออกจากการสแกนตามลำดับ id
 *
 * ลบแบบ soft delete และเขียนลงถังขยะ (TrashRestorers) การลบถาวรเกิดจาก TrashRetention เท่านั้น
 * user_id ลบตาม (cascade) เมื่อบัญชีถูกลบถาวรจากถังขยะ — รูปของโพสต์เหล่านั้นถูกเก็บกวาดก่อน
 * ผ่าน TrashRestorers::FILE_RELATIONS ของ User (ไม่ทิ้งไฟล์ค้างบนดิสก์)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'id'], 'social_posts_deleted_id_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('social_posts') && DB::table('social_posts')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีโพสต์ในชุมชนบันทึกไว้แล้ว');
        }

        Schema::dropIfExists('social_posts');
    }
};
