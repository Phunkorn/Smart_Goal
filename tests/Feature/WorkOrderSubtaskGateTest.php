<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TaskStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * งานย่อยต้องเสร็จครบก่อนจึงจะปิดงานแม่หรือส่งตรวจได้
 *
 * งานย่อยคือ WorkOrder ที่มี parent_job_id มันจึงมี job_status ของตัวเอง
 * ก่อนหน้านี้ผู้ใช้กด "เสร็จแล้ว" ที่งานแม่ได้ทันทีทั้งที่งานย่อยยังกำลังทำอยู่
 * งานย่อยจึงค้างเป็นงานเปิดใต้งานที่ปิดไปแล้ว ทำให้บอร์ดและรายงานไม่ตรงความจริง
 *
 * กฎนี้เป็นกฎเนื้องาน ไม่ใช่เรื่องสิทธิ์ จึงบังคับกับ admin ที่ปรับสถานะข้ามขั้นด้วย
 */
class WorkOrderSubtaskGateTest extends TestCase
{
    use RefreshDatabase;

    private function department(): Department
    {
        return Department::create(['department_name' => 'IT']);
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
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ], $attributes));
    }

    private function subtask(WorkOrder $parent, int $status): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $parent->user_id,
            'created_by' => $parent->created_by,
            'assigned_by' => $parent->assigned_by,
            'department_id' => $parent->department_id,
            'parent_job_id' => $parent->job_id,
            'parent_sort_order' => 0,
            'job_topic' => 'งานย่อย',
            'job_priority' => 2,
            'job_status' => $status,
            'job_start_at' => $parent->job_start_at,
            'job_due_at' => $parent->job_due_at,
        ]);
    }

    public function test_owner_cannot_close_task_while_a_subtask_is_still_in_progress(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $this->department()->id]);
        $task = $this->task($owner);
        $this->subtask($task, 2);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_task_cannot_be_submitted_for_review_while_a_subtask_is_open(): void
    {
        $department = $this->department();
        $leader = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $worker = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        // งานที่มีผู้มอบหมายคนอื่นจึงมีขั้นตรวจสอบจริง ไม่ใช่งานของตัวเองที่ปิดเองได้
        $task = $this->task($worker, ['created_by' => $leader->id, 'assigned_by' => $leader->id]);
        $this->subtask($task, 2);

        $this->actingAs($worker)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_admin_status_override_is_blocked_by_open_subtasks_too(): void
    {
        $department = $this->department();
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $department->id]);
        $task = $this->task($owner);
        $this->subtask($task, 2);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_a_paused_subtask_still_counts_as_unfinished(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $this->department()->id]);
        $task = $this->task($owner);
        $this->subtask($task, 5);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_status');

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_task_closes_once_every_subtask_is_done(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $this->department()->id]);
        $task = $this->task($owner);
        $this->subtask($task, 4);
        $this->subtask($task, 4);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4])
            ->assertOk();

        $this->assertSame(4, (int) $task->fresh()->job_status);
    }

    public function test_a_task_without_subtasks_still_closes_immediately(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $this->department()->id]);
        $task = $this->task($owner);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 4])
            ->assertOk();

        $this->assertSame(4, (int) $task->fresh()->job_status);
    }

    public function test_reopening_a_closed_task_is_not_blocked_by_open_subtasks(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $this->department()->id]);
        $task = $this->task($owner, ['job_status' => 4, 'job_completed_at' => now()]);
        $this->subtask($task, 2);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 2, 'action' => 'reopen'])
            ->assertOk();

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_returning_a_task_for_rework_is_not_blocked_by_open_subtasks(): void
    {
        $department = $this->department();
        $leader = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $worker = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $task = $this->task($worker, [
            'created_by' => $leader->id,
            'assigned_by' => $leader->id,
            'job_status' => 3,
            'submitted_for_review_by' => $worker->id,
            'submitted_for_review_at' => now(),
        ]);
        $this->subtask($task, 2);

        $this->actingAs($leader)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 2, 'reason' => 'แก้หัวข้อ'])
            ->assertOk();

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_capabilities_report_subtask_counts_without_narrowing_allowed_statuses(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $this->department()->id]);
        $task = $this->task($owner);
        $this->subtask($task, 4);
        $this->subtask($task, 2);

        $capabilities = app(TaskStatusTransitionService::class)->capabilities($task->fresh(), $owner);

        $this->assertSame((int) $task->job_id, $capabilities['task_id']);
        $this->assertSame(2, $capabilities['child_count']);
        $this->assertSame(1, $capabilities['open_child_count']);
        /*
         * ด่านงานย่อยไม่ใช่เรื่องสิทธิ์ ถ้าไปตัด 4 ออกจาก allowed_statuses
         * ปุ่มจะถูก disable เงียบ ๆ โดยผู้ใช้ไม่รู้ว่าทำไม ซึ่งตรงข้ามกับสิ่งที่ต้องการ
         */
        $this->assertContains(4, $capabilities['allowed_statuses']);
    }
}
