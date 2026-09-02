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
        $job = $this->job($user, 2, '2026-08-16', '2026-08-20');

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

    public function test_status_one_is_rejected_by_both_active_status_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $task = $this->job($user, 2, now(), now()->addDay());

        $this->actingAs($user)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');

        $this->actingAs($user)
            ->postJson(route('mytasks.updateStatus', $task), ['job_status' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
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

    public function test_opening_today_workspace_preserves_status_two_for_current_and_future_tasks(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $today = $this->job($user, 2, now()->subDay(), now()->addDay());
        $future = $this->job($user, 2, now()->addDay(), now()->addDays(2));

        $response = $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(2, (int) $today->fresh()->job_status);
        $this->assertSame(2, (int) $future->fresh()->job_status);
        $response->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$today->job_id]);
        $response->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->contains($future->job_id));
    }

    public function test_user_my_tasks_renders_calendar_without_removing_today_or_full_task_sources(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $today = $this->job($user, 2, now(), now()->addDay());
        $future = $this->job($user, 2, now()->addMonth(), now()->addMonth()->addDays(3));

        $response = $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $response
            ->assertSee('data-view="table"', false)
            ->assertSee('data-view="board"', false)
            ->assertSee('data-view="calendar"', false)
            ->assertSee('data-calendar', false)
            ->assertSee('data-calendar-month', false)
            ->assertSee('data-calendar-year', false)
            ->assertSee('data-calendar-reset', false)
            ->assertSee('data-calendar-detail', false)
            ->assertDontSee('data-calendar-detail-edit', false)
            ->assertDontSee('data-calendar-detail-timeline', false)
            ->assertSee('data-project-board', false)
            ->assertSee('data-table-kanban', false)
            ->assertDontSee('data-kanban-column='.chr(34).'1'.chr(34), false)
            ->assertDontSee('data-modal-status-value='.chr(34).'1'.chr(34), false)
            ->assertDontSee('ยังไม่เริ่ม')
            ->assertSee('data-workspace-task-source', false)
            // overlay ใช้ theme scope (sg-task-theme) ไม่ใช่ page-layout class
            // เพื่อไม่ให้ width/margin ของหน้ารั่วลงมาบีบ backdrop
            ->assertSee('class="task-workspace-modal notion-modal sg-task-theme" data-task-modal hidden', false)
            ->assertDontSee('task-workspace-modal notion-modal my-tasks-page', false)
            ->assertSee('class="task-workspace"', false)
            ->assertDontSee('data-task-subtasks', false)
            ->assertDontSee('data-add-subtask', false)
            ->assertDontSee('data-delete-active-task', false)
            ->assertDontSee('data-modal-progress', false)
            ->assertSee('data-reopen-task', false)
            ->assertSee('data-id="'.$future->job_id.'"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-task-modal'));
        $response->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$today->job_id]);
        $response->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->contains($future->job_id));
    }

    public function test_pending_task_is_never_auto_marked_late(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $started = $this->job($user, 2, now()->subDay(), now()->addDay(), ['approval_status' => 'pending']);
        $overdue = $this->job($user, 2, now()->subWeek(), now()->subDay(), ['approval_status' => 'pending']);

        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(2, (int) $started->fresh()->job_status);
        $this->assertSame(2, (int) $overdue->fresh()->job_status);
        $this->assertNull($overdue->fresh()->late_at);
    }

    public function test_rejected_task_is_never_auto_marked_late(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $started = $this->job($user, 2, now()->subDay(), now()->addDay(), ['approval_status' => 'rejected']);
        $overdue = $this->job($user, 2, now()->subWeek(), now()->subDay(), ['approval_status' => 'rejected']);

        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(2, (int) $started->fresh()->job_status);
        $this->assertSame(2, (int) $overdue->fresh()->job_status);
        $this->assertNull($overdue->fresh()->late_at);
    }

    public function test_approved_doing_task_is_auto_marked_late_when_overdue(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $started = $this->job($user, 2, now()->subDay(), now()->addDay());
        $overdue = $this->job($user, 2, now()->subWeek(), now()->subDay());

        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(2, (int) $started->fresh()->job_status);
        $this->assertSame(6, (int) $overdue->fresh()->job_status);
        $this->assertNotNull($overdue->fresh()->late_at);
    }

    public function test_late_status_reconciles_after_authorized_due_or_schedule_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        $resumed = $this->job($user, 6, now()->subWeek(), now()->subDay(), ['late_at' => now()]);
        $this->actingAs($admin)
            ->postJson(route('mytasks.updateDueDate', $resumed), ['job_due_at' => now()->addDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('job_status', 2);
        $this->assertSame(2, (int) $resumed->fresh()->job_status);
        $this->assertNull($resumed->fresh()->late_at);

        $rescheduled = $this->job($user, 6, now()->subWeek(), now()->subDay(), ['late_at' => now()]);
        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $rescheduled), [
                'job_start_at' => now()->addDays(2)->toDateString(),
                'job_due_at' => now()->addDays(4)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('job_status', 2);
        $this->assertSame(2, (int) $rescheduled->fresh()->job_status);
        $this->assertNull($rescheduled->fresh()->late_at);

        $stillLate = $this->job($user, 6, now()->subWeek(), now()->subDays(2), ['late_at' => now()]);
        $this->actingAs($admin)
            ->postJson(route('mytasks.updateDueDate', $stillLate), ['job_due_at' => now()->subDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('job_status', 6);
        $this->assertNotNull($stillLate->fresh()->late_at);

        $newlyLate = $this->job($user, 2, now()->subWeek(), now()->addDay());
        $this->actingAs($admin)
            ->postJson(route('mytasks.updateDueDate', $newlyLate), ['job_due_at' => now()->subDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('job_status', 6);
        $this->assertNotNull($newlyLate->fresh()->late_at);
    }

    public function test_admin_cannot_override_late_to_active_while_schedule_is_still_overdue(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $late = $this->job($user, 6, now()->subWeek(), now()->subDay(), ['late_at' => now()]);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $late), ['job_status' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');
        $this->assertSame(6, (int) $late->fresh()->job_status);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $late), ['job_status' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');
        $this->assertSame(6, (int) $late->fresh()->job_status);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $late), ['job_status' => 5])
            ->assertOk()
            ->assertJsonPath('job_status', 5);
        $this->assertNull($late->fresh()->late_at);
        $this->assertNotNull($late->fresh()->paused_at);
    }

    public function test_pending_overdue_task_only_enters_the_lifecycle_after_admin_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $job = $this->job($user, 2, now()->subWeek(), now()->subDay(), ['approval_status' => 'pending']);

        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();
        $this->assertSame(2, (int) $job->fresh()->job_status);

        $this->actingAs($admin)
            ->patch(route('admin.tasks.approval', $job->job_id), ['approval_status' => 'approved'])
            ->assertRedirect();

        $this->assertSame('approved', $job->fresh()->approval_status);
        $this->assertSame(2, (int) $job->fresh()->job_status);

        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();
        $this->assertSame(6, (int) $job->fresh()->job_status);
    }

    public function test_date_range_label_works_regardless_of_task_status_or_today(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $done = $this->job($user, 4, '2026-08-16', '2026-08-20', ['job_completed_at' => '2026-08-18 10:00:00']);
        $future = $this->job($user, 2, '2026-09-01', '2026-09-01');

        // ไม่ต้อง travelTo เพราะ dateRangeLabel ต้องไม่ขึ้นกับ "วันนี้" เหมือน timeProgress()
        $this->assertSame('16–20 ส.ค. 2569', TodayWorkspace::dateRangeLabel($done->job_start_at, $done->job_due_at));
        $this->assertSame('1 ก.ย. 2569', TodayWorkspace::dateRangeLabel($future->job_start_at, $future->job_due_at));
        $this->assertNull(TodayWorkspace::dateRangeLabel(null, $future->job_due_at));
        $this->assertNull(TodayWorkspace::dateRangeLabel($future->job_start_at, null));
    }

    /**
     * Regression: synchronizeLate() เคยกรองเฉพาะ job_status = 2 งานที่ถูก "พักงาน"
     * ค้างไว้จนเลยกำหนดส่งจึงไม่เคยกลายเป็นล่าช้า การพักงานจึงเป็นช่องหลบสถานะล่าช้า
     */
    public function test_paused_task_becomes_late_once_it_passes_its_due_date(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $pausedInTime = $this->job($user, 5, now()->subDay(), now()->addDay(), ['paused_at' => now()->subDay()]);
        $pausedOverdue = $this->job($user, 5, now()->subWeek(), now()->subDay(), ['paused_at' => now()->subWeek()]);

        $this->actingAs($user)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(5, (int) $pausedInTime->fresh()->job_status);
        $this->assertSame(6, (int) $pausedOverdue->fresh()->job_status);
        $this->assertNotNull($pausedOverdue->fresh()->late_at);
    }

    /**
     * งานที่ล่าช้าเพราะถูกพักไว้ ต้องกลับไป "พักงาน" เมื่อกำหนดส่งถูกเลื่อนออก
     * ไม่ใช่ถูกปลุกมาเป็นกำลังทำเอง — paused_at คือหลักฐานว่าเดิมงานอยู่สถานะใด
     */
    public function test_late_task_that_was_paused_returns_to_paused_after_the_due_date_moves(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        $wasPaused = $this->job($user, 6, now()->subWeek(), now()->subDay(), [
            'late_at' => now(), 'paused_at' => now()->subWeek(),
        ]);
        $wasDoing = $this->job($user, 6, now()->subWeek(), now()->subDay(), ['late_at' => now()]);

        foreach ([$wasPaused, $wasDoing] as $task) {
            $this->actingAs($admin)
                ->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => now()->addDay()->toDateString()])
                ->assertOk();
        }

        $this->assertSame(5, (int) $wasPaused->fresh()->job_status);
        $this->assertSame(2, (int) $wasDoing->fresh()->job_status);
        $this->assertNull($wasPaused->fresh()->late_at);
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
            'job_start_at' => $start,
            'job_due_at' => $due,
        ], $extra));
    }
}
