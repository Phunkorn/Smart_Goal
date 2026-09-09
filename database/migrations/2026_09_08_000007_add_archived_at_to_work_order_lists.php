<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_lists', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_visible')->index();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_lists', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
