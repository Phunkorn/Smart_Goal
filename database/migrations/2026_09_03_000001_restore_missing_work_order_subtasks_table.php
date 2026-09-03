<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_subtasks')) {
            return;
        }

        Schema::create('work_order_subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders', 'job_id')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // This migration repairs schema drift. Dropping the table here could
        // destroy a table that was created by the original migration.
    }
};
