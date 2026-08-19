<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ครอบคลุมกติกาการอนุมัติของ POST /tasks (TaskController::store()) ให้ตรงกับ
 * MyTaskController::store() ซึ่งเป็นต้นแบบที่ถูกต้อง หลังรวม logic เข้า
 * WorkOrderApprovalResolver
 */
class TaskControllerApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_assigning_within_same_department_via_tasks_is_approved_immediately(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = $this->userInDepartment($department);
        $assignee = $this->userInDepartment($department);

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee))
            ->assertRedirect(route('mytasks.index'));

        $job = WorkOrder::where('job_topic', 'Same department task via /tasks')->firstOrFail();

        $this->assertSame('approved', $job->approval_status);
        $this->assertSame($actor->id, $job->approved_by);
        $this->assertNotNull($job->approved_at);
        $this->assertSame($assignee->id, $job->leader_user_id);
    }

    public function test_user_assigning_cross_department_via_tasks_is_pending_and_notifies_all_admins(): void
    {
        $deptA = Department::create(['department_name' => 'IT']);
        $deptB = Department::create(['department_name' => 'HR']);

        $actor = $this->userInDepartment($deptA);
        $assignee = $this->userInDepartment($deptB);
        $adminOne = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $adminTwo = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee, 'Cross department task via /tasks'))
            ->assertRedirect(route('mytasks.index'));

        $job = WorkOrder::where('job_topic', 'Cross department task via /tasks')->firstOrFail();

        $this->assertSame('pending', $job->approval_status);
        $this->assertNull($job->approved_by);
        $this->assertNull($job->approved_at);
        $this->assertSame($actor->id, $job->leader_user_id);

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $adminOne->id,
            'work_order_id' => $job->job_id,
            'type' => 'cross_department_pending',
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $adminTwo->id,
            'work_order_id' => $job->job_id,
            'type' => 'cross_department_pending',
        ]);
        $this->assertSame(
            2,
            SystemNotification::where('work_order_id', $job->job_id)
                ->where('type', 'cross_department_pending')
                ->count()
        );
    }

    public function test_admin_created_task_via_tasks_is_always_approved_regardless_of_department(): void
    {
        $deptA = Department::create(['department_name' => 'IT']);
        $deptB = Department::create(['department_name' => 'HR']);

        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $deptA->id, 'must_change_password' => false, 'is_active' => true]);
        $assignee = $this->userInDepartment($deptB);

        $this->actingAs($admin)
            ->post(route('tasks.store'), $this->payload($assignee, 'Admin created task via /tasks'))
            ->assertRedirect(route('board.index'));

        $job = WorkOrder::where('job_topic', 'Admin created task via /tasks')->firstOrFail();

        $this->assertSame('approved', $job->approval_status);
        $this->assertSame($admin->id, $job->approved_by);
        $this->assertNotNull($job->approved_at);
        $this->assertSame($assignee->id, $job->leader_user_id);
    }

    public function test_viewer_cannot_be_assigned_through_task_creation_endpoints_or_picker(): void
    {
        $department = Department::create(['department_name' => 'Management']);
        $actor = $this->userInDepartment($department);
        $admin = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $viewer = $this->userInDepartment($department, 'viewer');

        $this->actingAs($actor)
            ->postJson(route('tasks.store'), $this->payload($viewer, 'Crafted viewer assignment'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), $this->payload($viewer, 'Crafted My Tasks viewer assignment'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseMissing('work_orders', ['user_id' => $viewer->id]);

        $this->actingAs($admin)
            ->get(route('board.index'))
            ->assertOk()
            ->assertSee('class="assignee-option" data-id="'.$actor->id.'"', false)
            ->assertDontSee('class="assignee-option" data-id="'.$viewer->id.'"', false);
    }

    private function userInDepartment(Department $department, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function payload(User $assignee, string $topic = 'Same department task via /tasks'): array
    {
        return [
            'job_topic' => $topic,
            'user_id' => $assignee->id,
            'job_start_at' => now()->format('Y-m-d'),
            'job_due_at' => now()->addDay()->format('Y-m-d'),
            'job_priority' => 2,
        ];
    }
}
