<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "ไม่ระบุเวลากำหนดส่ง" — งานที่มีวันครบกำหนด แต่ผู้ใช้ไม่ได้ตั้งเวลานัดจริง
 *
 * เจตนาเก็บที่ work_orders.job_due_time_unset ส่วน job_due_at ยังถือขณะเวลาไว้ที่
 * สิ้นวันทำการตามเวลาไทย เพื่อให้วงจรสถานะล่าช้าเทียบกับ now() ได้เหมือนเดิม
 * สองค่านี้ตอบคำถามต่างกัน เทสต์ชุดนี้จึงตรึงไว้ทั้งคู่ ไม่ใช่ค่าใดค่าเดียว
 */
class UnsetDueTimeTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'Technology']);
    }

    public function test_unset_due_time_stores_end_of_business_day_and_records_the_intent(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 17:00:00');

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), [
                'job_due_at' => '2026-10-15T17:00',
                'job_due_time_unset' => true,
            ])
            ->assertOk()
            ->assertJson([
                'job_due_at' => '2026-10-15',
                'job_due_time' => '23:59',
                'job_due_time_unset' => true,
            ]);

        $task->refresh();

        $this->assertTrue($task->job_due_time_unset);
        // 23:59 น. เวลาไทย = 16:59 UTC ของวันเดียวกัน
        $this->assertSame('2026-10-15 16:59:00', $task->job_due_at->toDateTimeString());
    }

    public function test_clearing_the_intent_restores_the_time_the_user_picked(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 17:00:00', ['job_due_time_unset' => true]);

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), [
                'job_due_at' => '2026-10-15T14:30',
                'job_due_time_unset' => false,
            ])
            ->assertOk()
            ->assertJson([
                'job_due_time' => '14:30',
                'job_due_time_unset' => false,
            ]);

        $task->refresh();

        $this->assertFalse($task->job_due_time_unset);
        $this->assertSame('14:30', TodayWorkspace::clockTime($task->job_due_at));
    }

    /**
     * endpoint ตารางเวลาเขียนทั้งวันเริ่มและกำหนดส่ง การแก้วันเริ่มจึงต้องพาเจตนาเดิมไปด้วย
     * ไม่เช่นนั้นผู้ใช้ที่เลื่อนวันเริ่มจะเห็นเวลากำหนดส่งโผล่กลับมาเองโดยไม่ได้ตั้ง
     */
    public function test_the_schedule_endpoint_keeps_the_intent_it_is_given(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, '2026-10-09 09:00:00', '2026-10-15 17:00:00', ['job_due_time_unset' => true]);

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $task), [
                'job_start_at' => '2026-10-10T09:00',
                'job_due_at' => '2026-10-15T23:59',
                'job_due_time_unset' => true,
            ])
            ->assertOk()
            ->assertJson([
                'job_due_at' => '2026-10-15',
                'job_due_time' => '23:59',
                'job_due_time_unset' => true,
            ]);

        $this->assertTrue($task->refresh()->job_due_time_unset);
    }

    /**
     * ผู้เรียกเก่าที่ยังไม่ส่งคีย์นี้มาต้องได้พฤติกรรมเดิมทุกอย่าง
     * ไม่ใช่เจตนาค้างจากการบันทึกครั้งก่อน
     */
    public function test_a_payload_without_the_key_saves_a_real_due_time(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 17:00:00', ['job_due_time_unset' => true]);

        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => '2026-10-16T09:30'])
            ->assertOk()
            ->assertJson(['job_due_time' => '09:30', 'job_due_time_unset' => false]);

        $this->assertFalse($task->refresh()->job_due_time_unset);
    }

    public function test_the_shared_label_separates_the_intent_from_the_stored_clock(): void
    {
        $user = $this->user();
        $unset = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 23:59:00', ['job_due_time_unset' => true]);
        $chosen = $this->task($user, '2026-10-09 09:00:00', '2026-10-15 23:59:00');

        // เวลาที่เก็บไว้เท่ากันเป๊ะ สิ่งที่ต่างคือเจตนา ไม่ใช่ค่าเวลา
        $this->assertSame(TodayWorkspace::UNSET_DUE_TIME_LABEL, TodayWorkspace::dueTimeLabel($unset));
        $this->assertSame('23:59 น.', TodayWorkspace::dueTimeLabel($chosen));
    }

    public function test_the_user_board_shows_the_intent_instead_of_the_stored_clock(): void
    {
        $user = $this->user();
        $project = WorkOrderList::create(['name' => 'โปรเจกต์ทดสอบ', 'user_id' => $user->id, 'priority' => 2]);
        $this->task($user, '2026-10-09 09:00:00', '2026-10-15 17:00:00', [
            'work_order_list_id' => $project->id,
            'job_due_time_unset' => true,
        ]);

        $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('data-due-time-unset="1"', false)
            // ช่องกรอกต้องประกาศตัวเลือกนี้ ไม่งั้นปฏิทินจะไม่แสดงแถวติ๊กให้เลย
            ->assertSee('data-time-optional', false)
            ->assertSee(TodayWorkspace::UNSET_DUE_TIME_LABEL)
            ->assertDontSee('23:59 น.');
    }

    /**
     * Workspace ของสมาชิกใช้ Blade ชุดเดียวกับหน้า "งานของฉัน"
     * การเพิ่ม UI ต้องไปถึงทุกบทบาท ไม่ใช่เฉพาะหน้าของผู้ใช้
     */
    public function test_the_admin_member_board_shows_the_same_intent(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $project = WorkOrderList::create(['name' => 'โปรเจกต์ทดสอบ', 'user_id' => $member->id, 'priority' => 2]);
        $this->task($member, '2026-10-09 09:00:00', '2026-10-15 17:00:00', [
            'work_order_list_id' => $project->id,
            'job_due_time_unset' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'view' => 'board']))
            ->assertOk()
            ->assertSee('data-due-time-unset="1"', false)
            ->assertSee('data-time-optional', false)
            ->assertSee(TodayWorkspace::UNSET_DUE_TIME_LABEL);
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

    /** $start และ $due เขียนเป็นเวลาไทย ให้ตรงกับสิ่งที่ผู้ใช้กรอกในหน้าจอ */
    private function task(User $owner, string $start, string $due, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => 'งานทดสอบเวลากำหนดส่ง',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'job_start_at' => Carbon::parse($start, TodayWorkspace::BUSINESS_TIMEZONE)->utc(),
            'job_due_at' => Carbon::parse($due, TodayWorkspace::BUSINESS_TIMEZONE)->utc(),
        ], $extra));
    }
}
