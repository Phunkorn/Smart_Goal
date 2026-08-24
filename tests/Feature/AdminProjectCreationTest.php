<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AdminProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_project_rejects_viewer_assignee_from_crafted_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'viewer', 'must_change_password' => false, 'is_active' => true]);

        $this->actingAs($admin)->postJson(route('admin.tasks.store'), [
            'project_name' => 'Invalid viewer project',
            'project_priority' => 2,
            'tasks' => [[
                'job_topic' => 'Viewer cannot own this task',
                'user_id' => $viewer->id,
                'job_start_at' => now()->format('Y-m-d H:i:s'),
                'job_due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tasks.0.user_id');

        $this->assertDatabaseMissing('work_order_lists', ['name' => 'Invalid viewer project']);
        $this->assertDatabaseMissing('work_orders', ['user_id' => $viewer->id]);
    }

    public function test_admin_creates_project_with_multiple_tasks_and_nested_records(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $department = Department::create(['department_name' => 'Operations']);
        $otherDepartment = Department::create(['department_name' => 'Finance']);
        $assigneeOne = $this->employee($department);
        $assigneeTwo = $this->employee($department);
        $collaborator = $this->employee($department);

        $response = $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'project_name' => 'Admin rollout',
            'project_priority' => 3,
            'project_attachments' => [UploadedFile::fake()->image('brief.jpg')],
            'tasks' => [
                [
                    'job_topic' => 'Prepare environment',
                    'job_details' => 'Provision dependencies',
                    'user_id' => $assigneeOne->id,
                    'department_id' => $otherDepartment->id,
                    'leader_user_id' => $collaborator->id,
                    'approval_status' => 'pending',
                    'job_priority' => 3,
                    'job_start_at' => now()->format('Y-m-d H:i:s'),
                    'job_due_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'collaborators' => [$collaborator->id],
                    'attachments' => [UploadedFile::fake()->image('task.jpg')],
                    'subtasks' => [
                        ['title' => 'Create credentials', 'details' => 'Use least privilege'],
                    ],
                ],
                [
                    'job_topic' => 'Deploy release',
                    'user_id' => $assigneeTwo->id,
                    'job_priority' => 2,
                    'job_start_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'job_due_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                    'collaborators' => [$collaborator->id],
                ],
            ],
        ]);

        $response->assertRedirect(route('board.index'))
            ->assertSessionHas('success', 'สร้างโปรเจกต์และมอบหมายงาน 2 งานสำเร็จ');

        $project = WorkOrderList::where('name', 'Admin rollout')->firstOrFail();
        $this->assertSame($admin->id, $project->user_id);
        $this->assertSame(3, $project->priority);
        $this->assertCount(1, $project->attachments);

        $firstTask = WorkOrder::where('job_topic', 'Prepare environment')->firstOrFail();
        $secondTask = WorkOrder::where('job_topic', 'Deploy release')->firstOrFail();
        $this->assertSame($project->id, $firstTask->work_order_list_id);
        $this->assertSame($project->id, $secondTask->work_order_list_id);
        $this->assertSame($admin->id, $firstTask->created_by);
        $this->assertSame($admin->id, $secondTask->created_by);
        $this->assertSame($admin->id, $firstTask->assigned_by);
        $this->assertSame($admin->id, $secondTask->assigned_by);
        $this->assertSame($assigneeOne->department_id, $firstTask->department_id);
        $this->assertSame('approved', $firstTask->approval_status);
        $this->assertSame($admin->id, $firstTask->approved_by);
        $this->assertSame($assigneeOne->id, $firstTask->leader_user_id);
        $this->assertSame('approved', $secondTask->approval_status);
        $this->assertSame($admin->id, $secondTask->approved_by);
        $this->assertSame($assigneeTwo->id, $secondTask->leader_user_id);
        $this->assertCount(1, $firstTask->subtasks);
        $this->assertCount(1, $firstTask->attachments);
        $this->assertSame('accepted', $firstTask->collaborators()->firstOrFail()->pivot->status);
        $this->assertSame('accepted', $secondTask->collaborators()->firstOrFail()->pivot->status);

        $this->assertSame(2, SystemNotification::where('work_order_id', $firstTask->job_id)->count());
        $this->assertSame(2, SystemNotification::where('work_order_id', $secondTask->job_id)->count());
        $this->assertSame(1, SystemNotification::where('work_order_id', $firstTask->job_id)->where('user_id', $collaborator->id)->count());
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $assigneeOne->id,
            'work_order_id' => $firstTask->job_id,
            'message' => $admin->name.' มอบหมายงาน '.chr(34).'Prepare environment'.chr(34).' ให้คุณ',
        ]);

        $listCount = WorkOrderList::count();
        $this->actingAs($assigneeOne)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('Admin rollout')
            ->assertSee('brief.jpg')
            ->assertSee('Prepare environment')
            ->assertSee('มอบหมายโดย '.$admin->name)
            ->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$firstTask->job_id])
            ->assertViewHas('manageableTaskLists', fn ($lists) => $lists->isEmpty())
            ->assertSee('data-board-toolbar hidden', false)
            ->assertDontSee('data-kanban-project', false)
            ->assertDontSee('data-add-in-group data-list-id="'.$project->id.'"', false);
        $this->assertSame($project->id, $firstTask->fresh()->work_order_list_id);
        $this->assertSame($project->id, $secondTask->fresh()->work_order_list_id);
        $this->assertSame($listCount, WorkOrderList::count());
        $this->assertCount(1, $project->fresh()->attachments);
        $this->postJson(route('mytasks.store'), [
            'job_topic' => 'Unauthorized project task',
            'work_order_list_id' => $project->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('work_orders', ['job_topic' => 'Unauthorized project task']);

        $this->actingAs($assigneeTwo)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('Admin rollout')
            ->assertSee('brief.jpg')
            ->assertSee('Deploy release')
            ->assertDontSee('Prepare environment');
        $this->assertSame($project->id, $firstTask->fresh()->work_order_list_id);
        $this->assertSame($project->id, $secondTask->fresh()->work_order_list_id);
        $this->assertSame($listCount, WorkOrderList::count());
        $this->assertCount(1, $project->fresh()->attachments);

        foreach ([$firstTask, $secondTask] as $task) {
            $this->assertDatabaseHas('activity_logs', [
                'action' => 'created',
                'subject_type' => WorkOrder::class,
                'subject_id' => $task->job_id,
            ]);
        }
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'created',
            'subject_type' => WorkOrderList::class,
            'subject_id' => $project->id,
        ]);
    }

    public function test_admin_project_requires_at_least_one_task_and_creates_nothing_on_validation_failure(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'project_name' => 'Empty project',
            'project_priority' => 2,
            'tasks' => [],
        ])->assertSessionHasErrors('tasks');

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_multiple_tasks_for_same_assignee_keep_one_admin_project_after_opening_my_tasks(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $department = Department::create(['department_name' => 'Support']);
        $assignee = $this->employee($department);

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'project_name' => 'Shared support project',
            'project_priority' => 2,
            'tasks' => [
                $this->taskPayload('First support task', $assignee),
                $this->taskPayload('Second support task', $assignee),
            ],
        ])->assertRedirect(route('board.index'));

        $project = WorkOrderList::where('name', 'Shared support project')->firstOrFail();
        $taskIds = WorkOrder::where('work_order_list_id', $project->id)->pluck('job_id');
        $this->assertCount(2, $taskIds);

        $this->actingAs($assignee)->get(route('mytasks.index'))->assertOk();

        $this->assertDatabaseCount('work_order_lists', 1);
        $this->assertSame(2, WorkOrder::whereIn('job_id', $taskIds)->where('work_order_list_id', $project->id)->count());
    }

    public function test_collaborators_must_be_unique_per_task_and_must_not_include_assignee(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $department = Department::create(['department_name' => 'Compliance']);
        $assignee = $this->employee($department);
        $collaborator = $this->employee($department);

        $duplicatePayload = $this->taskPayload('Duplicate collaborators', $assignee);
        $duplicatePayload['collaborators'] = [$collaborator->id, $collaborator->id];

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'project_name' => 'Invalid collaborators',
            'project_priority' => 2,
            'tasks' => [$duplicatePayload],
        ])->assertSessionHasErrors('tasks.0.collaborators');

        $assigneePayload = $this->taskPayload('Assignee collaborator', $assignee);
        $assigneePayload['collaborators'] = [$assignee->id];

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'project_name' => 'Invalid assignee collaborator',
            'project_priority' => 2,
            'tasks' => [$assigneePayload],
        ])->assertSessionHasErrors('tasks.0.collaborators');

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_quick_add_defaults_to_an_owned_list_and_never_renders_for_the_admin_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $department = Department::create(['department_name' => 'Customer Success']);
        $assignee = $this->employee($department);

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'project_name' => 'Admin project',
            'project_priority' => 2,
            'tasks' => [$this->taskPayload('Admin task', $assignee)],
        ])->assertRedirect(route('board.index'));

        $adminProject = WorkOrderList::where('name', 'Admin project')->firstOrFail();
        $ownedProject = WorkOrderList::create([
            'user_id' => $assignee->id,
            'name' => 'Owned project',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 99,
        ]);

        $response = $this->actingAs($assignee)->get(route('mytasks.index'))
            ->assertOk()
            ->assertViewHas('taskLists', fn ($lists) => $lists->contains('id', $adminProject->id) && $lists->contains('id', $ownedProject->id))
            ->assertViewHas('manageableTaskLists', fn ($lists) => $lists->pluck('id')->all() === [$ownedProject->id])
            ->assertDontSee('data-add-in-group data-list-id="'.$adminProject->id.'"', false);

        $response->assertDontSee('data-kanban-project', false)
            ->assertDontSee('class="mytasks-kanban__toolbar"', false);

        $this->postJson(route('mytasks.store'), ['job_topic' => 'Owned quick task'])
            ->assertCreated();
        $this->assertDatabaseHas('work_orders', [
            'job_topic' => 'Owned quick task',
            'work_order_list_id' => $ownedProject->id,
            'assigned_by' => $assignee->id,
        ]);
    }

    public function test_failed_project_graph_removes_only_files_written_by_the_request(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $department = Department::create(['department_name' => 'QA']);
        $assignee = $this->employee($department);
        Storage::disk('public')->put('existing/keep.txt', 'keep');

        WorkOrder::creating(function (WorkOrder $workOrder) {
            if ($workOrder->job_topic === 'Force rollback') {
                throw new RuntimeException('Forced failure after attachments were stored');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post(route('admin.tasks.store'), [
                'project_name' => 'Rollback project',
                'project_priority' => 2,
                'project_attachments' => [UploadedFile::fake()->image('project.jpg')],
                'tasks' => [
                    array_merge($this->taskPayload('Stored before failure', $assignee), [
                        'attachments' => [UploadedFile::fake()->image('task.jpg')],
                    ]),
                    $this->taskPayload('Force rollback', $assignee),
                ],
            ]);
            $this->fail('Expected the forced creation failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced failure after attachments were stored', $exception->getMessage());
        }

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_list_attachments', 0);
        $this->assertDatabaseCount('job_images', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Storage::disk('public')->assertExists('existing/keep.txt');
        $this->assertSame(['existing/keep.txt'], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function employee(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function taskPayload(string $topic, User $assignee): array
    {
        return [
            'job_topic' => $topic,
            'user_id' => $assignee->id,
            'job_priority' => 2,
            'job_start_at' => now()->format('Y-m-d H:i:s'),
            'job_due_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ];
    }
}
