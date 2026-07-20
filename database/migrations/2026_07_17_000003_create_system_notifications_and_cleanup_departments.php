<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders', 'job_id')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title');
            $table->text('message')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        $removeDepartmentIds = DB::table('departments')
            ->whereIn('department_name', ['Human Resource', 'Human Resources', 'Graphic Design'])
            ->pluck('id');

        if ($removeDepartmentIds->isNotEmpty()) {
            DB::table('users')->whereIn('department_id', $removeDepartmentIds)->delete();
            DB::table('work_orders')->whereIn('department_id', $removeDepartmentIds)->delete();
            DB::table('departments')->whereIn('id', $removeDepartmentIds)->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
