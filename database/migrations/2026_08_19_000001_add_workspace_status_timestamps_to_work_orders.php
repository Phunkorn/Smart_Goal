<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dateTime('paused_at')->nullable()->after('job_completed_at');
            $table->dateTime('late_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn(['paused_at', 'late_at']));
    }
};
