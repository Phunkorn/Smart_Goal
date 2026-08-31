<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTaskRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_collaborator_can_submit_without_creating_a_work_order_and_owner_is_notified(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Requested task'))
            ->assertCreated();

        $this->assertDatabaseHas('work_order_list_task_requests', [
            'work_order_list_id' => $project->id,
            'requester_id' => $collaborator->id,
            'status' => 'pending',
            'job_topic' => 'Requested task',
            'job_details' => null,
            'work_order_id' => null,
        ]);
        $this->assertDatabaseMissing('work_orders', ['job_topic' => 'Requested task']);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $owner->id,
            'type' => 'project_task_request_submitted',
            'category' => 'task',
        ]);

        $notification = SystemNotification::where('user_id', $owner->id)
            ->where('type', 'project_task_request_submitted')
            ->firstOrFail();
        $this->actingAs($owner)->get(route('notifications.open', $notification))->assertRedirect(route('mytasks.index', [
            'view' => 'board',
            'task_request' => $notification->data['task_request_id'],
        ]));
    }

    public function test_pending_rejected_removed_and_unrelated_users_cannot_submit_requests(): void
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

    public function test_only_project_owner_can_approve_and_approval_creates_exactly_one_task(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $taskRequest = $this->request($collaborator, $project, 'Approved request');

        $this->actingAs($this->user())
            ->patchJson(route('mytasks.task-requests.approve', $taskRequest))
            ->assertForbidden();

        $response = $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.approve', $taskRequest))
            ->assertOk();

        $jobId = $response->json('job_id');
        $this->assertDatabaseHas('work_orders', [
            'job_id' => $jobId,
            'work_order_list_id' => $project->id,
            'user_id' => $collaborator->id,
            'created_by' => $owner->id,
            'job_topic' => 'Approved request',
            'job_details' => null,
        ]);
        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $jobId,
            'user_id' => $collaborator->id,
            'status' => 'accepted',
            'decided_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('work_order_list_task_requests', [
            'id' => $taskRequest->id,
            'status' => 'approved',
            'work_order_id' => $jobId,
        ]);

        $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.approve', $taskRequest))
            ->assertStatus(409);
        $this->assertSame(1, WorkOrder::where('job_topic', 'Approved request')->count());
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $collaborator->id,
            'type' => 'project_task_request_approved',
            'work_order_id' => $jobId,
        ]);
    }

    public function test_legacy_request_details_are_preserved_when_owner_approves(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $taskRequest = $this->request($collaborator, $project, 'Legacy detailed request', 'Legacy request details');

        $response = $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.approve', $taskRequest))
            ->assertOk();

        $this->assertDatabaseHas('work_orders', [
            'job_id' => $response->json('job_id'),
            'job_topic' => 'Legacy detailed request',
            'job_details' => 'Legacy request details',
        ]);
    }

    public function test_rejection_creates_no_task_notifies_requester_and_allows_a_new_request(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $taskRequest = $this->request($collaborator, $project, 'Rejected request');

        $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.reject', $taskRequest), ['decision_reason' => 'Not now'])
            ->assertOk();

        $this->assertDatabaseHas('work_order_list_task_requests', [
            'id' => $taskRequest->id,
            'status' => 'rejected',
            'decision_reason' => 'Not now',
            'work_order_id' => null,
        ]);
        $this->assertDatabaseMissing('work_orders', ['job_topic' => 'Rejected request']);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $collaborator->id,
            'type' => 'project_task_request_rejected',
        ]);
        $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.reject', $taskRequest), ['decision_reason' => 'Again'])
            ->assertStatus(409);
        $this->assertSame(1, SystemNotification::where('user_id', $collaborator->id)
            ->where('type', 'project_task_request_rejected')->count());

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Rejected request'))
            ->assertCreated();
    }

    public function test_duplicate_pending_request_is_rejected_but_different_request_is_allowed(): void
    {
        [, $collaborator, $project] = $this->collaborativeProject();
        $this->request($collaborator, $project, 'Duplicate request');

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Duplicate request'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_topic');
        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Different request'))
            ->assertCreated();
    }

    public function test_pending_request_cap_blocks_different_titles_without_creating_notifications(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();

        foreach (range(1, WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT) as $index) {
            $this->request($collaborator, $project, 'Pending '.$index);
        }

        $before = $project->taskRequests()->where('status', 'pending')->get()->toArray();

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Different title over cap'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_topic');
        $this->actingAs($collaborator)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->post(route('mytasks.lists.task-requests.store', $project), $this->payload('HTML title over cap'))
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHasErrors('job_topic', null, 'projectTaskRequest')
            ->assertSessionHasInput('job_topic', 'HTML title over cap')
            ->assertSessionHas('project_task_request_list_id', $project->id);

        $this->assertSame($before, $project->taskRequests()->where('status', 'pending')->get()->toArray());
        $this->assertDatabaseMissing('work_order_list_task_requests', ['job_topic' => 'Different title over cap']);
        $this->assertDatabaseMissing('work_order_list_task_requests', ['job_topic' => 'HTML title over cap']);
        $this->assertSame(0, SystemNotification::where('user_id', $owner->id)
            ->where('type', 'project_task_request_submitted')->count());
    }

    public function test_approved_and_rejected_requests_do_not_count_toward_pending_cap(): void
    {
        [, $collaborator, $project] = $this->collaborativeProject();

        foreach (range(1, WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT - 1) as $index) {
            $this->request($collaborator, $project, 'Still pending '.$index);
        }
        $this->request($collaborator, $project, 'Already approved')->update(['status' => 'approved']);
        $this->request($collaborator, $project, 'Already rejected')->update(['status' => 'rejected']);

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Last available pending slot'))
            ->assertCreated();

        $this->assertSame(
            WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT,
            $project->taskRequests()->where('requester_id', $collaborator->id)->where('status', 'pending')->count()
        );
    }

    public function test_back_to_back_submissions_at_the_boundary_cannot_bypass_pending_cap(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();

        foreach (range(1, WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT - 1) as $index) {
            $this->request($collaborator, $project, 'Boundary seed '.$index);
        }

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Boundary winner'))
            ->assertCreated();
        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Boundary loser'))
            ->assertUnprocessable();

        $this->assertSame(
            WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT,
            $project->taskRequests()->where('requester_id', $collaborator->id)->where('status', 'pending')->count()
        );
        $this->assertDatabaseMissing('work_order_list_task_requests', ['job_topic' => 'Boundary loser']);
        $this->assertSame(1, SystemNotification::where('user_id', $owner->id)
            ->where('type', 'project_task_request_submitted')->count());
    }

    public function test_pending_cap_is_project_and_requester_scoped(): void
    {
        [$owner, $collaborator, $firstProject] = $this->collaborativeProject();
        $secondProject = $this->project($owner);
        $secondAnchor = $this->task($owner, $secondProject);
        $secondAnchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);
        $otherCollaborator = $this->user();
        $firstProject->workOrders()->firstOrFail()->collaborators()->attach($otherCollaborator->id, ['status' => 'accepted']);

        foreach (range(1, WorkOrderListTaskRequest::MAX_PENDING_PER_REQUESTER_PROJECT) as $index) {
            $this->request($collaborator, $firstProject, 'Scoped seed '.$index);
        }

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $secondProject), $this->payload('Other project'))
            ->assertCreated();
        $this->actingAs($otherCollaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $firstProject), $this->payload('Other requester'))
            ->assertCreated();
    }

    public function test_submit_rate_limit_is_per_user_and_does_not_block_approval_route(): void
    {
        [$owner, $collaborator, $project, $anchor] = $this->collaborativeProject();
        $payload = $this->payload('Rate limited duplicate');

        foreach (range(1, WorkOrderListTaskRequest::SUBMIT_RATE_LIMIT_PER_MINUTE) as $attempt) {
            $response = $this->actingAs($collaborator)
                ->postJson(route('mytasks.lists.task-requests.store', $project), $payload);
            $attempt === 1 ? $response->assertCreated() : $response->assertUnprocessable();
        }

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Rate limited request'))
            ->assertTooManyRequests()
            ->assertJsonPath('errors.task_request.0', 'ส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่');
        $this->actingAs($collaborator)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->post(route('mytasks.lists.task-requests.store', $project), $this->payload('Rate limited HTML request'))
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHasErrors('task_request', null, 'projectTaskRequest')
            ->assertSessionHasInput('job_topic', 'Rate limited HTML request')
            ->assertSessionHas('project_task_request_list_id', $project->id);
        $this->assertSame(1, SystemNotification::where('user_id', $owner->id)
            ->where('type', 'project_task_request_submitted')->count());

        $otherCollaborator = $this->user();
        $anchor->collaborators()->attach($otherCollaborator->id, ['status' => 'accepted']);
        $this->actingAs($otherCollaborator)
            ->postJson(route('mytasks.lists.task-requests.store', $project), $this->payload('Separate user bucket'))
            ->assertCreated();
        $this->assertSame(2, SystemNotification::where('user_id', $owner->id)
            ->where('type', 'project_task_request_submitted')->count());

        $pending = $project->taskRequests()->where('requester_id', $collaborator->id)->firstOrFail();
        $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.approve', $pending))
            ->assertOk();
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

    public function test_stale_html_approve_and_reject_redirect_with_feedback_instead_of_raw_error_pages(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $approved = $this->request($collaborator, $project, 'Stale approval');
        $rejected = $this->request($collaborator, $project, 'Stale rejection');

        $this->actingAs($owner)->patchJson(route('mytasks.task-requests.approve', $approved))->assertOk();
        $this->actingAs($owner)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->patch(route('mytasks.task-requests.approve', $approved))
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHas('project_task_request_error', 'คำขอนี้ถูกพิจารณาโดยผู้ใช้อื่นแล้ว');

        $this->actingAs($owner)->patchJson(route('mytasks.task-requests.reject', $rejected))->assertOk();
        $this->actingAs($owner)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->patch(route('mytasks.task-requests.reject', $rejected))
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHas('project_task_request_error', 'คำขอนี้ถูกพิจารณาโดยผู้ใช้อื่นแล้ว');
    }

    public function test_reject_validation_redirects_with_sweetalert_feedback_context_and_preserves_reason(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $taskRequest = $this->request($collaborator, $project, 'Reject validation');
        $reason = str_repeat('x', 1001);

        $this->actingAs($owner)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->patch(route('mytasks.task-requests.reject', $taskRequest), ['decision_reason' => $reason])
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHasErrors('decision_reason', null, 'projectTaskRequestDecision')
            ->assertSessionHasInput('decision_reason', $reason)
            ->assertSessionHas('project_task_request_decision_id', $taskRequest->id);

        $this->assertSame('pending', $taskRequest->fresh()->status);
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $collaborator->id,
            'type' => 'project_task_request_rejected',
        ]);
    }

    public function test_owner_cannot_approve_after_requester_loses_collaborator_access(): void
    {
        [$owner, $collaborator, $project, $anchor] = $this->collaborativeProject();
        $taskRequest = $this->request($collaborator, $project, 'Removed requester');
        $anchor->collaborators()->detach($collaborator->id);

        $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.approve', $taskRequest))
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->from(route('mytasks.index', ['view' => 'board']))
            ->patch(route('mytasks.task-requests.approve', $taskRequest))
            ->assertRedirect(route('mytasks.index', ['view' => 'board']))
            ->assertSessionHas('project_task_request_error', 'ผู้ขอไม่ได้เป็นผู้ร่วมงานที่ได้รับการยอมรับในโปรเจกต์นี้แล้ว');
        $this->assertDatabaseMissing('work_orders', ['job_topic' => 'Removed requester']);
        $this->assertSame('pending', $taskRequest->fresh()->status);
    }

    public function test_cross_department_approval_still_uses_existing_admin_approval_flow(): void
    {
        $departmentA = Department::create(['department_name' => 'A']);
        $departmentB = Department::create(['department_name' => 'B']);
        $owner = $this->user(['department_id' => $departmentA->id]);
        $collaborator = $this->user(['department_id' => $departmentB->id]);
        $project = $this->project($owner);
        $anchor = $this->task($owner, $project);
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);
        $taskRequest = $this->request($collaborator, $project, 'Cross department');
        $admin = $this->user(['role' => 'admin']);

        $response = $this->actingAs($owner)
            ->patchJson(route('mytasks.task-requests.approve', $taskRequest))
            ->assertOk();

        $this->assertDatabaseHas('work_orders', [
            'job_id' => $response->json('job_id'),
            'approval_status' => 'pending',
            'approved_by' => null,
        ]);
        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $response->json('job_id'),
            'user_id' => $collaborator->id,
            'status' => 'pending',
            'decided_by' => null,
        ]);

        $job = WorkOrder::findOrFail($response->json('job_id'));
        $this->actingAs($collaborator)->get(route('tasks.show', $job))->assertForbidden();
        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $job->job_id,
            'user_id' => $collaborator->id,
            'status' => 'accepted',
            'decided_by' => $admin->id,
        ]);
        $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $job))->assertOk();
        $this->assertSame(1, SystemNotification::where('user_id', $collaborator->id)
            ->where('work_order_id', $job->job_id)
            ->where('type', 'task_assigned')
            ->count());

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertConflict();
        $this->assertSame(1, SystemNotification::where('user_id', $collaborator->id)
            ->where('work_order_id', $job->job_id)
            ->where('type', 'task_assigned')
            ->count());
    }

    public function test_page_renders_request_action_for_collaborator_and_pending_queue_for_owner(): void
    {
        [$owner, $collaborator, $project] = $this->collaborativeProject();
        $this->request($collaborator, $project, 'Pending UI request');

        $this->actingAs($collaborator)->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->assertSee('ขอเพิ่มงาน')
            ->assertSee('data-task-modal hidden', false)
            ->assertSee('data-project-task-request-modal hidden', false)
            ->assertDontSee('name="request_details"', false)
            ->assertDontSee('รายละเอียดโดยย่อ');
        $this->actingAs($owner)->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()->assertSee('Pending UI request')->assertSee('อนุมัติ');
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

    private function request(User $requester, WorkOrderList $project, string $topic, ?string $details = null): WorkOrderListTaskRequest
    {
        $payload = $this->payload($topic);

        return WorkOrderListTaskRequest::create([
            'work_order_list_id' => $project->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
            'job_topic' => $payload['job_topic'],
            'job_details' => $details,
            'job_priority' => $payload['job_priority'],
            'job_start_at' => $payload['job_start_at'],
            'job_due_at' => $payload['job_due_at'],
        ]);
    }

    private function payload(string $topic): array
    {
        return [
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
            'job_status' => 1,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
