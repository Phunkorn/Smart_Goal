<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * "ไม่มีกำหนดส่ง" — งานที่ไม่มีวันครบกำหนดเลย เก็บเป็น work_orders.job_due_at = null
 *
 * คนละเรื่องกับ "ไม่ระบุเวลากำหนดส่ง" (UnsetDueTimeTest) ซึ่งยังมีวันส่งและยังล่าช้าได้
 * งานที่ไม่มีกำหนดส่งไม่มีขณะเวลาให้วงจรสถานะล่าช้าเทียบ จึงต้องไม่ถูกดันเป็นล่าช้าเอง
 * และผู้เรียกเก่าที่ไม่รู้จักคีย์ job_due_unset ต้องได้พฤติกรรมเดิมทุกอย่าง
 */
class NoDueDateTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'Technology']);
        // 12 ต.ค. 2569 10:00 น. เวลาไทย
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', TodayWorkspace::BUSINESS_TIMEZONE)->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_due_date_endpoint_clears_the_due_date_and_the_time_intent(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 23:59:00', ['job_due_time_unset' => true]);

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), [
                // ปฏิทินยังส่งวันเดิมที่ค้างในช่องมาด้วย ซึ่งต้องไม่ถูกเก็บ
                'job_due_at' => '2026-10-15T23:59',
                'job_due_unset' => true,
                'job_due_time_unset' => true,
            ])
            ->assertOk()
            ->assertJson([
                'job_due_at' => '',
                'job_due_time' => '',
                'job_due_unset' => true,
                'job_due_time_unset' => false,
            ]);

        $task->refresh();

        $this->assertNull($task->job_due_at);
        $this->assertFalse($task->job_due_time_unset, 'งานที่ไม่มีวันส่งไม่มีเวลาให้ "ไม่ระบุ"');
        $this->assertTrue(TodayWorkspace::dueIsUnset($task));
    }

    public function test_the_due_date_endpoint_accepts_the_intent_without_any_date(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 17:00:00');

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), ['job_due_unset' => true])
            ->assertOk()
            ->assertJson(['job_due_unset' => true]);

        $this->assertNull($task->refresh()->job_due_at);
    }

    /**
     * endpoint ตารางเวลาเขียนทั้งวันเริ่มและกำหนดส่ง วันเดิมที่ค้างในช่องอาจอยู่ก่อนวันเริ่มใหม่
     * กติกา "กำหนดส่งต้องไม่ก่อนวันเริ่ม" จึงต้องไม่ถูกใช้กับค่าที่กำลังจะถูกทิ้ง
     */
    public function test_the_schedule_endpoint_clears_the_due_date_and_keeps_the_start(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, '2026-10-09 09:00:00', '2026-10-15 17:00:00');

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $task), [
                'job_start_at' => '2026-10-20T09:00',
                'job_due_at' => '2026-10-15T17:00',
                'job_due_unset' => true,
            ])
            ->assertOk()
            ->assertJson([
                'job_start_at' => '2026-10-20',
                'job_start_time' => '09:00',
                'job_due_at' => '',
                'job_due_unset' => true,
                'job_due_time_unset' => false,
            ]);

        $task->refresh();

        $this->assertNull($task->job_due_at);
        $this->assertSame('2026-10-20', TodayWorkspace::calendarDate($task->job_start_at));
    }

    public function test_setting_a_due_date_again_brings_the_task_back(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-10-09 09:00:00', null);

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), [
                'job_due_at' => '2026-10-20T14:30',
                'job_due_unset' => false,
            ])
            ->assertOk()
            ->assertJson([
                'job_due_at' => '2026-10-20',
                'job_due_time' => '14:30',
                'job_due_unset' => false,
            ]);

        $this->assertSame('14:30', TodayWorkspace::clockTime($task->refresh()->job_due_at));
    }

    /**
     * ผู้เรียกเก่าที่ยังไม่ส่งคีย์นี้มาต้องได้พฤติกรรมเดิมทุกอย่าง
     * รวมถึงยังถูกปฏิเสธเมื่อไม่ส่งกำหนดส่งมา ไม่ใช่กลายเป็นงานไม่มีกำหนดส่งเงียบ ๆ
     */
    public function test_a_payload_without_the_key_still_requires_and_saves_a_due_date(): void
    {
        $user = $this->user();
        $admin = $this->user('admin');
        $task = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 17:00:00');

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_due_at');

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), ['job_due_unset' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_due_at');

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $task), ['job_start_at' => '2026-10-09T09:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_due_at');

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $task), [
                'job_start_at' => '2026-10-16T09:00',
                'job_due_at' => '2026-10-15T17:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_due_at');

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => '2026-10-16T09:30'])
            ->assertOk()
            ->assertJson(['job_due_at' => '2026-10-16', 'job_due_time' => '09:30', 'job_due_unset' => false]);

        $this->assertNotNull($task->refresh()->job_due_at);
    }

    public function test_a_late_task_returns_to_its_active_status_once_it_has_no_due_date(): void
    {
        $user = $this->user();
        $late = $this->task($user, '2026-10-01 09:00:00', '2026-10-05 17:00:00', [
            'job_status' => 6,
            'late_at' => now(),
        ]);
        $pausedThenLate = $this->task($user, '2026-10-01 09:00:00', '2026-10-05 17:00:00', [
            'job_status' => 6,
            'late_at' => now(),
            'paused_at' => now()->subDays(8),
        ]);

        foreach ([$late, $pausedThenLate] as $task) {
            $this->actingAs($user)
                ->postJson(route('mytasks.updateDueDate', $task), ['job_due_unset' => true])
                ->assertOk();
        }

        $this->assertSame(2, (int) $late->refresh()->job_status);
        $this->assertNull($late->late_at);
        // งานที่ถูกพักไว้ก่อนล่าช้าต้องกลับไป "พักงาน" ไม่ใช่ถูกปลุกมาทำงานเอง
        $this->assertSame(5, (int) $pausedThenLate->refresh()->job_status);
    }

    public function test_the_late_cycle_never_touches_a_task_without_a_due_date(): void
    {
        $user = $this->user();
        $noDue = $this->task($user, '2026-09-01 09:00:00', null);
        $overdue = $this->task($user, '2026-09-01 09:00:00', '2026-10-05 17:00:00');

        TodayWorkspace::synchronizeLate(WorkOrder::query());

        $this->assertSame(2, (int) $noDue->refresh()->job_status);
        $this->assertFalse(TodayWorkspace::isOverdue($noDue));
        $this->assertSame(0, TodayWorkspace::overdueDays($noDue));
        // ตัวเทียบ: งานที่มีกำหนดส่งและเลยมาแล้วยังถูกดันเป็นล่าช้าเหมือนเดิม
        $this->assertSame(6, (int) $overdue->refresh()->job_status);
    }

    public function test_a_task_without_a_due_date_stays_in_today_from_its_start_date_on(): void
    {
        $user = $this->user();
        $started = $this->task($user, '2026-10-01 09:00:00', null, ['job_topic' => 'เริ่มแล้ว ไม่มีกำหนดส่ง']);
        $future = $this->task($user, '2026-10-20 09:00:00', null, ['job_topic' => 'ยังไม่ถึงวันเริ่ม']);

        $today = TodayWorkspace::tasks(WorkOrder::query()->get())->pluck('job_id')->all();

        $this->assertContains($started->job_id, $today);
        $this->assertNotContains($future->job_id, $today);
    }

    public function test_someone_who_cannot_work_on_the_task_cannot_clear_its_due_date(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $viewer = $this->user('viewer');
        $task = $this->task($owner, '2026-10-09 09:00:00', '2026-10-15 17:00:00');

        foreach ([$stranger, $viewer] as $actor) {
            $response = $this->actingAs($actor)
                ->postJson(route('mytasks.updateDueDate', $task), ['job_due_unset' => true]);
            $this->assertContains($response->getStatusCode(), [403, 404]);

            $response = $this->actingAs($actor)
                ->patchJson(route('tasks.schedule.update', $task), [
                    'job_start_at' => '2026-10-09T09:00',
                    'job_due_unset' => true,
                ]);
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }

        $this->assertNotNull($task->refresh()->job_due_at);
    }

    public function test_a_subtask_inherits_no_due_date_from_its_parent(): void
    {
        $user = $this->user();
        $parent = $this->task($user, '2026-10-09 09:00:00', null);

        $this->actingAs($user)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'งานย่อยของงานที่ไม่มีกำหนดส่ง'])
            ->assertSuccessful();

        $child = WorkOrder::query()->where('parent_job_id', $parent->job_id)->firstOrFail();

        $this->assertNull($child->job_due_at, 'งานย่อยต้องไม่ได้วันเริ่มมาเป็นกำหนดส่ง แล้วล่าช้าทันที');
    }

    public function test_the_user_board_offers_the_option_and_shows_the_shared_label(): void
    {
        $user = $this->user();
        $project = WorkOrderList::create(['name' => 'โปรเจกต์ทดสอบ', 'user_id' => $user->id, 'priority' => 2]);
        $this->task($user, '2026-10-09 09:00:00', null, ['work_order_list_id' => $project->id]);

        foreach (['board', 'table'] as $view) {
            $this->actingAs($user)
                ->get(route('mytasks.index', ['view' => $view]))
                ->assertOk()
                // ช่องกรอกต้องประกาศตัวเลือกนี้ ไม่งั้นปฏิทินจะไม่แสดงแถวติ๊กให้เลย
                ->assertSee('data-due-optional', false)
                ->assertSee('data-due-unset="1"', false)
                ->assertSee(TodayWorkspace::NO_DUE_LABEL);
        }
    }

    /**
     * Workspace ของสมาชิกใช้ Blade ชุดเดียวกับหน้า "งานของฉัน"
     * การเพิ่ม UI ต้องไปถึงทุกบทบาท ไม่ใช่เฉพาะหน้าของผู้ใช้
     */
    public function test_the_admin_member_board_shows_the_same_label(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $project = WorkOrderList::create(['name' => 'โปรเจกต์ทดสอบ', 'user_id' => $member->id, 'priority' => 2]);
        $this->task($member, '2026-10-09 09:00:00', null, ['work_order_list_id' => $project->id]);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'view' => 'board']))
            ->assertOk()
            ->assertSee('data-due="" ', false)
            ->assertSee(TodayWorkspace::NO_DUE_LABEL);
    }

    public function test_the_migration_refuses_to_roll_back_while_tasks_have_no_due_date(): void
    {
        $user = $this->user();
        $this->task($user, '2026-10-09 09:00:00', null);

        $migration = require database_path('migrations/2026_10_12_000001_allow_work_orders_without_due_date.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $this->department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /** $start และ $due เขียนเป็นเวลาไทย ให้ตรงกับสิ่งที่ผู้ใช้กรอกในหน้าจอ — $due เป็น null คือไม่มีกำหนดส่ง */
    private function task(User $owner, string $start, ?string $due, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => 'งานทดสอบไม่มีกำหนดส่ง',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'job_start_at' => Carbon::parse($start, TodayWorkspace::BUSINESS_TIMEZONE)->utc(),
            'job_due_at' => $due === null ? null : Carbon::parse($due, TodayWorkspace::BUSINESS_TIMEZONE)->utc(),
        ], $extra));
    }
}
