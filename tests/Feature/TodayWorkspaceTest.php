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

    public function test_today_workspace_uses_inclusive_active_range_and_persistent_statuses(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo(Carbon::parse('2026-08-18 10:00:00'));
        $today = $this->job($user, 2, '2026-08-16', '2026-08-20');
        $expired = $this->job($user, 2, '2026-08-10', '2026-08-17');
        $tomorrow = $this->job($user, 2, '2026-08-19', '2026-08-20');
        $paused = $this->job($user, 5, now()->subWeek(), now()->addWeek(), ['paused_at' => now()->subDays(3)]);
        $late = $this->job($user, 6, now()->subWeek(), now()->subDay(), ['late_at' => now()->subDays(2)]);
        $doneToday = $this->job($user, 4, now()->subWeek(), now()->subDay(), ['job_completed_at' => now()]);
        $doneYesterday = $this->job($user, 4, now()->subWeek(), now()->subDay(), ['job_completed_at' => now()->subDay()]);

        $ids = TodayWorkspace::tasks(WorkOrder::all())->pluck('job_id');

        $this->assertTrue($ids->contains($today->job_id));
        $this->assertTrue($ids->contains($paused->job_id));
        $this->assertTrue($ids->contains($late->job_id));
        $this->assertTrue($ids->contains($doneToday->job_id));
        $this->assertFalse($ids->contains($expired->job_id));
        $this->assertFalse($ids->contains($tomorrow->job_id));
        $this->assertFalse($ids->contains($doneYesterday->job_id));
    }

    public function test_multi_day_time_progress_is_inclusive_for_every_active_date(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $job = $this->job($user, 2, '2026-08-16', '2026-08-20', ['job_progress' => 37]);

        foreach ([
            16 => [1, 4, 'วันที่ 1/5 • เหลือ 4 วัน'],
            17 => [2, 3, 'วันที่ 2/5 • เหลือ 3 วัน'],
            18 => [3, 2, 'วันที่ 3/5 • เหลือ 2 วัน'],
            19 => [4, 1, 'วันที่ 4/5 • เหลือ 1 วัน'],
            20 => [5, 0, 'วันที่ 5/5 • ครบกำหนดวันนี้'],
        ] as $day => [$current, $remaining, $label]) {
            $this->travelTo(Carbon::parse("2026-08-{$day} 12:00:00"));
            $this->assertTrue(TodayWorkspace::tasks(collect([$job]))->contains('job_id', $job->job_id));
            $progress = TodayWorkspace::timeProgress($job);
            $this->assertSame('16–20 ส.ค. 2569', $progress['range_label']);
            $this->assertSame(5, $progress['total_days']);
            $this->assertSame($current, $progress['current_day']);
            $this->assertSame($remaining, $progress['remaining_days']);
            $this->assertSame($label, $progress['progress_label']);
        }

        $this->assertSame(37, (int) $job->fresh()->job_progress);
    }

    public function test_single_day_time_progress_uses_natural_due_label(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo(Carbon::parse('2026-08-20 12:00:00'));
        $job = $this->job($user, 2, '2026-08-20', '2026-08-20');

        $progress = TodayWorkspace::timeProgress($job);

        $this->assertSame('ครบกำหนดวันนี้', $progress['progress_label']);
        $this->assertStringNotContainsString('1/1', $progress['progress_label']);
    }

    public function test_bangkok_midnight_controls_due_today_and_late_transition(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $job = $this->job($user, 2, '2026-08-16', '2026-08-20');

        $this->travelTo(Carbon::parse('2026-08-20 16:59:59', 'UTC'));
        $dueTodayResponse = $this->actingAs($user)->get(route('mytasks.index'))->assertOk();
        $this->assertSame(2, (int) $job->fresh()->job_status);
        $this->assertTrue($dueTodayResponse->viewData('todayTasks')->contains('job_id', $job->job_id));
        $dueTodayResponse->assertSee('วันที่ 5/5 • ครบกำหนดวันนี้');

        $this->travelTo(Carbon::parse('2026-08-20 17:00:00', 'UTC'));
        $response = $this->actingAs($user)->get(route('mytasks.index'))->assertOk();
        $this->assertSame(6, (int) $job->fresh()->job_status);
        $this->assertSame(1, TodayWorkspace::overdueDays($job->fresh()));
        $response->assertSee('ล่าช้า 1 วัน');
    }

    public function test_time_progress_formats_cross_month_and_cross_year_ranges(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $crossMonth = $this->job($user, 2, '2026-08-30', '2026-09-02');
        $crossYear = $this->job($user, 2, '2026-12-30', '2027-01-02');

        $monthProgress = TodayWorkspace::timeProgress($crossMonth, Carbon::parse('2026-09-01 05:00:00', 'UTC'));
        $this->assertSame('30 ส.ค.–2 ก.ย. 2569', $monthProgress['range_label']);
        $this->assertSame(4, $monthProgress['total_days']);
        $this->assertSame(3, $monthProgress['current_day']);
        $this->assertSame(1, $monthProgress['remaining_days']);

        $yearProgress = TodayWorkspace::timeProgress($crossYear, Carbon::parse('2027-01-01 05:00:00', 'UTC'));
        $this->assertSame('30 ธ.ค. 2569–2 ม.ค. 2570', $yearProgress['range_label']);
        $this->assertSame(4, $yearProgress['total_days']);
        $this->assertSame(3, $yearProgress['current_day']);
        $this->assertSame(1, $yearProgress['remaining_days']);
    }

    public function test_completed_early_disappears_after_completion_day_while_paused_persists(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $completed = $this->job($user, 4, '2026-08-16', '2026-08-20', ['job_completed_at' => '2026-08-18 14:00:00']);
        $paused = $this->job($user, 5, '2026-08-16', '2026-08-20', ['paused_at' => '2026-08-17 09:00:00']);

        $this->travelTo(Carbon::parse('2026-08-18 16:00:00'));
        $this->assertTrue(TodayWorkspace::tasks(collect([$completed, $paused]))->contains('job_id', $completed->job_id));

        $this->travelTo(Carbon::parse('2026-08-19 16:00:00'));
        $ids = TodayWorkspace::tasks(collect([$completed, $paused]))->pluck('job_id');
        $this->assertFalse($ids->contains($completed->job_id));
        $this->assertTrue($ids->contains($paused->job_id));
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

    public function test_opening_today_workspace_activates_started_status_one_tasks_in_range(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $today = $this->job($user, 1, now()->subDay(), now()->addDay());
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
