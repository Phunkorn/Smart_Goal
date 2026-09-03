<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderSubtask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderTaskDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_task_creation_stores_repeatable_details_and_renders_them_on_board(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $this->actingAs($owner)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'งานอบรม',
                'job_topic' => 'เตรียมของ',
                'subtasks' => ['ซื้ออุปกรณ์', '', 'จัดชุดเอกสาร'],
                'user_id' => $owner->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'job_priority' => 2,
            ])
            ->assertCreated();

        $task = WorkOrder::where('job_topic', 'เตรียมของ')->firstOrFail();

        $this->assertSame(
            ['ซื้ออุปกรณ์', 'จัดชุดเอกสาร'],
            $task->subtasks()->pluck('title')->all()
        );

        $this->actingAs($owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('data-task-details-toggle', false)
            ->assertSee('data-detail-project-target="1"', false)
            ->assertSee('ซื้ออุปกรณ์')
            ->assertSee('จัดชุดเอกสาร');
    }

    public function test_owner_can_create_edit_move_reorder_and_delete_task_details(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $firstProject = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $secondProject = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project B']);
        $source = $this->task($owner, $firstProject, 'Source task');
        $target = $this->task($owner, $secondProject, 'Target task');
        $existing = WorkOrderSubtask::create([
            'work_order_id' => $target->job_id,
            'created_by' => $owner->id,
            'title' => 'Existing target detail',
            'sort_order' => 0,
        ]);

        $createdResponse = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $source), ['title' => 'New detail'])
            ->assertCreated()
            ->assertJsonPath('detail.work_order_id', $source->job_id);

        $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $source), ['title' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $detail = WorkOrderSubtask::findOrFail($createdResponse->json('detail.id'));

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.update', $detail), ['title' => 'Renamed detail'])
            ->assertOk()
            ->assertJsonPath('detail.title', 'Renamed detail');

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.move', $detail), [
                'target_work_order_id' => $target->job_id,
                'position' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('target.project_id', $secondProject->id);

        $this->assertSame($target->job_id, $detail->fresh()->work_order_id);
        $this->assertSame(
            [$detail->id, $existing->id],
            $target->subtasks()->pluck('id')->all()
        );

        $this->actingAs($owner)
            ->deleteJson(route('mytasks.details.destroy', $detail))
            ->assertOk();

        $this->assertDatabaseMissing('work_order_subtasks', ['id' => $detail->id]);
        $this->assertSame(0, $existing->fresh()->sort_order);
    }

    public function test_detail_cannot_be_changed_or_moved_to_a_task_outside_the_users_access(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $outsider = User::factory()->create(['role' => 'user']);
        $ownerProject = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Owner project']);
        $outsiderProject = WorkOrderList::create(['user_id' => $outsider->id, 'name' => 'Outsider project']);
        $source = $this->task($owner, $ownerProject, 'Owner task');
        $target = $this->task($outsider, $outsiderProject, 'Private task');
        $detail = WorkOrderSubtask::create([
            'work_order_id' => $source->job_id,
            'created_by' => $owner->id,
            'title' => 'Protected detail',
            'sort_order' => 0,
        ]);

        $this->actingAs($outsider)
            ->patchJson(route('mytasks.details.update', $detail), ['title' => 'No access'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.move', $detail), [
                'target_work_order_id' => $target->job_id,
            ])
            ->assertForbidden();

        $this->assertSame($source->job_id, $detail->fresh()->work_order_id);
        $this->assertSame('Protected detail', $detail->fresh()->title);
    }

    private function task(User $owner, WorkOrderList $project, string $topic): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
