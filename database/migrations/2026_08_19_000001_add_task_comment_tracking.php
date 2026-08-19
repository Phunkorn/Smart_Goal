<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_updates', function (Blueprint $table) {
            $table->boolean('is_comment')->default(false)->index()->after('note');
        });

        Schema::create('work_order_comment_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders', 'job_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_read_update_id')->nullable()->constrained('work_order_updates')->nullOnDelete();
            $table->timestamps();
            $table->unique(['work_order_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_comment_reads');
        Schema::table('work_order_updates', fn (Blueprint $table) => $table->dropColumn('is_comment'));
    }
};
