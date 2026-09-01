<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: ปุ่ม "เพิ่มโปรเจกต์" เคยหายไปทั้งระบบ เพราะ toolbar เดิมใน
 * tasks/partials/table-kanban.blade.php ถูกครอบด้วย @if(false) ทั้งชุด
 * ทำให้ modal สร้างโปรเจกต์ยังถูก render แต่ไม่มีปุ่มใดเปิดได้เลย
 *
 * ชุดทดสอบนี้คุมทั้งสองด้าน: ปุ่มต้องกลับมา และ toolbar เดิมต้องยังปิดอยู่
 */
class MyTasksCreateProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tasks_renders_exactly_one_create_task_trigger(): void
    {
        $user = $this->member();

        $content = $this->actingAs($user)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-open-user-task-create', false)
            ->assertSee('สร้างงาน')
            ->getContent();

        $this->assertSame(
            1,
            substr_count($content, 'data-open-user-task-create'),
            'หน้างานของฉันต้องมีปุ่มเปิด modal สร้างโปรเจกต์เพียงปุ่มเดียว'
        );
    }

    public function test_create_task_modal_and_backend_contract_are_rendered(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-user-task-create-modal', false)
            ->assertSee('data-user-task-create-form', false)
            ->assertSee('data-create-url="'.route('mytasks.create').'"', false);
    }

    public function test_admin_member_workspace_has_no_create_project_trigger(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->member('admin', $department);
        $member = $this->member('user', $department);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()
            ->assertDontSee('data-open-user-task-create', false)
            ->assertDontSee('data-create-modal', false);
    }

    public function test_legacy_kanban_toolbar_stays_disabled(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertDontSee('mytasks-kanban__toolbar', false)
            ->assertDontSee('data-kanban-project', false)
            ->assertDontSee('data-kanban-project-priority', false)
            ->assertDontSee('data-add-in-group', false);
    }

    public function test_create_project_endpoint_still_creates_project_and_first_task(): void
    {
        Storage::fake('local');
        $user = $this->member();

        $this->actingAs($user)
            ->post(route('mytasks.create'), [
                'project_name' => 'โปรเจกต์กู้คืนปุ่ม',
                'job_topic' => 'งานแรกของโปรเจกต์',
                'user_id' => $user->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'project_priority' => 2,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $project = WorkOrderList::where('name', 'โปรเจกต์กู้คืนปุ่ม')->firstOrFail();
        $job = WorkOrder::where('job_topic', 'งานแรกของโปรเจกต์')->firstOrFail();

        $this->assertSame($user->id, $project->user_id);
        $this->assertSame($project->id, $job->work_order_list_id);
    }

    public function test_create_task_modal_can_use_an_existing_owned_project(): void
    {
        $user = $this->member();
        $project = WorkOrderList::create([
            'user_id' => $user->id,
            'name' => 'Existing project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->postJson(route('mytasks.create'), [
                'work_order_list_id' => $project->id,
                'job_topic' => 'Task from one-step modal',
                'job_start_at' => now()->format('Y-m-d H:i:s'),
                'job_due_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'job_priority' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('list_id', $project->id);

        $task = WorkOrder::where('job_topic', 'Task from one-step modal')->firstOrFail();
        $this->assertSame($project->id, $task->work_order_list_id);
        $this->assertSame($user->id, $task->user_id);
        $this->assertSame(3, (int) $task->job_priority);
    }

    public function test_user_cannot_create_task_inside_another_users_project(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $project = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Private project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($intruder)
            ->postJson(route('mytasks.create'), [
                'work_order_list_id' => $project->id,
                'job_topic' => 'Forbidden task',
                'job_start_at' => now()->format('Y-m-d H:i:s'),
                'job_due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('work_orders', ['job_topic' => 'Forbidden task']);
    }

    private function member(string $role = 'user', ?Department $department = null): User
    {
        $department = $department ?: Department::create(['department_name' => 'IT '.uniqid()]);

        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }
}
