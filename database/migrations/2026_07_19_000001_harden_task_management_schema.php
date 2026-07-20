<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('work_order_subtasks', function (Blueprint $table) {
            if (! Schema::hasColumn('work_order_subtasks', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('is_completed');
            }
        });

        Schema::table('system_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('system_notifications', 'data')) {
                $table->json('data')->nullable()->after('message');
            }

            if (! Schema::hasColumn('system_notifications', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('data');
            }
        });

        if (! Schema::hasTable('trash_logs')) {
            Schema::create('trash_logs', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type');
                $table->unsignedBigInteger('entity_id');
                $table->json('payload_json')->nullable();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('deleted_at')->useCurrent();
                $table->timestamp('purge_after')->nullable();
                $table->timestamps();
                $table->index(['entity_type', 'entity_id']);
                $table->index('purge_after');
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 50);
                $table->nullableMorphs('subject');
                $table->text('description')->nullable();
                $table->json('changes')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['action', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('trash_logs');

        Schema::table('system_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('system_notifications', 'is_read')) {
                $table->dropColumn('is_read');
            }

            if (Schema::hasColumn('system_notifications', 'data')) {
                $table->dropColumn('data');
            }
        });

        Schema::table('work_order_subtasks', function (Blueprint $table) {
            if (Schema::hasColumn('work_order_subtasks', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
