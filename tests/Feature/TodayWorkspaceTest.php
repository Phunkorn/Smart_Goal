<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\TodayWorkspace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_workspace_uses_exact_start_date_and_persistent_statuses(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $today = $this->job($user, 2, now(), now()->addDay());
        $yesterday = $this->job($user, 2, now()->subDay(), now()->addDay());
        $tomorrow = $this->job($user, 2, now()->addDay(), now()->addDays(2));
        $paused = $this->job($user, 5, now()->subWeek(), now()->addWeek(), ['paused_at' => now()->subDays(3)]);
        $late = $this->job($user, 6, now()->subWeek(), now()->subDay(), ['late_at' => now()->subDays(2)]);
        $doneToday = $this->job($user, 4, now()->subWeek(), now()->subDay(), ['job_completed_at' => now()]);
        $doneYesterday = $this->job($user, 4, now()->subWeek(), now()->subDay(), ['job_completed_at' => now()->subDay()]);

        $ids = TodayWorkspace::tasks(WorkOrder::all())->pluck('job_id');

        $this->assertTrue($ids->contains($today->job_id));
        $this->assertTrue($ids->contains($paused->job_id));
        $this->assertTrue($ids->contains($late->job_id));
        $this->assertTrue($ids->contains($doneToday->job_id));
        $this->assertFalse($ids->contains($yesterday->job_id));
        $this->assertFalse($ids->contains($tomorrow->job_id));
        $this->assertFalse($ids->contains($doneYesterday->job_id));
    }

    public function test_overdue_task_becomes_late_and_can_only_transition_to_done(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $job = $this->job($user, 2, now()->subWeek(), now()->subDay());

        $this->actingAs($user)->patchJson(route('tasks.updateStatus', $job), ['job_status' => 3])->assertStatus(422);

        $this->assertSame(6, (int) $job->fresh()->job_status);
        $this->assertNotNull($job->fresh()->late_at);

        $this->actingAs($user)->patchJson(route('tasks.updateStatus', $job), ['job_status' => 4])->assertOk();

        $this->assertSame(4, (int) $job->fresh()->job_status);
        $this->assertNotNull($job->fresh()->job_completed_at);

        $myTasksJob = $this->job($user, 2, now()->subWeek(), now()->subDay());
        $this->actingAs($user)
            ->postJson(route('mytasks.updateStatus', $myTasksJob), ['job_status' => 2])
            ->assertStatus(422);
        $this->assertSame(6, (int) $myTasksJob->fresh()->job_status);
        $this->assertNotNull($myTasksJob->fresh()->late_at);
    }

    public function test_pause_timestamp_tracks_the_latest_pause_and_clears_when_resumed(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $job = $this->job($user, 2, now(), now()->addWeek());
        $firstPause = Carbon::parse('2026-08-19 09:00:00');
        $secondPause = Carbon::parse('2026-08-21 14:30:00');

        $this->travelTo($firstPause);
        $this->actingAs($user)->patchJson(route('tasks.updateStatus', $job), ['job_status' => 5])->assertOk();
        $this->assertTrue($job->fresh()->paused_at->equalTo($firstPause));

        $this->actingAs($user)->patchJson(route('tasks.updateStatus', $job), ['job_status' => 2])->assertOk();
        $this->assertNull($job->fresh()->paused_at);

        $this->travelTo($secondPause);
        $this->actingAs($user)->patchJson(route('tasks.updateStatus', $job), ['job_status' => 5])->assertOk();
        $this->assertTrue($job->fresh()->paused_at->equalTo($secondPause));
    }

    public function test_opening_today_workspace_activates_only_status_one_tasks_starting_today(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $today = $this->job($user, 1, now(), now()->addDay());
        $future = $this->job($user, 1, now()->addDay(), now()->addDays(2));

        $response = $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(2, (int) $today->fresh()->job_status);
        $this->assertSame(1, (int) $future->fresh()->job_status);
        $response->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$today->job_id]);
        $response->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->contains($future->job_id));
    }

    public function test_user_my_tasks_renders_calendar_without_removing_today_or_full_task_sources(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $today = $this->job($user, 2, now(), now()->addDay());
        $future = $this->job($user, 1, now()->addMonth(), now()->addMonth()->addDays(3));

        $response = $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $response
            ->assertSee('data-view="table"', false)
            ->assertSee('data-view="board"', false)
            ->assertSee('data-view="calendar"', false)
            ->assertSee('data-calendar', false)
            ->assertSee('data-project-board', false)
            ->assertSee('data-table-kanban', false)
            ->assertSee('data-workspace-task-source', false)
            ->assertSee('data-id="'.$future->job_id.'"', false);
        $response->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$today->job_id]);
        $response->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->contains($future->job_id));
    }

    private function job(User $user, int $status, $start, $due, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'leader_user_id' => $user->id,
            'job_topic' => 'Workspace task '.uniqid(),
            'job_priority' => 2,
            'job_status' => $status,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => $start,
            'job_due_at' => $due,
        ], $extra));
    }
}
