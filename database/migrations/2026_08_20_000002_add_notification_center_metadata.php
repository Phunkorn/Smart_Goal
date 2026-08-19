<?php

use App\Models\SystemNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('type')->index();
            $table->foreignId('actor_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('work_order_list_id')->nullable()->after('work_order_id')->constrained('work_order_lists')->nullOnDelete();
            $table->string('dedupe_key')->nullable()->after('data');
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'created_at'], 'notifications_owner_read_created_idx');
            $table->index(['user_id', 'category', 'created_at'], 'notifications_owner_category_created_idx');
        });

        DB::table('system_notifications')->orderBy('id')->chunkById(200, function ($notifications): void {
            foreach ($notifications as $notification) {
                $listId = $notification->work_order_id
                    ? DB::table('work_orders')->where('job_id', $notification->work_order_id)->value('work_order_list_id')
                    : null;
                DB::table('system_notifications')->where('id', $notification->id)->update([
                    'category' => SystemNotification::categoryForType($notification->type),
                    'work_order_list_id' => $listId,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_owner_read_created_idx');
            $table->dropIndex('notifications_owner_category_created_idx');
            $table->dropUnique(['user_id', 'dedupe_key']);
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropConstrainedForeignId('work_order_list_id');
            $table->dropColumn(['category', 'dedupe_key']);
        });
    }
};
