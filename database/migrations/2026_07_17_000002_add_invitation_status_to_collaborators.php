<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_collaborators', function (Blueprint $table) {
            if (! Schema::hasColumn('work_order_collaborators', 'status')) {
                $table->string('status', 20)->default('accepted')->after('added_by');
            }

            if (! Schema::hasColumn('work_order_collaborators', 'responded_at')) {
                $table->timestamp('responded_at')->nullable()->after('status');
            }
        });

        DB::table('work_orders')
            ->whereNull('created_by')
            ->update(['created_by' => DB::raw('user_id')]);

        DB::table('work_orders')
            ->whereNull('leader_user_id')
            ->update(['leader_user_id' => DB::raw('user_id')]);

        DB::table('work_order_collaborators')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        Schema::table('work_order_collaborators', function (Blueprint $table) {
            if (Schema::hasColumn('work_order_collaborators', 'responded_at')) {
                $table->dropColumn('responded_at');
            }

            if (Schema::hasColumn('work_order_collaborators', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
