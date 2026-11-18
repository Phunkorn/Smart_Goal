<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TaskStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ผู้ดูแลระบบ (admin) จัดการงานได้ครบ — ปิดงาน (รวมงานล่าช้าที่มีงานย่อยค้าง) และแก้ไขงานที่ปิดแล้ว
 *
 * กติกาจากเจ้าของระบบ:
 *   - admin ปิดงานแม่ที่ยังมีงานย่อยค้างได้ โดยระบบปิดงานย่อยที่ค้างทั้งหมดไปพร้อมกัน
 *     หลังจากที่ admin ยืนยันอย่างชัดแจ้ง (ธง close_subtasks)
 *   - admin แก้ไขรายละเอียด กำหนดส่ง คอมเมนต์ และจัดการงานย่อยของงานที่ปิดแล้วได้
 *     โดยไม่ต้องเปิดงานอีกครั้ง ส่วน role อื่นยังถูกล็อกเหมือนเดิม
 *   - งานที่ยังรออนุมัติยังต้องอนุมัติก่อน แม้เป็น admin
 */
class AdminFullTaskControlTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::create(['department_name' => 'IT']);
    }

    private function person(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'department_id' => $this->department->id]);
    }

    private function task(User $owner, array $attributes = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => 'งานแม่',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subWeek(),
            'job_due_at' => now()->addDay(),
        ], $attributes));
    }

    private function subtask(WorkOrder $parent, int $status, array $attributes = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $parent->user_id,
            'created_by' => $parent->created_by,
            'assigned_by' => $parent->assigned_by,
            'department_id' => $parent->department_id,
            'parent_job_id' => $parent->job_id,
            'parent_sort_order' => 0,
            'job_topic' => 'งานย่อย',
            'job_priority' => 2,
            'job_status' => $status,
            'approval_status' => 'approved',
            'job_start_at' => $parent->job_start_at,
            'job_due_at' => $parent->job_due_at,
        ], $attributes));
    }

    public function test_admin_closes_a_late_task_together_with_every_open_subtask(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $late = $this->task($owner, ['job_status' => 6, 'job_due_at' => now()->subDay(), 'late_at' => now()]);
        $inProgress = $this->subtask($late, 2);
        $paused = $this->subtask($late, 5, ['paused_at' => now()]);
        $done = $this->subtask($late, 4, ['job_completed_at' => now()->subDay()]);
        $grandchild = $this->subtask($inProgress, 2);

        $response = $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $late->job_id), ['job_status' => 4, 'close_subtasks' => true])
            ->assertOk()
            ->assertJsonPath('job_status', 4);

        foreach ([$late, $inProgress, $paused, $grandchild] as $task) {
            $fresh = $task->fresh();
            $this->assertSame(4, (int) $fresh->job_status, $task->job_topic.' #'.$task->job_id.' must be closed');
            $this->assertSame($admin->id, $fresh->final_approved_by);
            $this->assertNotNull($fresh->job_completed_at);
            $this->assertNull($fresh->paused_at);
            $this->assertNull($fresh->late_at);
        }

        // งานย่อยที่ปิดไปก่อนแล้วต้องไม่ถูกแตะ
        $this->assertNotSame($admin->id, $done->fresh()->final_approved_by);

        $closedIds = collect($response->json('closed_subtasks'))->pluck('job_id')->sort()->values()->all();
        $this->assertSame(
            collect([$inProgress->job_id, $paused->job_id, $grandchild->job_id])->map(fn ($id) => (int) $id)->sort()->values()->all(),
            $closedIds
        );
        $this->assertTrue(collect($response->json('closed_subtasks'))->every(fn ($item) => $item['job_status'] === 4));

        // ทุกใบมีประวัติของตัวเอง
        $this->assertSame(4, ActivityLog::where('action', 'admin_status_overridden')->where('user_id', $admin->id)->count());
    }

    public function test_admin_still_needs_the_explicit_flag_to_close_open_subtasks(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $task = $this->task($owner);
        $child = $this->subtask($task, 2);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
        $this->assertSame(2, (int) $child->fresh()->job_status);
    }

    public function test_a_regular_owner_cannot_close_open_subtasks_with_the_flag(): void
    {
        $owner = $this->person();
        $task = $this->task($owner);
        $child = $this->subtask($task, 2);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4, 'close_subtasks' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
        $this->assertSame(2, (int) $child->fresh()->job_status);
    }

    public function test_the_flag_never_lets_anyone_submit_for_review_past_open_subtasks(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $task = $this->task($owner);
        $child = $this->subtask($task, 2);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 3, 'close_subtasks' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
        $this->assertSame(2, (int) $child->fresh()->job_status);
    }

    public function test_an_unapproved_subtask_blocks_the_whole_close_and_nothing_is_half_closed(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $task = $this->task($owner);
        $open = $this->subtask($task, 2);
        $pending = $this->subtask($task, 2, ['approval_status' => 'pending']);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4, 'close_subtasks' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
        $this->assertSame(2, (int) $open->fresh()->job_status);
        $this->assertSame(2, (int) $pending->fresh()->job_status);
    }

    public function test_capabilities_offer_closing_with_subtasks_to_admin_only(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $task = $this->task($owner);
        $this->subtask($task, 2);
        $service = app(TaskStatusTransitionService::class);

        $this->assertTrue($service->capabilities($task->fresh(), $admin)['can_close_with_subtasks']);
        $this->assertFalse($service->capabilities($task->fresh(), $owner)['can_close_with_subtasks']);

        $closed = $this->task($owner, ['job_status' => 4, 'job_completed_at' => now()]);
        $this->assertFalse($service->capabilities($closed, $admin)['can_close_with_subtasks']);
    }

    public function test_admin_edits_a_closed_task_without_reopening_it(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $closed = $this->task($owner, ['job_status' => 4, 'job_completed_at' => now()]);

        $this->actingAs($admin)
            ->patchJson(route('tasks.details.update', $closed->job_id), ['job_topic' => 'ชื่อใหม่โดยผู้ดูแล'])
            ->assertOk();
        $this->assertSame('ชื่อใหม่โดยผู้ดูแล', $closed->fresh()->job_topic);

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $closed->job_id), [
                'job_start_at' => now()->subWeek()->toDateString(),
                'job_due_at' => now()->addWeek()->toDateString(),
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('tasks.comments.store', $closed), ['message' => 'บันทึกเพิ่มเติมหลังปิดงาน'])
            ->assertSuccessful();

        $this->actingAs($admin)
            ->postJson(route('mytasks.details.store', $closed), ['title' => 'งานย่อยที่ผู้ดูแลเพิ่ม'])
            ->assertSuccessful();
        $this->assertTrue(WorkOrder::where('parent_job_id', $closed->job_id)->where('job_topic', 'งานย่อยที่ผู้ดูแลเพิ่ม')->exists());

        // สถานะยังปิดอยู่ — การเปลี่ยนสถานะของงานที่ปิดแล้วยังต้องผ่าน "เปิดงานอีกครั้ง"
        $this->assertSame(4, (int) $closed->fresh()->job_status);
    }

    public function test_a_closed_task_stays_locked_for_its_owner_and_for_viewers(): void
    {
        $owner = $this->person();
        $viewer = $this->person('viewer');
        $closed = $this->task($owner, ['job_status' => 4, 'job_completed_at' => now()]);

        foreach ([$owner, $viewer] as $actor) {
            $this->assertFalse($actor->can('work', $closed));
            $this->assertFalse($actor->can('update', $closed));
            $this->assertFalse($actor->can('comment', $closed));
            $this->assertFalse($actor->can('manageSubtasks', $closed));
        }

        $this->actingAs($owner)
            ->patchJson(route('tasks.details.update', $closed->job_id), ['job_topic' => 'ไม่ควรเปลี่ยน'])
            ->assertForbidden();
        $this->assertSame('งานแม่', $closed->fresh()->job_topic);
    }

    /** สิทธิ์ที่หน้า Workspace ส่งให้ปุ่มสถานะ (JSON data-task-management-data) ของงานหนึ่งใบ */
    private function workspaceTransitions(string $html, WorkOrder $task): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-task-management-data>(.*?)<\/script>/s', $html, $match));

        return json_decode($match[1], true)[(string) $task->job_id]['transitions'];
    }

    /*
     * จุดที่ผู้ใช้ทดสอบแล้วพบว่าปิดงานไม่ได้ — Workspace ของสมาชิก (บอร์ดงาน → แผนก → สมาชิก)
     *
     * หน้านี้เคยบังคับอ่านอย่างเดียวกับ admin ด้วย ปุ่มสถานะของงานล่าช้าจึงกดไม่ได้ทุกตัว
     * ทั้งที่ policy และ endpoint อนุญาต admin อยู่แล้ว
     */
    public function test_admin_can_close_a_late_task_from_the_member_workspace(): void
    {
        $member = $this->person();
        $admin = $this->person('admin');
        $late = $this->task($member, ['job_status' => 6, 'job_due_at' => now()->subMonth(), 'late_at' => now()]);
        $this->subtask($late, 2);

        $html = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->getContent();

        $transitions = $this->workspaceTransitions($html, $late);
        $this->assertTrue($transitions['can_edit']);
        $this->assertTrue($transitions['can_admin_override']);
        $this->assertContains(4, $transitions['allowed_statuses']);
        $this->assertTrue($transitions['can_close_with_subtasks']);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $late->job_id), ['job_status' => 4, 'close_subtasks' => true])
            ->assertOk()
            ->assertJsonPath('job_status', 4);
        $this->assertSame(4, (int) $late->fresh()->job_status);
    }

    public function test_the_department_head_view_of_a_member_workspace_stays_read_only(): void
    {
        $member = $this->person();
        $head = User::factory()->create([
            'role' => 'user',
            'is_department_head' => true,
            'is_active' => true,
            'department_id' => $this->department->id,
        ]);
        $late = $this->task($member, ['job_status' => 6, 'job_due_at' => now()->subMonth(), 'late_at' => now()]);

        $html = $this->actingAs($head)
            ->get(route('work-board.member', [$this->department, $member, 'workspace' => 1]))
            ->assertOk()
            ->getContent();

        $transitions = $this->workspaceTransitions($html, $late);
        $this->assertFalse($transitions['can_edit']);
        $this->assertSame([6], $transitions['allowed_statuses']);
    }

    public function test_admin_still_has_to_approve_a_pending_task_before_working_on_it(): void
    {
        $owner = $this->person();
        $admin = $this->person('admin');
        $pending = $this->task($owner, ['approval_status' => 'pending']);

        $this->assertFalse($admin->can('work', $pending));
        $this->assertFalse($admin->can('overrideStatus', $pending));
        $this->assertFalse($admin->can('closeWithSubtasks', $pending));
        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $pending->job_id), ['job_status' => 4])
            ->assertForbidden();
    }
}
