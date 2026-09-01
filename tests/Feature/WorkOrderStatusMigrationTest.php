<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_moves_every_legacy_status_one_row_and_changes_only_the_default_on_rollback(): void
    {
        $user = User::factory()->create();
        $unchangedTimestamp = '2026-08-30 09:00:00';
        $base = [
            'user_id' => $user->id,
            'job_priority' => 2,
            'job_start_at' => '2026-08-31 09:00:00',
            'job_due_at' => '2026-09-01 09:00:00',
            'created_at' => $unchangedTimestamp,
            'updated_at' => $unchangedTimestamp,
        ];

        $activeId = DB::table('work_orders')->insertGetId([
            ...$base,
            'job_topic' => 'Legacy active task',
            'job_status' => 2,
        ], 'job_id');
        $deletedId = DB::table('work_orders')->insertGetId([
            ...$base,
            'job_topic' => 'Legacy soft-deleted task',
            'job_status' => 2,
            'deleted_at' => '2026-08-30 10:00:00',
        ], 'job_id');

        DB::table('work_orders')
            ->whereIn('job_id', [$activeId, $deletedId])
            ->update(['job_status' => 1]);

        $migration = require database_path('migrations/2026_08_31_000001_remove_todo_status_from_work_orders.php');

        try {
            $migration->up();

            $this->assertSame(2, (int) DB::table('work_orders')->where('job_id', $activeId)->value('job_status'));
            $this->assertSame(2, (int) DB::table('work_orders')->where('job_id', $deletedId)->value('job_status'));
            $this->assertSame($unchangedTimestamp, DB::table('work_orders')->where('job_id', $activeId)->value('updated_at'));

            $defaultTwoId = DB::table('work_orders')->insertGetId([
                ...$base,
                'job_topic' => 'Default status two',
            ], 'job_id');
            $this->assertSame(2, (int) DB::table('work_orders')->where('job_id', $defaultTwoId)->value('job_status'));

            $migration->down();

            $this->assertSame(2, (int) DB::table('work_orders')->where('job_id', $activeId)->value('job_status'));
            $this->assertSame(2, (int) DB::table('work_orders')->where('job_id', $deletedId)->value('job_status'));

            $defaultOneId = DB::table('work_orders')->insertGetId([
                ...$base,
                'job_topic' => 'Rollback default status one',
            ], 'job_id');
            $this->assertSame(1, (int) DB::table('work_orders')->where('job_id', $defaultOneId)->value('job_status'));
        } finally {
            $migration->up();
        }
    }
}
