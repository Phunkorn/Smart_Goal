<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TaskStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignee_submits_delegated_task_and_only_creator_can_approve(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $outsider = $this->user();
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

    public function test_admin_can_override_approved_active_statuses_but_never_assign_late_manually(): void
    {
        $admin = $this->user('admin');
        $creator = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $creator, 2);

        $response = $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 5])
            ->assertOk()
            ->assertJsonPath('job_status', 5)
            ->assertJsonPath('transitions.can_admin_override', true);
        $this->assertNotContains(1, $response->json('transitions.allowed_statuses'));
        $this->assertContains(2, $response->json('transitions.allowed_statuses'));
        $this->assertContains(5, $response->json('transitions.allowed_statuses'));
        $this->assertNotContains(6, $response->json('transitions.allowed_statuses'));
        $this->assertNotNull($task->fresh()->paused_at);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])
            ->assertOk()
            ->assertJsonPath('job_status', 3);
        $task->refresh();
        $this->assertNull($task->paused_at);
        $this->assertNull($task->submitted_for_review_by);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])
            ->assertOk()
            ->assertJsonPath('job_status', 4);
        $this->assertSame($admin->id, $task->fresh()->final_approved_by);
        $this->assertNotNull($task->fresh()->job_completed_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'admin_status_overridden', 'user_id' => $admin->id]);
        $this->assertDatabaseMissing('system_notifications', ['work_order_id' => $task->job_id]);

        $manualLate = $this->task($assignee, $creator, 2);
        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $manualLate), ['job_status' => 6])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_status');
        $this->assertSame(2, (int) $manualLate->fresh()->job_status);

        $pending = $this->task($assignee, $creator, 2, ['approval_status' => 'pending']);
        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $pending), ['job_status' => 2])
            ->assertForbidden();
    }

    public function test_admin_can_manage_an_accepted_collaborator_task_from_member_context(): void
    {
        $admin = $this->user('admin');
        $creator = $this->user();
        $assignee = $this->user();
        $member = $this->user();
        $task = $this->task($assignee, $creator, 2);
        $task->collaborators()->attach($member->id, [
            'status' => 'accepted',
            'added_by' => $creator->id,
            'responded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 5])
            ->assertOk()
            ->assertJsonPath('job_status', 5);

        $this->assertSame(5, (int) $task->fresh()->job_status);
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

    /**
     * Regression: หัวหน้า/ผู้มอบหมายเคยถูก WorkOrderPolicy::submitForReview() กันออกด้วย
     * isAssignmentApprover() ผลคือถ้าผู้รับผิดชอบไม่กดส่งตรวจเอง งานจะตันสนิท —
     * ที่สถานะล่าช้าหนักที่สุดเพราะถอยกลับไปกำลังทำหรือพักงานก็ไม่ได้ ผู้มอบหมายจึงเหลือ
     * สถานะเดียวคือสถานะเดิม และการ์ดบนบอร์ดลากไม่ได้เลย
     */
    public function test_assigner_can_pull_a_stuck_task_into_review_and_close_it(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $creator, 6, ['job_due_at' => now()->subDays(2), 'late_at' => now()]);

        $capabilities = app(TaskStatusTransitionService::class)->capabilities($task, $creator);
        $this->assertContains(3, $capabilities['allowed_statuses'], 'ผู้มอบหมายต้องดึงงานล่าช้าเข้าขั้นตรวจได้');

        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])->assertOk();
        $this->assertSame(3, (int) $task->fresh()->job_status);

        // ขั้นตรวจสอบยังอยู่ครบ ปิดงานยังต้องเดินผ่านสถานะ 3 เหมือนเดิม
        $this->actingAs($creator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertOk();
        $this->assertSame(4, (int) $task->fresh()->job_status);
        $this->assertSame($creator->id, $task->fresh()->final_approved_by);
    }

    public function test_no_active_status_leaves_any_task_participant_without_a_move(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $collaborator = $this->user();
        $service = app(TaskStatusTransitionService::class);

        foreach ([2, 3, 5, 6] as $status) {
            $task = $this->task($assignee, $creator, $status);
            $task->collaborators()->attach($collaborator->id, ['status' => 'accepted']);
            $task->load('collaborators');

            foreach (['creator' => $creator, 'assignee' => $assignee, 'collaborator' => $collaborator] as $label => $actor) {
                $allowed = $service->capabilities($task, $actor)['allowed_statuses'];
                // สถานะ 3 เป็นข้อยกเว้นที่ตั้งใจ: คนทำงานต้องรอผลตรวจจากผู้มอบหมาย
                if ($status === 3 && $label !== 'creator') {
                    $this->assertSame([3], $allowed, $label.' ที่สถานะ 3 ต้องรอผลตรวจเท่านั้น');

                    continue;
                }

                $this->assertNotSame([$status], $allowed, $label.' ตันที่สถานะ '.$status);
            }
        }
    }

    public function test_paused_work_goes_straight_to_review_without_bouncing_through_doing(): void
    {
        $creator = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $creator, 5, ['paused_at' => now()->subDay()]);

        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])->assertOk();

        $task->refresh();
        $this->assertSame(3, (int) $task->job_status);
        $this->assertNull($task->paused_at, 'ส่งตรวจแล้วต้องไม่ค้างสถานะพักงานไว้');
        $this->assertSame($assignee->id, $task->submitted_for_review_by);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $creator->id, 'type' => 'submitted_for_review']);
    }

    /**
     * งานที่ผู้ใช้สร้างเองและรับผิดชอบเองไม่มีขั้นตรวจสอบ UI จึงต้องไม่เสนอสถานะ 3
     * ให้ลากแล้วเด้ง error — allowed_statuses กับสิ่งที่ server ยอมรับต้องตรงกันเสมอ
     */
    public function test_self_owned_task_never_advertises_a_review_step_it_would_reject(): void
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner, 2);

        $allowed = app(TaskStatusTransitionService::class)->capabilities($task, $owner)['allowed_statuses'];
        $this->assertNotContains(3, $allowed);
        $this->assertContains(4, $allowed);

        $this->actingAs($owner)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('job_status');
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
