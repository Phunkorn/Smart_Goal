<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MyTasksProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tasks_opens_without_legacy_subtask_queries_or_numeric_progress_routes(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $list = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Current project']);
        WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $list->id,
            'job_topic' => 'Current task',
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($owner)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertDontSee('data-progress-template', false)
            ->assertDontSee('data-field="progress"', false);

        $this->assertFalse(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'work_order_subtasks')));
        $this->assertFalse(Route::has('tasks.progress.store'));
        $this->assertFalse(Route::has('mytasks.subtasks.store'));
        $this->assertFalse(Route::has('mytasks.subtasks.update'));
        $this->assertFalse(Route::has('mytasks.subtasks.toggle'));
    }

    public function test_board_task_menu_only_targets_the_task_and_project_header_edit_remains_available(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $list = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Menu project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $task = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $list->id,
            'job_topic' => 'Menu task',
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $content = $this->actingAs($owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('data-board-edit-project', false)
            ->getContent();

        preg_match('/<article[^>]*data-board-task[^>]*data-task-id="'.$task->job_id.'"[^>]*>(.*?)<\/article>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'ไม่พบ task card ที่ต้องตรวจเมนู');
        $taskCard = $matches[0];

        $this->assertStringContainsString('bi-three-dots-vertical', $taskCard);
        $this->assertStringContainsString('แก้ไขชื่อรายการงาน', $taskCard);
        $this->assertStringContainsString('ลบรายการงาน', $taskCard);
        $this->assertStringContainsString('data-task-id="'.$task->job_id.'"', $taskCard);
        $this->assertStringContainsString('data-url="'.route('tasks.details.update', $task).'"', $taskCard);
        $this->assertStringContainsString('data-url="'.route('mytasks.destroy', $task).'"', $taskCard);
        $this->assertStringNotContainsString('แก้ไขโปรเจกต์', $taskCard);
        $this->assertStringNotContainsString(route('mytasks.lists.update', $list), $taskCard);

        $this->actingAs($owner)
            ->patchJson(route('tasks.details.update', $task), ['job_topic' => 'Renamed task item'])
            ->assertOk();
        $this->assertSame('Renamed task item', $task->fresh()->job_topic);
        $this->assertSame('Menu project', $list->fresh()->name);

        $this->actingAs($owner)
            ->deleteJson(route('mytasks.destroy', $task))
            ->assertOk();
        $this->assertSoftDeleted($task);
        $this->assertDatabaseHas('work_order_lists', ['id' => $list->id, 'name' => 'Menu project']);

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'Renamed project'])
            ->assertOk();
        $this->assertSame('Renamed project', $list->fresh()->name);
    }

    public function test_project_priority_and_attachments_belong_to_project_not_first_task(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $department = Department::create(['department_name' => 'IT']);
        $actor = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $this->actingAs($actor)
            ->post(route('mytasks.create'), [
                'project_name' => 'Project metadata',
                'job_topic' => 'First task',
                'user_id' => $actor->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'project_priority' => 3,
                'attachments' => [UploadedFile::fake()->image('brief.png')],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $job = WorkOrder::where('job_topic', 'First task')->firstOrFail();
        $project = $job->taskList()->with('attachments')->firstOrFail();

        $this->assertSame(3, $project->priority);
        $this->assertSame(2, (int) $job->job_priority);
        $this->assertSame($actor->id, $job->assigned_by);
        $this->assertCount(1, $project->attachments);
        $this->assertDatabaseMissing('job_images', ['job_id' => $job->job_id]);
        Storage::disk('local')->assertExists($project->attachments->first()->file_path);

        $projectId = $project->id;
        $attachmentId = $project->attachments->first()->id;
        $attachmentPath = $project->attachments->first()->file_path;
        $listCount = WorkOrderList::count();

        $this->actingAs($actor)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('Project metadata')
            ->assertSee('brief.png');

        $this->assertSame($projectId, $job->fresh()->work_order_list_id);
        $this->assertSame($listCount, WorkOrderList::count());
        $this->assertDatabaseHas('work_order_list_attachments', [
            'id' => $attachmentId,
            'work_order_list_id' => $projectId,
            'file_path' => $attachmentPath,
        ]);
    }

    public function test_same_department_assignment_makes_assignee_project_leader_and_list_owner(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $assignee = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'Same department task',
                'job_details' => 'details',
                'user_id' => $assignee->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'job_priority' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('requires_admin_review', false);

        $job = WorkOrder::where('job_topic', 'Same department task')->firstOrFail();

        $this->assertSame($assignee->id, $job->leader_user_id);
        $this->assertSame($actor->id, $job->assigned_by);
        $this->assertSame('approved', $job->approval_status);
        $this->assertSame($assignee->id, $job->taskList->user_id);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project_leader_assigned',
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->job_id,
        ]);
    }

    public function test_create_project_can_create_an_initial_job(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'Dashboard redesign',
                'job_topic' => 'Dashboard project',
                'user_id' => $actor->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'job_priority' => 2,
            ])
            ->assertCreated();

        $job = WorkOrder::where('job_topic', 'Dashboard project')->firstOrFail();

        $this->assertSame('Dashboard redesign', $job->taskList->name);
    }

    public function test_create_project_can_create_multiple_job_topics(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'โปรเจกต์ Dashboard Q3',
                'project_items' => [
                    [
                        'job_topic' => 'ออกแบบ Dashboard',
                        'job_details' => 'งานหลักชุดแรก',
                    ],
                    [
                        'job_topic' => 'ตั้งค่า Analytics',
                        'job_details' => 'งานหลักชุดสอง',
                    ],
                ],
                'user_id' => $actor->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'job_priority' => 2,
            ])
            ->assertCreated();

        $firstJob = WorkOrder::where('job_topic', 'ออกแบบ Dashboard')->firstOrFail();
        $secondJob = WorkOrder::where('job_topic', 'ตั้งค่า Analytics')->firstOrFail();

        $this->assertSame($firstJob->work_order_list_id, $secondJob->work_order_list_id);
        $this->assertSame('โปรเจกต์ Dashboard Q3', $firstJob->taskList->name);
    }

    public function test_project_owner_can_rename_project_and_collaborator_cannot(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $collaborator = User::factory()->create(['role' => 'user']);
        $list = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Old project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => null,
            'work_order_list_id' => $list->id,
            'job_topic' => 'Task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        $job->collaborators()->attach($collaborator->id, [
            'added_by' => $owner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($collaborator)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'New project'])
            ->assertOk()
            ->assertJsonPath('name', 'New project');

        $this->assertSame('New project', $list->fresh()->name);

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.update', $list), ['priority' => 3])
            ->assertOk()
            ->assertJsonPath('priority', 3);

        $this->assertSame(3, (int) $list->fresh()->priority);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'updated',
            'subject_type' => WorkOrderList::class,
            'subject_id' => $list->id,
        ]);
    }

    public function test_user_delete_on_admin_assigned_task_creates_delete_request_instead_of_deleting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'user']);
        $list = WorkOrderList::create([
            'user_id' => $assignee->id,
            'name' => 'Admin project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $job = WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $admin->id,
            'leader_user_id' => $assignee->id,
            'department_id' => null,
            'work_order_list_id' => $list->id,
            'job_topic' => 'Admin assigned',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($assignee)
            ->deleteJson(route('mytasks.destroy', $job->job_id))
            ->assertAccepted()
            ->assertJsonPath('delete_requested', true);

        $job->refresh();

        $this->assertNotNull($job->delete_requested_at);
        $this->assertSame($assignee->id, $job->delete_requested_by);
        $this->assertFalse($job->trashed());
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $admin->id,
            'work_order_id' => $job->job_id,
            'type' => 'delete_request',
        ]);
    }

    public function test_admin_can_reject_delete_request_and_requester_is_notified(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $requester = User::factory()->create(['role' => 'user']);
        $job = WorkOrder::create([
            'user_id' => $requester->id,
            'created_by' => $admin->id,
            'leader_user_id' => $requester->id,
            'department_id' => null,
            'work_order_list_id' => null,
            'job_topic' => 'Delete requested',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'delete_requested_by' => $requester->id,
            'delete_requested_at' => now(),
            'delete_request_reason' => 'Not needed',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.deleteRequest.reject', $job->job_id))
            ->assertOk();

        $job->refresh();

        $this->assertNull($job->delete_requested_by);
        $this->assertNull($job->delete_requested_at);
        $this->assertNull($job->delete_request_reason);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delete_request_rejected',
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->job_id,
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $requester->id,
            'work_order_id' => $job->job_id,
            'type' => 'delete_request_rejected',
        ]);
    }

    public function test_completed_task_fields_are_locked_for_user_and_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $list = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Closed project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => null,
            'work_order_list_id' => $list->id,
            'job_topic' => 'Closed task',
            'job_priority' => 2,
            'job_status' => 4,
            'approval_status' => 'approved',
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'job_completed_at' => now(),
        ]);

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'User rename'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->deleteJson(route('mytasks.lists.destroy', $list))
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('mytasks.updatePriority', $job->job_id), ['job_priority' => 3])
            ->assertForbidden();

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.update', $list), ['priority' => 3])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'Admin rename'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('mytasks.updatePriority', $job->job_id), ['job_priority' => 3])
            ->assertForbidden();

        $this->assertSame('Admin rename', $list->fresh()->name);
        $this->assertSame(2, (int) $job->fresh()->job_priority);
    }

    public function test_completed_status_always_persists_completion_timestamp(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $department->id,
            'job_topic' => 'Completed invariant',
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $job->update(['job_status' => 4]);

        $this->assertNotNull($job->fresh()->job_completed_at);
    }

    public function test_project_can_be_created_without_an_initial_job(): void
    {
        $owner = User::factory()->create(['role' => 'user']);

        $this->actingAs($owner)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'Empty project',
                'project_priority' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('job_id', null);

        $project = WorkOrderList::where('name', 'Empty project')->firstOrFail();
        $this->assertSame(3, (int) $project->priority);
        $this->assertSame(0, $project->workOrders()->count());
    }

    public function test_opening_my_tasks_never_absorbs_another_members_ungrouped_task(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $collaborator = User::factory()->create(['role' => 'user']);
        $collaboratorList = WorkOrderList::create([
            'user_id' => $collaborator->id,
            'name' => 'Collaborator own project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => null,
            'job_topic' => 'Ungrouped shared task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        $job->collaborators()->attach($collaborator->id, [
            'added_by' => $owner->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($collaborator)->get(route('mytasks.index'))->assertOk();

        $this->assertNull(
            $job->fresh()->work_order_list_id,
            'Opening My Tasks must not move an ungrouped task owned by someone else into the viewer project.'
        );
        $this->assertSame(0, $collaboratorList->workOrders()->count());
    }

    public function test_opening_my_tasks_keeps_own_ungrouped_task_in_the_general_bucket(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $ownList = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Owner project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => null,
            'job_topic' => 'Ungrouped own task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($owner)->get(route('mytasks.index'))->assertOk();

        $this->assertNull($job->fresh()->work_order_list_id);
        $this->assertSame(0, $ownList->workOrders()->count());
        $response->assertSee('งานทั่วไป');
        $response->assertSee('Ungrouped own task');
    }
}
