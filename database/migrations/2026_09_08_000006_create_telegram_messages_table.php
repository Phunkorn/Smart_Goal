<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * คิวข้อความขาออกของ Telegram (outbox)
     *
     * เครื่อง production ไม่มี queue worker และไม่มี cron การสร้างการแจ้งเตือนจึงเพียง
     * INSERT แถวที่นี่ (เร็วและปลอดภัยแม้อยู่ใน DB::transaction) แล้วยิง HTTP จริง
     * หลังคืน response ให้เบราว์เซอร์แล้ว แถวที่ส่งไม่สำเร็จจะค้างเป็น pending
     * และถูก request ถัดไปหยิบไปลองใหม่จนครบจำนวนครั้งที่กำหนด
     */
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // การแจ้งเตือนถูกลบได้จากศูนย์การแจ้งเตือน และถูก cascade เมื่องานถูกลบ
            // ข้อความที่ประกอบไว้แล้วยังต้องส่งได้ต่อ จึงตัดความสัมพันธ์แทนการลบแถว
            $table->foreignId('system_notification_id')->nullable()->constrained('system_notifications')->nullOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->text('text');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
