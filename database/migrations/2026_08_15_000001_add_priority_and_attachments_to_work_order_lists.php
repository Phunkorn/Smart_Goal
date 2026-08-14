<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_lists', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(2)->after('name');
        });

        Schema::create('work_order_list_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_list_id')->constrained('work_order_lists')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('file_type')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_list_attachments');

        Schema::table('work_order_lists', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
