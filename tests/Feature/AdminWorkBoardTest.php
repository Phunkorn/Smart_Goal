<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWorkBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_follow_department_member_workspace_without_impersonating_member(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', $department, 'Admin Owner');
        $member = $this->user('user', $department, 'Member A');
        $otherMember = $this->user('user', $department, 'Member B');
        $collaborator = $this->user('user', $department, 'Collaborator X');
        $project = WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'Admin Project',
            'priority' => 3,
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $project->attachments()->create([
            'file_path' => 'project-attachments/'.$project->id.'/brief.docx',
            'original_name' => 'brief.docx',
            'file_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'uploaded_by' => $admin->id,
        ]);
        $memberTask = $this->task($project, $admin, $member, 'Member task');
        $memberTask->subtasks()->create([
            'created_by' => $admin->id,
            'title' => 'Prepare checklist',
            'details' => 'Check every item',
            'sort_order' => 1,
        ]);
        $memberTask->collaborators()->attach($collaborator->id, [
            'added_by' => $admin->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $otherTask = $this->task($project, $admin, $otherMember, 'Other member task');

        $this->actingAs($admin)
            ->get(route('admin.work-board.department', $department))
            ->assertOk()
            ->assertSee($department->department_name)
            ->assertSee($member->name)
            ->assertSee(route('admin.work-board.member', [$department, $member]), false);

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()
            ->assertSee('Admin Project')
            ->assertSee('Member task')
            ->assertSee('brief.docx')
            ->assertSee('Collaborator X')
            ->assertSee('0/1 งานย่อย')
            ->assertSee('มอบหมายโดย Admin Owner')
            ->assertSee('data-kanban-card', false)
            ->assertSee('data-board-task', false)
            ->assertDontSee('data-create-modal', false)
            ->assertDontSee('data-create-form', false)
            ->assertDontSee('data-group>', false)
            ->assertSee(route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]));

        $response->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$memberTask->job_id]);

        $this->assertSame($admin->id, auth()->id());
        $this->assertSame($project->id, $memberTask->fresh()->work_order_list_id);
        $this->assertSame($project->id, $otherTask->fresh()->work_order_list_id);
        $this->assertDatabaseCount('work_order_lists', 1);
        $this->assertStringContainsString('data-task-id="'.$memberTask->job_id.'"', $response->getContent());
    }

    public function test_admin_workspace_routes_are_authorized_and_validate_department_membership(): void
    {
        $first = Department::create(['department_name' => 'IT']);
        $second = Department::create(['department_name' => 'Finance']);
        $admin = $this->user('admin', $first, 'Admin');
        $user = $this->user('user', $first, 'User');
        $otherDepartmentUser = $this->user('user', $second, 'Other');

        $this->actingAs($user)->get(route('admin.work-board.department', $first))->assertForbidden();
        $this->actingAs($user)->get(route('admin.work-board.member', [$first, $user]))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.work-board.member', [$first, $otherDepartmentUser]))->assertNotFound();
    }

    public function test_mixed_creators_use_task_markers_and_user_created_task_has_no_admin_marker(): void
    {
        $department = Department::create(['department_name' => 'QA']);
        $adminOne = $this->user('admin', $department, 'Admin One');
        $adminTwo = $this->user('admin', $department, 'Admin Two');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $adminOne->id, 'name' => 'Mixed Project', 'priority' => 2]);

        $this->task($project, $adminOne, $member, 'Admin one task');
        $this->task($project, $adminTwo, $member, 'Admin two task');
        $this->task($project, $member, $member, 'User-created task');

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('มอบหมายโดย Admin One')
            ->assertSee('มอบหมายโดย Admin Two')
            ->assertDontSee('มอบหมายโดย Member');
    }

    public function test_admin_board_has_visible_assignment_entry_point_and_can_preselect_member(): void
    {
        $department = Department::create(['department_name' => 'Support']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');

        $this->actingAs($admin)
            ->get(route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]))
            ->assertOk()
            ->assertSee('data-open-admin-assignment', false)
            ->assertSee('boardCreateTaskModal', false)
            ->assertSee(route('admin.work-board.department', $department), false)
            ->assertSee((string) $member->id, false);
    }

    private function user(string $role, Department $department, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function task(WorkOrderList $project, User $creator, User $assignee, string $topic): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_details' => 'Task details',
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'approved_by' => $creator->role === 'admin' ? $creator->id : null,
            'approved_at' => now(),
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
