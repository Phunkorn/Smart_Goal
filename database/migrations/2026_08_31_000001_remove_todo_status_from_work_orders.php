<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->tinyInteger('job_status')->default(2)->change();
        });

        DB::table('work_orders')
            ->where('job_status', 1)
            ->update(['job_status' => 2]);
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->tinyInteger('job_status')->default(1)->change();
        });
    }
};
