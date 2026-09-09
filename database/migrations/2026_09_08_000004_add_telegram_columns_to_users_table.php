<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // chat ส่วนตัวหนึ่งห้องผูกได้กับผู้ใช้เดียวเท่านั้น มิฉะนั้นการแจ้งเตือนของ
            // คนละคนจะไปโผล่ในห้องเดียวกัน จึงบังคับ unique ที่ระดับฐานข้อมูล
            $table->unsignedBigInteger('telegram_chat_id')->nullable()->unique()->after('profile_image');
            $table->string('telegram_username', 64)->nullable()->after('telegram_chat_id');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_username');
            $table->boolean('telegram_notifications_enabled')->default(true)->after('telegram_linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_telegram_chat_id_unique');
            $table->dropColumn([
                'telegram_chat_id',
                'telegram_username',
                'telegram_linked_at',
                'telegram_notifications_enabled',
            ]);
        });
    }
};
