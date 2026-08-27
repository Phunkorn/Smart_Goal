<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_collaborators', function (Blueprint $table): void {
            $table->string('status', 20)->default('pending')->change();
        });

        Schema::create('work_order_list_task_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('work_order_id')->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->string('job_topic');
            $table->text('job_details')->nullable();
            $table->unsignedTinyInteger('job_priority')->default(2);
            $table->dateTime('job_start_at');
            $table->dateTime('job_due_at');
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('job_id')->on('work_orders')->nullOnDelete();
            $table->index(['work_order_list_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_list_task_requests');

        Schema::table('work_order_collaborators', function (Blueprint $table): void {
            $table->string('status', 20)->default('accepted')->change();
        });
    }
};
