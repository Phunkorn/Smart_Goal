<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectCollaboratorPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_collaborator_sees_every_task_in_the_project_but_not_other_projects(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner, 'Project A');
        $anchor = $this->task($owner, $project, 'Anchor task');
        $otherTask = $this->task($owner, $project, 'Visible project task');
        $hiddenTask = $this->task($owner, $this->project($owner, 'Project B'), 'Hidden project task');
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $this->actingAs($collaborator)->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->assertSee($project->name)
            ->assertSee($otherTask->job_topic)
            ->assertDontSee($hiddenTask->job_topic);

        $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $otherTask))->assertOk();
        $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $hiddenTask))->assertForbidden();
    }

    public function test_pending_rejected_and_removed_collaborators_have_no_project_level_visibility(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $owner = $this->user();
            $collaborator = $this->user();
            $project = $this->project($owner, 'Project '.$status);
            $anchor = $this->task($owner, $project, 'Anchor '.$status);
            $other = $this->task($owner, $project, 'Private '.$status);
            $anchor->collaborators()->attach($collaborator->id, ['status' => $status]);

            $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $other))->assertForbidden();
        }

        $owner = $this->user();
        $removed = $this->user();
        $project = $this->project($owner, 'Removed project');
        $anchor = $this->task($owner, $project, 'Removed anchor');
        $other = $this->task($owner, $project, 'Removed private');
        $anchor->collaborators()->attach($removed->id, ['status' => 'accepted']);
        $anchor->collaborators()->detach($removed->id);
        $this->actingAs($removed)->get(route('mytasks.quickview.task', $other))->assertForbidden();
    }

    public function test_collaborator_only_access_is_read_only_for_task_mutations(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $task = $this->task($owner, $project);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $this->actingAs($collaborator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2])->assertForbidden();
        $this->actingAs($collaborator)->patchJson(route('tasks.details.update', $task), ['job_topic' => 'No'])->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('tasks.progress.store', $task), ['note' => 'No'])->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('tasks.attachments.store', $task))->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('mytasks.updatePriority', $task), ['job_priority' => 3])->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => now()->addDays(2)->toDateString()])->assertForbidden();
        $this->actingAs($collaborator)->patchJson(route('mytasks.complete', $task), ['completed' => true])->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('mytasks.subtasks.store', $task), ['title' => 'No'])->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$this->user()->id]])->assertForbidden();
        $this->actingAs($collaborator)->deleteJson(route('mytasks.destroy', $task))->assertForbidden();

        $this->assertSame(1, (int) $task->fresh()->job_status);
        $this->assertSame(2, (int) $task->fresh()->job_priority);
    }

    public function test_direct_task_role_keeps_stronger_permission_even_when_user_is_also_a_collaborator(): void
    {
        $owner = $this->user();
        $project = $this->project($owner);
        $task = $this->task($owner, $project);
        $task->collaborators()->attach($owner->id, ['status' => 'accepted']);

        $this->actingAs($owner)
            ->post(route('tasks.progress.store', $task), ['note' => 'Owner update'])
            ->assertRedirect();
    }

    public function test_assignee_creator_and_leader_each_keep_editor_permission(): void
    {
        foreach (['user_id', 'created_by', 'leader_user_id'] as $roleColumn) {
            $projectOwner = $this->user();
            $actor = $this->user();
            $project = $this->project($projectOwner, 'Direct role '.$roleColumn);
            $task = $this->task($projectOwner, $project, 'Direct role task '.$roleColumn);
            $task->update([$roleColumn => $actor->id]);
            $task->collaborators()->attach($actor->id, ['status' => 'accepted']);

            $this->actingAs($actor)
                ->postJson(route('mytasks.updatePriority', $task), ['job_priority' => 3])
                ->assertOk();
            $this->assertSame(3, (int) $task->fresh()->job_priority);
        }
    }

    public function test_collaborator_pivot_defaults_to_pending_instead_of_granting_silent_access(): void
    {
        $owner = $this->user();
        $candidate = $this->user();
        $project = $this->project($owner);
        $task = $this->task($owner, $project);

        DB::table('work_order_collaborators')->insert([
            'work_order_id' => $task->job_id,
            'user_id' => $candidate->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $task->job_id,
            'user_id' => $candidate->id,
            'status' => 'pending',
        ]);
        $this->actingAs($candidate)->get(route('mytasks.quickview.task', $task))->assertForbidden();
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'user', 'must_change_password' => false, 'is_active' => true]);
    }

    private function project(User $owner, string $name = 'Permission project'): WorkOrderList
    {
        return WorkOrderList::create(['user_id' => $owner->id, 'name' => $name, 'is_visible' => true, 'sort_order' => 1]);
    }

    private function task(User $owner, WorkOrderList $project, string $topic = 'Permission task'): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
