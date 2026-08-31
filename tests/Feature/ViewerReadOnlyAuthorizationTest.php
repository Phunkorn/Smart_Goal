<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewerReadOnlyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_view_company_board_and_allowed_task_details(): void
    {
        $viewer = $this->user('viewer');
        $task = $this->task($viewer, $viewer, 2);

        $this->actingAs($viewer)->get(route('board.index'))->assertOk();
        $this->actingAs($viewer)->get(route('tasks.show', $task))->assertRedirect(route('board.index'));
    }

    public function test_user_who_created_tasks_before_role_change_becomes_strictly_read_only(): void
    {
        $user = $this->user('user');
        $assignee = $this->user();
        $workingTask = $this->task($user, $user, 2);
        $reviewTask = $this->task($assignee, $user, 3, [
            'submitted_for_review_by' => $assignee->id,
            'submitted_for_review_at' => now(),
        ]);

        $this->assertSame($user->id, $workingTask->created_by);
        $this->assertSame('user', $user->role);

        $user->update(['role' => 'viewer']);
        $viewer = $user->fresh();

        $this->assertSame('viewer', $viewer->role);
        $this->actingAs($viewer)->get(route('tasks.show', $workingTask))->assertRedirect(route('board.index'));
        $this->actingAs($viewer)->patchJson(route('tasks.details.update', $workingTask), [
            'job_topic' => 'Forbidden legacy edit',
        ])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $workingTask), ['job_status' => 5])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $reviewTask), ['job_status' => 4])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $reviewTask), [
            'job_status' => 2,
            'reason' => 'Forbidden legacy return',
        ])->assertForbidden();

        $this->assertSame('Viewer permission task', $workingTask->fresh()->job_topic);
        $this->assertSame(2, (int) $workingTask->fresh()->job_status);
        $this->assertSame(3, (int) $reviewTask->fresh()->job_status);
    }

    public function test_legacy_creator_viewer_cannot_edit_task_fields_or_work_properties(): void
    {
        $viewer = $this->legacyCreatorViewer();
        $task = $this->task($viewer, $viewer, 2);

        $this->actingAs($viewer)->patchJson(route('tasks.details.update', $task), [
            'job_topic' => 'Changed',
            'job_details' => 'Changed',
        ])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.schedule.update', $task), [
            'job_start_at' => now()->toDateString(),
            'job_due_at' => now()->addDays(2)->toDateString(),
        ])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('mytasks.updatePriority', $task), ['job_priority' => 3])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => now()->addDays(3)->toDateString()])->assertForbidden();

        $this->assertSame('Viewer permission task', $task->fresh()->job_topic);
        $this->assertSame(2, (int) $task->fresh()->job_priority);
    }

    public function test_viewer_cannot_use_any_review_or_status_transition_path(): void
    {
        $viewer = $this->legacyCreatorViewer();
        $assignee = $this->user();
        $creator = $this->user();

        $working = $this->task($assignee, $viewer, 2);
        $assignedToViewer = $this->task($viewer, $creator, 2);
        $review = $this->task($assignee, $viewer, 3, [
            'submitted_for_review_by' => $assignee->id,
            'submitted_for_review_at' => now(),
        ]);
        $completed = $this->task($assignee, $viewer, 4, [
            'job_completed_at' => now(),
            'final_approved_at' => now(),
        ]);

        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $working), ['job_status' => 3])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $assignedToViewer), ['job_status' => 3])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('mytasks.updateStatus', $assignedToViewer), ['job_status' => 5])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $review), ['job_status' => 4])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $review), ['job_status' => 2, 'reason' => 'Return'])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('tasks.updateStatus', $completed), ['job_status' => 2, 'action' => 'reopen'])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('mytasks.complete', $working), ['completed' => true])->assertForbidden();

        $this->assertSame(2, (int) $working->fresh()->job_status);
        $this->assertSame(2, (int) $assignedToViewer->fresh()->job_status);
        $this->assertSame(3, (int) $review->fresh()->job_status);
        $this->assertSame(4, (int) $completed->fresh()->job_status);
    }

    public function test_viewer_cannot_delete_manage_collaborators_or_respond_to_invitation(): void
    {
        $viewer = $this->legacyCreatorViewer();
        $assignee = $this->user();
        $candidate = $this->user();
        $task = $this->task($assignee, $viewer, 2);

        $this->actingAs($viewer)->deleteJson(route('mytasks.destroy', $task))->assertForbidden();
        $this->actingAs($viewer)->deleteJson(route('admin.tasks.destroy', $task))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('tasks.collaborators.store', $task), [
            'collaborators' => [$candidate->id],
        ])->assertForbidden();

        $task->collaborators()->attach($viewer->id, ['status' => 'pending']);
        $this->actingAs($viewer)->patchJson(route('tasks.invitation.respond', $task), ['status' => 'accepted'])->assertForbidden();
        $this->assertSame('pending', $task->collaborators()->where('users.id', $viewer->id)->first()->pivot->status);
    }

    public function test_viewer_cannot_mutate_legacy_project_or_comment_on_task(): void
    {
        $viewer = $this->legacyCreatorViewer();
        $task = $this->task($viewer, $viewer, 2);
        $list = WorkOrderList::create([
            'user_id' => $viewer->id,
            'name' => 'Legacy viewer project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($viewer)->patchJson(route('mytasks.lists.toggle', $list), ['is_visible' => false])->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('mytasks.lists.update', $list), ['name' => 'Changed'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('tasks.comments.store', $task), ['message' => 'Mutation'])->assertForbidden();

        $this->assertTrue((bool) $list->fresh()->is_visible);
        $this->assertSame('Legacy viewer project', $list->fresh()->name);
    }

    public function test_user_review_and_admin_reopen_behavior_remains_available(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $admin = $this->user('admin');
        $task = $this->task($assignee, $creator, 2);

        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])->assertOk();
        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertOk();
        $this->actingAs($admin)->patchJson(route('tasks.updateStatus', $task), [
            'job_status' => 2,
            'action' => 'reopen',
        ])->assertOk();

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    private function legacyCreatorViewer(): User
    {
        $user = $this->user();
        $user->update(['role' => 'viewer']);

        return $user->fresh();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function task(User $assignee, User $creator, int $status, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'job_topic' => 'Viewer permission task',
            'job_priority' => 2,
            'job_status' => $status,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ], $extra));
    }
}
