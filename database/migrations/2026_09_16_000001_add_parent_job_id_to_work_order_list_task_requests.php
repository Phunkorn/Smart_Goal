<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_list_task_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_job_id')->nullable()->after('work_order_id');
            $table->foreign('parent_job_id')
                ->references('job_id')
                ->on('work_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_list_task_requests', function (Blueprint $table): void {
            $table->dropForeign(['parent_job_id']);
            $table->dropColumn('parent_job_id');
        });
    }
};
