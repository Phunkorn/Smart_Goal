<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รูปภาพประกอบของ Ticket
 *
 * ไฟล์เก็บแบบ private และเสิร์ฟผ่าน MediaController โดยตรวจสิทธิ์จาก Ticket แม่
 * ticket_activity_id ระบุว่ารูปแนบมากับบันทึกการดำเนินงานใด (null = แนบตอนแจ้ง)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('ticket_activity_id')->nullable()->constrained('ticket_activities')->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('file_type', 100);
            $table->unsignedInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // แถวคือทางเดียวที่ไปถึงไฟล์ใน storage ถ้าลบตาราง ไฟล์จะค้างโดยไม่มีอะไรอ้างถึง
        if (Schema::hasTable('ticket_attachments') && DB::table('ticket_attachments')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีรูปภาพของ Ticket บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('ticket_attachments');
    }
};
