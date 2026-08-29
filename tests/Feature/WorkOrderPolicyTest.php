<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ครอบคลุมพฤติกรรมของ WorkOrderPolicy / WorkOrderListPolicy ที่รวบรวมมาจาก
 * abort_unless()/abort_if() เดิมใน TaskController และ MyTaskController
 * (หัวข้อที่ 1 ใน next_steps_scope.md) ยืนยันว่าใครเข้าได้/เข้าไม่ได้เหมือนเดิม
 * ทุกกรณีสำหรับทุก role (admin / user / viewer)
 */
class WorkOrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    // ---------- viewAny (board index) ----------

    public function test_only_admin_and_viewer_can_view_board_index(): void
    {
        $this->actingAs($this->user('admin'))->get(route('board.index'))->assertOk();
        $this->actingAs($this->user('viewer'))->get(route('board.index'))->assertOk();
        $this->actingAs($this->user('user'))->get(route('board.index'))->assertForbidden();
    }

    // ---------- create ----------

    public function test_viewer_cannot_create_task_from_any_entry_point(): void
    {
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)
            ->post(route('tasks.store'), $this->creationPayload())
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson(route('mytasks.store'), ['job_topic' => 'quick task'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson(route('mytasks.create'), $this->myTaskCreationPayload())
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson(route('mytasks.lists.store'), ['name' => 'my list'])
            ->assertForbidden();
    }

    public function test_admin_and_user_can_create_task(): void
    {
        $admin = $this->user('admin');
        $employee = $this->user('user');

        $this->actingAs($admin)
            ->post(route('tasks.store'), $this->creationPayload())
            ->assertRedirect();

        $this->actingAs($employee)
            ->postJson(route('mytasks.create'), $this->myTaskCreationPayload())
            ->assertCreated();
    }

    // ---------- manageTeam ----------

    public function test_only_creator_leader_or_admin_can_manage_collaborators(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $collaboratorCandidate = $this->user();
        $task = $this->taskFor($owner);

        $this->actingAs($stranger)
            ->post(route('tasks.collaborators.store', $task), [
                'collaborators' => [$collaboratorCandidate->id],
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('tasks.collaborators.store', $task), [
                'collaborators' => [$collaboratorCandidate->id],
            ])
            ->assertRedirect();
    }

    // ---------- delete (admin-only board deletion) vs deleteOwn ----------

    public function test_board_destroy_is_admin_only(): void
    {
        $owner = $this->user();
        $task = $this->taskFor($owner);

        $this->actingAs($owner)
            ->delete(route('admin.tasks.destroy', $task))
            ->assertForbidden();

        $this->actingAs($this->user('admin'))
            ->delete(route('admin.tasks.destroy', $task))
            ->assertRedirect();
    }

    public function test_my_tasks_destroy_allows_owner_creator_or_leader_but_not_unrelated_user(): void
    {
        $owner = $this->user();
        $unrelated = $this->user();
        $task = $this->taskFor($owner);

        $this->actingAs($unrelated)
            ->delete(route('mytasks.destroy', $task))
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete(route('mytasks.destroy', $task))
            ->assertOk();
    }

    // ---------- approve (admin-only creation approval) ----------

    public function test_only_admin_can_approve_task(): void
    {
        $owner = $this->user();
        $task = $this->taskFor($owner);

        $this->actingAs($owner)
            ->patch(route('admin.tasks.approval', $task), ['approval_status' => 'approved'])
            ->assertForbidden();

        $this->actingAs($this->user('admin'))
            ->patch(route('admin.tasks.approval', $task), ['approval_status' => 'approved'])
            ->assertRedirect();
    }

    // ---------- WorkOrderList: toggle has no admin bypass (preserves original quirk) ----------

    public function test_list_toggle_only_allows_the_exact_owner_not_even_admin(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $list = $this->listFor($owner);

        $this->actingAs($admin)
            ->patchJson(route('mytasks.lists.toggle', $list), ['is_visible' => false])
            ->assertForbidden();

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.toggle', $list), ['is_visible' => false])
            ->assertOk();
    }

    // ---------- WorkOrderList: manage (update/destroy) allows admin or owner ----------

    public function test_list_manage_allows_admin_or_owner_but_not_stranger(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $admin = $this->user('admin');
        $list = $this->listFor($owner);

        $this->actingAs($stranger)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'renamed'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'renamed by admin'])
            ->assertOk();

        $this->actingAs($owner)
            ->patchJson(route('mytasks.lists.update', $list), ['name' => 'renamed by owner'])
            ->assertOk();
    }

    // ---------- update (work-on-job access) shared by both controllers ----------

    public function test_accepted_collaborator_can_update_progress_but_pending_collaborator_cannot(): void
    {
        $owner = $this->user();
        $accepted = $this->user();
        $pending = $this->user();
        $task = $this->taskFor($owner);

        $task->collaborators()->attach($accepted->id, ['status' => 'accepted']);
        $task->collaborators()->attach($pending->id, ['status' => 'pending']);

        $this->actingAs($accepted)
            ->post(route('tasks.progress.store', $task), ['note' => 'อัปเดตความคืบหน้า'])
            ->assertRedirect();

        $this->actingAs($pending)
            ->post(route('tasks.progress.store', $task), ['note' => 'อัปเดตความคืบหน้า'])
            ->assertForbidden();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function taskFor(User $owner): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'Policy test task',
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }

    private function listFor(User $owner): WorkOrderList
    {
        return WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Policy test list',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
    }

    private function creationPayload(): array
    {
        return [
            'job_topic' => 'New authorized task',
            'job_start_at' => now()->toDateTimeString(),
            'job_due_at' => now()->addDay()->toDateTimeString(),
        ];
    }

    private function myTaskCreationPayload(): array
    {
        return [
            'job_topic' => 'New my-task',
            'job_start_at' => now()->toDateTimeString(),
            'job_due_at' => now()->addDay()->toDateTimeString(),
        ];
    }

    public function test_cross_department_invitation_requires_admin_decision_and_can_only_be_decided_once(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $invitee = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create(['role' => 'admin']);
        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'Invitation replay guard',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        $job->collaborators()->attach($invitee->id, ['added_by' => $owner->id, 'status' => 'pending']);

        $this->actingAs($invitee)
            ->patch(route('tasks.invitation.respond', $job->job_id), ['status' => 'rejected'])
            ->assertForbidden();
        $this->assertSame('pending', $job->fresh()->collaborators()->first()->pivot->status);

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.collaborators.approval', [$job->job_id, $invitee->id]), ['status' => 'rejected'])
            ->assertOk();
        $this->assertSame('rejected', $job->collaborators()->first()->pivot->status);

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.collaborators.approval', [$job->job_id, $invitee->id]), ['status' => 'accepted'])
            ->assertConflict();
        $this->assertSame('rejected', $job->fresh()->collaborators()->first()->pivot->status);
        $this->assertSame(1, ActivityLog::where('subject_id', $job->job_id)->where('action', 'collaborator_rejected')->count());
        $this->assertSame(1, SystemNotification::where('user_id', $owner->id)
            ->where('work_order_id', $job->job_id)
            ->where('type', 'collaborator_rejected')
            ->count());
    }
}
