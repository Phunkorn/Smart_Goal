<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('submitted_for_review_by')->nullable()->after('late_at')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_for_review_at')->nullable()->after('submitted_for_review_by');
            $table->foreignId('final_approved_by')->nullable()->after('submitted_for_review_at')->constrained('users')->nullOnDelete();
            $table->timestamp('final_approved_at')->nullable()->after('final_approved_by');
            $table->string('review_return_reason', 1000)->nullable()->after('final_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_for_review_by');
            $table->dropConstrainedForeignId('final_approved_by');
            $table->dropColumn(['submitted_for_review_at', 'final_approved_at', 'review_return_reason']);
        });
    }
};
