<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * กติกาเปลี่ยนตามที่เจ้าของระบบกำหนด: ผู้ร่วมงานที่ได้รับการตอบรับเข้าโปรเจกต์แล้ว (รวมถึง
 * ผู้ร่วมงานข้ามแผนก) เพิ่มงานหรืองานย่อยในโปรเจกต์นั้นได้ทันที ไม่ต้องรอเจ้าของโปรเจกต์อนุมัติ
 * อีกต่อไป — ดูเหตุผลเต็มที่ WorkOrderListPolicy::requestTask() และ
 * ProjectTaskRequestController::store()
 */
class ProjectTaskRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_collaborator_can_add_a_task_immediately_and_owner_is_notified(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();

        $response = $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Requested task'))
            ->assertCreated();

        $jobId = $response->json('job_id');
        $this->assertDatabaseHas('work_orders', [
            'job_id' => $jobId,
            'work_order_list_id' => $project->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'Requested task',
            'job_details' => null,
            'approval_status' => 'approved',
        ]);
        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $jobId,
            'user_id' => $collaborator->id,
            'status' => 'accepted',
            'decided_by' => $owner->id,
        ]);
        $this->assertDatabaseMissing('work_order_list_task_requests', ['job_topic' => 'Requested task']);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $owner->id,
            'type' => 'project_task_added',
            'category' => 'task',
            'work_order_id' => $jobId,
        ]);
    }

    public function test_pending_rejected_removed_and_unrelated_users_cannot_add_tasks(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            [$owner, $candidate, $project, $anchor] = $this->projectFixture();
            $anchor->collaborators()->attach($candidate->id, ['status' => $status]);
            $this->actingAs($candidate)->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload($status))->assertForbidden();
        }

        [$owner, $removed, $project, $anchor] = $this->projectFixture();
        $anchor->collaborators()->attach($removed->id, ['status' => 'accepted']);
        $anchor->collaborators()->detach($removed->id);
        $this->actingAs($removed)->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('removed'))->assertForbidden();

        $this->actingAs($this->user())->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('stranger'))->assertForbidden();
        $this->actingAs($owner)->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('owner'))->assertForbidden();
    }

    public function test_cross_department_collaborator_can_add_a_task_immediately_without_waiting_for_approval(): void
    {
        $departmentA = Department::create(['department_name' => 'A']);
        $departmentB = Department::create(['department_name' => 'B']);
        $owner = $this->user(['department_id' => $departmentA->id]);
        $collaborator = $this->user(['department_id' => $departmentB->id]);
        $project = $this->project($owner);
        $anchor = $this->task($owner, $project);
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $response = $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Cross department task'))
            ->assertCreated();

        $jobId = $response->json('job_id');
        $this->assertDatabaseHas('work_orders', [
            'job_id' => $jobId,
            'job_topic' => 'Cross department task',
            'user_id' => $owner->id,
            'approval_status' => 'approved',
        ]);
        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $jobId,
            'user_id' => $collaborator->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseMissing('system_notifications', ['type' => 'cross_department_pending']);
    }

    public function test_collaborator_can_add_a_subtask_and_the_parent_is_preserved(): void
    {
        [$owner, $collaborator, $project, $parent] = $this->collaborativeProject();
        $payload = array_replace($this->payload('Requested child'), [
            'request_type' => 'subtask',
            'parent_job_id' => $parent->job_id,
        ]);

        $response = $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('work_orders', [
            'job_id' => $response->json('job_id'),
            'work_order_list_id' => $project->id,
            'parent_job_id' => $parent->job_id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $response->json('job_id'),
            'user_id' => $collaborator->id,
            'status' => 'accepted',
        ]);
    }

    public function test_subtask_request_rejects_a_parent_from_another_project(): void
    {
        [, $collaborator, $project] = $this->collaborativeProject();
        $otherOwner = $this->user();
        $otherParent = $this->task($otherOwner, $this->project($otherOwner));

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), array_replace($this->payload('Wrong parent'), [
                'request_type' => 'subtask',
                'parent_job_id' => $otherParent->job_id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_job_id');

        $this->assertDatabaseMissing('work_orders', ['job_topic' => 'Wrong parent']);
    }

    public function test_subtask_request_rejects_a_closed_parent(): void
    {
        [$owner, $collaborator, $project, $parent] = $this->collaborativeProject();
        $payload = array_replace($this->payload('Closed parent child'), [
            'request_type' => 'subtask',
            'parent_job_id' => $parent->job_id,
        ]);
        $parent->update(['job_status' => 4]);

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_job_id');

        $parent->update(['job_status' => 2]);
        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $payload)
            ->assertCreated();
        $this->assertDatabaseHas('work_orders', ['job_topic' => 'Closed parent child', 'parent_job_id' => $parent->job_id]);
    }

    public function test_archiving_a_completed_project_is_not_blocked_by_task_history(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $anchor = $this->task($owner, $project);
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Added then closed'))
            ->assertCreated();
        WorkOrder::where('work_order_list_id', $project->id)->update(['job_status' => 4]);

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.archive', $project))
            ->assertOk();
        $this->assertNotNull($project->fresh()->archived_at);
    }

    public function test_duplicate_submissions_each_create_their_own_task(): void
    {
        [, $collaborator, $project] = $this->collaborativeProject();

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Duplicate request'))
            ->assertCreated();
        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Duplicate request'))
            ->assertCreated();

        $this->assertSame(2, WorkOrder::where('job_topic', 'Duplicate request')->count());
    }

    public function test_submit_rate_limit_is_per_user(): void
    {
        [$owner, $collaborator, $project, $anchor] = $this->collaborativeProject();
        $payload = $this->payload('Rate limited duplicate');

        foreach (range(1, WorkOrderListTaskRequest::SUBMIT_RATE_LIMIT_PER_MINUTE) as $attempt) {
            $this->actingAs($collaborator)
                ->postJson(route('mytasks.lists.task-requests.store', $project), $payload)
                ->assertCreated();
        }

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Rate limited request'))
            ->assertTooManyRequests()
            ->assertJsonPath('errors.task_request.0', 'ส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่');

        $otherCollaborator = $this->user();
        $anchor->collaborators()->attach($otherCollaborator->id, ['status' => 'accepted']);
        $this->actingAs($otherCollaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Separate user bucket'))
            ->assertCreated();
    }

    public function test_html_validation_preserves_input_and_reopens_the_correct_request_modal(): void
    {
        [, $collaborator, $project] = $this->collaborativeProject();
        $payload = $this->payload('Preserved request title');
        $payload['job_due_at'] = now()->subDay()->format('Y-m-d');

        $response = $this->actingAs($collaborator)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->post(route('mytasks.lists.task-requests.store', $project), $payload)
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHasErrors('job_due_at', null, 'projectTaskRequest')
            ->assertSessionHasInput('job_topic', 'Preserved request title')
            ->assertSessionHas('project_task_request_list_id', $project->id);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('"open_modal":true', false)
            ->assertSee('"job_topic":"Preserved request title"', false);
    }

    public function test_html_submission_redirects_with_success_feedback(): void
    {
        [, $collaborator, $project] = $this->collaborativeProject();

        $this->actingAs($collaborator)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->post(route('mytasks.lists.task-requests.store', $project), $this->payload('HTML added task'))
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHas('project_task_request_success', 'เพิ่มงานแล้ว');

        $this->assertDatabaseHas('work_orders', ['job_topic' => 'HTML added task']);
    }

    public function test_owner_is_notified_over_telegram_when_a_collaborator_adds_a_task(): void
    {
        Http::fake();
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.bot_username' => 'SmartGoalTestBot',
            'services.telegram.webhook_secret' => 'secret-token-for-tests',
            'services.telegram.max_attempts' => 3,
        ]);

        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $owner->forceFill([
            'telegram_chat_id' => 5001,
            'telegram_username' => 'owner5001',
            'telegram_linked_at' => now(),
            'telegram_notifications_enabled' => true,
        ])->save();

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Bot notified task'))
            ->assertCreated();

        $this->assertSame([$owner->id], TelegramMessage::query()->pluck('user_id')->all());
    }

    private function collaborativeProject(): array
    {
        [$owner, $collaborator, $project, $anchor] = $this->projectFixture();
        $department = Department::create(['department_name' => 'Request team']);
        $owner->update(['department_id' => $department->id]);
        $collaborator->update(['department_id' => $department->id]);
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        return [$owner, $collaborator, $project, $anchor];
    }

    private function projectFixture(): array
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $anchor = $this->task($owner, $project);

        return [$owner, $collaborator, $project, $anchor];
    }

    private function payload(string $topic): array
    {
        return [
            'request_type' => 'task',
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_start_at' => now()->addDay()->format('Y-m-d'),
            'job_due_at' => now()->addDays(3)->format('Y-m-d'),
        ];
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'user', 'must_change_password' => false, 'is_active' => true]);
    }

    private function project(User $owner): WorkOrderList
    {
        return WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Request project', 'is_visible' => true, 'sort_order' => 1]);
    }

    private function task(User $owner, WorkOrderList $project): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'Anchor task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
