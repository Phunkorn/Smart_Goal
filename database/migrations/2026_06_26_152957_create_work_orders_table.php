<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {

            $table->id('job_id');

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('job_topic');

            $table->text('job_details')->nullable();

            // 1 = ต่ำ, 2 = ปานกลาง, 3 = สูง
            $table->tinyInteger('job_priority')->default(2);

            // 1 = รอเริ่ม, 2 = กำลังทำ, 3 = ตรวจสอบ, 4 = เสร็จสิ้น
            $table->tinyInteger('job_status')->default(1);

            // Progress (%)
            $table->tinyInteger('job_progress')->default(0);

            $table->dateTime('job_start_at');

            $table->dateTime('job_due_at');

            $table->dateTime('job_completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
