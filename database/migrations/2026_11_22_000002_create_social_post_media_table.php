<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รูปภาพของโพสต์ในชุมชน
 *
 * ไฟล์อยู่ใน private disk และเสิร์ฟผ่าน MediaController ที่ตรวจสิทธิ์จากโพสต์แม่
 * width/height ใช้จองพื้นที่ของรูปก่อนโหลด หน้าฟีดจึงไม่กระโดด (ไม่มีค่าเมื่ออ่านขนาดไม่ได้)
 * mime_type มาจากเนื้อไฟล์ (getMimeType) ไม่ใช่ค่าที่ client ส่งมา
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('disk', 20)->default('local');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['social_post_id', 'sort_order'], 'social_post_media_post_order_index');
        });
    }

    public function down(): void
    {
        // แถวคือทางเดียวที่ไปถึงไฟล์ใน storage ถ้าลบตาราง ไฟล์จะค้างโดยไม่มีอะไรอ้างถึง
        if (Schema::hasTable('social_post_media') && DB::table('social_post_media')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีรูปภาพของโพสต์ในชุมชนบันทึกไว้แล้ว');
        }

        Schema::dropIfExists('social_post_media');
    }
};
