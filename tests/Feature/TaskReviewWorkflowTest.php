<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignee_submits_delegated_task_and_only_creator_can_approve(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $outsider = $this->user('admin');
        $task = $this->task($assignee, $creator, 2);

        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('job_status');
        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])->assertOk();

        $task->refresh();
        $this->assertSame(3, (int) $task->job_status);
        $this->assertSame($assignee->id, $task->submitted_for_review_by);
        $this->assertNotNull($task->submitted_for_review_at);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $creator->id, 'type' => 'submitted_for_review']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'submitted_for_review', 'user_id' => $assignee->id]);

        $this->actingAs($outsider)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertForbidden();
        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertForbidden();
        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertOk();

        $task->refresh();
        $this->assertSame(4, (int) $task->job_status);
        $this->assertSame($creator->id, $task->final_approved_by);
        $this->assertNotNull($task->final_approved_at);
        $this->assertNotNull($task->job_completed_at);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $assignee->id, 'type' => 'review_approved']);
    }

    public function test_creator_returns_review_with_required_reason(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $creator, 3, ['submitted_for_review_by' => $assignee->id, 'submitted_for_review_at' => now()]);

        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2, 'reason' => 'mine'])
            ->assertForbidden();
        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2, 'reason' => 'แก้ไขรายงานหน้า 3'])->assertOk();

        $task->refresh();
        $this->assertSame(2, (int) $task->job_status);
        $this->assertNull($task->submitted_for_review_by);
        $this->assertSame('แก้ไขรายงานหน้า 3', $task->review_return_reason);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $assignee->id, 'type' => 'review_returned']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'review_returned', 'user_id' => $creator->id]);
    }

    public function test_completed_task_is_locked_but_comments_remain_available_and_admin_can_explicitly_reopen(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $admin = $this->user('admin');
        $task = $this->task($assignee, $creator, 4, ['job_completed_at' => now(), 'final_approved_by' => $creator->id, 'final_approved_at' => now()]);

        $this->actingAs($assignee)->patchJson(route('tasks.details.update', $task), ['job_topic' => 'Changed'])->assertForbidden();
        $this->actingAs($admin)->patchJson(route('tasks.details.update', $task), ['job_topic' => 'Changed'])->assertForbidden();
        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $task), ['message' => 'follow up'])->assertCreated();
        $this->actingAs($admin)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2])->assertUnprocessable();
        $this->actingAs($admin)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2, 'action' => 'reopen'])->assertOk();

        $task->refresh();
        $this->assertSame(2, (int) $task->job_status);
        $this->assertNull($task->job_completed_at);
        $this->assertNull($task->final_approved_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task_reopened', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $assignee->id, 'type' => 'task_reopened']);
    }

    public function test_self_created_task_can_close_without_review_and_legacy_complete_endpoint_cannot_bypass_delegated_review(): void
    {
        $owner = $this->user();
        $selfTask = $this->task($owner, $owner, 2);
        $this->actingAs($owner)->patchJson(route('mytasks.complete', $selfTask), ['completed' => true])->assertOk();
        $this->assertSame(4, (int) $selfTask->fresh()->job_status);

        $creator = $this->user();
        $assignee = $this->user();
        $delegated = $this->task($assignee, $creator, 2);
        $this->actingAs($assignee)->patchJson(route('mytasks.complete', $delegated), ['completed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('job_status');
    }

    public function test_late_delegated_task_submits_to_review_and_stays_in_review(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $creator, 6, ['job_due_at' => now()->subDay(), 'late_at' => now()]);

        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertUnprocessable();
        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])->assertOk();
        $this->actingAs($assignee)->get(route('mytasks.index'))->assertOk();
        $this->assertSame(3, (int) $task->fresh()->job_status);
    }

    public function test_approval_notifications_are_deduplicated(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $collaborator = $this->user();
        $task = $this->task($assignee, $creator, 3, ['submitted_for_review_by' => $assignee->id, 'submitted_for_review_at' => now()]);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $creator->id]);
        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertOk();

        $this->assertSame(1, SystemNotification::where('type', 'review_approved')->where('user_id', $assignee->id)->count());
        $this->assertSame(1, SystemNotification::where('type', 'review_approved')->where('user_id', $collaborator->id)->count());
        $this->assertSame(0, SystemNotification::where('type', 'review_approved')->where('user_id', $creator->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'review_approved')->count());
    }

    public function test_workspace_exposes_review_capabilities_and_collapsed_completed_group(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $reviewTask = $this->task($assignee, $creator, 3, [
            'submitted_for_review_by' => $assignee->id,
            'submitted_for_review_at' => now(),
        ]);
        $completedTask = $this->task($creator, $creator, 4, [
            'job_topic' => 'Archived completed task',
            'job_completed_at' => now(),
            'final_approved_by' => $creator->id,
            'final_approved_at' => now(),
        ]);

        $response = $this->actingAs($creator)->get(route('mytasks.index'));

        $response->assertOk()
            ->assertSee('data-review-approve', false)
            ->assertSee('data-review-return', false)
            ->assertSee('"can_review":true', false)
            ->assertSee('board-completed-group', false)
            ->assertSee('Archived completed task')
            ->assertSee('data-task-id="'.$completedTask->job_id.'"', false);

    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false, 'is_active' => true]);
    }

    private function task(User $assignee, User $creator, int $status, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id, 'created_by' => $creator->id, 'leader_user_id' => $creator->id,
            'job_topic' => 'Review workflow task', 'job_priority' => 2, 'job_status' => $status,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(), 'job_due_at' => now()->addDay(),
        ], $extra));
    }
}
