<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * รหัสผูกบัญชีแบบใช้ครั้งเดียว
     *
     * ผู้ใช้กดขอรหัสจากหน้าตั้งค่าแล้วนำไปส่งให้บอทด้วย /start <token>
     * เก็บเป็นตารางแยกแทนคอลัมน์ใน users เพราะต้องรู้เวลาหมดอายุและเวลาที่ถูกใช้
     * เพื่อกันการนำรหัสเดิมกลับมาผูกซ้ำภายหลัง
     */
    public function up(): void
    {
        Schema::create('telegram_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_tokens');
    }
};
