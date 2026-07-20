<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'work_order_list_id')) {
                $table->foreignId('work_order_list_id')->nullable()->after('department_id')->constrained('work_order_lists')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_orders', 'is_starred')) {
                $table->boolean('is_starred')->default(false)->after('work_order_list_id');
            }
        });

        Schema::table('work_order_subtasks', function (Blueprint $table) {
            if (! Schema::hasColumn('work_order_subtasks', 'details')) {
                $table->text('details')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_order_subtasks', function (Blueprint $table) {
            if (Schema::hasColumn('work_order_subtasks', 'details')) {
                $table->dropColumn('details');
            }
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'is_starred')) {
                $table->dropColumn('is_starred');
            }

            if (Schema::hasColumn('work_orders', 'work_order_list_id')) {
                $table->dropConstrainedForeignId('work_order_list_id');
            }
        });

        Schema::dropIfExists('work_order_lists');
    }
};
