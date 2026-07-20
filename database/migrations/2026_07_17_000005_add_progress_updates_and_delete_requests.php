<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders', 'job_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('note');
            $table->timestamps();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'delete_requested_by')) {
                $table->foreignId('delete_requested_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_orders', 'delete_requested_at')) {
                $table->timestamp('delete_requested_at')->nullable()->after('delete_requested_by');
            }

            if (! Schema::hasColumn('work_orders', 'delete_request_reason')) {
                $table->text('delete_request_reason')->nullable()->after('delete_requested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'delete_request_reason')) {
                $table->dropColumn('delete_request_reason');
            }

            if (Schema::hasColumn('work_orders', 'delete_requested_at')) {
                $table->dropColumn('delete_requested_at');
            }

            if (Schema::hasColumn('work_orders', 'delete_requested_by')) {
                $table->dropConstrainedForeignId('delete_requested_by');
            }
        });

        Schema::dropIfExists('work_order_updates');
    }
};
