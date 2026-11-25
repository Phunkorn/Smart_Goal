<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_board_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_board_id')->constrained('workspace_boards')->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->uuid('request_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_hash', 64)->nullable();
            $table->json('delta');
            $table->unsignedInteger('byte_size');
            $table->timestamp('created_at');
            $table->unique(['workspace_board_id', 'revision'], 'workspace_changes_revision_unique');
            $table->unique(['workspace_board_id', 'request_id'], 'workspace_changes_request_unique');
        });
        Schema::table('workspace_board_attachments', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('released_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('workspace_board_changes') && DB::table('workspace_board_changes')->exists()) {
            throw new RuntimeException('สำรองประวัติและหยุดการใช้งานกระดานก่อนย้อน migration นี้; สามารถย้อนโค้ดโดยคง schema เพิ่มเติมไว้ได้');
        }
        // Snapshots remain authoritative. Downgrading removes sync history, never board content or files.
        Schema::dropIfExists('workspace_board_changes');
        Schema::table('workspace_board_attachments', function (Blueprint $table): void {
            $table->dropIndex(['released_at']);
            $table->dropColumn(['claimed_at', 'released_at']);
        });
    }
};
