<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','user','viewer') NOT NULL DEFAULT 'user'");
        }

        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_orders', 'leader_user_id')) {
                $table->foreignId('leader_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_orders', 'approval_status')) {
                $table->string('approval_status', 20)->default('approved')->after('job_status');
            }

            if (! Schema::hasColumn('work_orders', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });

        Schema::create('work_order_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders', 'job_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['work_order_id', 'user_id']);
        });

        Schema::table('job_images', function (Blueprint $table) {
            if (! Schema::hasColumn('job_images', 'original_name')) {
                $table->string('original_name')->nullable()->after('file_path');
            }

            if (! Schema::hasColumn('job_images', 'uploaded_by')) {
                $table->foreignId('uploaded_by')->nullable()->after('file_type')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_images', function (Blueprint $table) {
            if (Schema::hasColumn('job_images', 'uploaded_by')) {
                $table->dropConstrainedForeignId('uploaded_by');
            }

            if (Schema::hasColumn('job_images', 'original_name')) {
                $table->dropColumn('original_name');
            }
        });

        Schema::dropIfExists('work_order_collaborators');

        Schema::table('work_orders', function (Blueprint $table) {
            foreach (['approved_at', 'approval_status'] as $column) {
                if (Schema::hasColumn('work_orders', $column)) {
                    $table->dropColumn($column);
                }
            }

            foreach (['approved_by', 'leader_user_id', 'created_by'] as $column) {
                if (Schema::hasColumn('work_orders', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','user') NOT NULL DEFAULT 'user'");
        }
    }
};
