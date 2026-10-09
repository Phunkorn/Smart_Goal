<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
            'job_status' => 2,
            'approval_status' => 'approved',
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

    /**
     * ไม่มีใครตัดสินการเข้าร่วมโปรเจกต์ได้อีกแล้ว เพราะไม่มีอะไรให้ตัดสิน
     *
     * การเพิ่มผู้เข้าร่วมจบในขั้นเดียวที่หัวหน้าโปรเจกต์ (ProjectRoles) และสมาชิกเอง
     * ก็ถอนตัวหรือกดรับคำเชิญไม่ได้ ต้องให้หัวหน้าโปรเจกต์นำออกเท่านั้น
     */
    public function test_nobody_decides_project_participation_and_members_cannot_withdraw_themselves(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $participant = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create(['role' => 'admin']);
        $job = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'Invitation replay guard',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $job->job_id), ['collaborators' => [$participant->id]])
            ->assertOk();

        $this->assertSame('accepted', $job->fresh()->collaborators()->first()->pivot->status);

        // ไม่มีเส้นทางให้ตอบรับ/ปฏิเสธคำเชิญ หรืออนุมัติผู้ร่วมงานอีกต่อไป
        foreach (['tasks.invitation.respond', 'admin.tasks.collaborators.approval'] as $retired) {
            $this->assertFalse(Route::has($retired), $retired.' ต้องถูกถอดออกแล้ว');
        }

        // สมาชิกถอนตัวเองไม่ได้ และ admin ก็ไม่มีคำขอให้ตัดสิน
        $this->actingAs($participant)
            ->deleteJson(route('tasks.collaborators.destroy', [$job->job_id, $participant->id]))
            ->assertForbidden();
        $this->assertSame('accepted', $job->fresh()->collaborators()->first()->pivot->status);
        $this->assertSame(0, ActivityLog::where('subject_id', $job->job_id)
            ->whereIn('action', ['collaborator_accepted', 'collaborator_rejected'])
            ->count());
        $this->assertSame(0, SystemNotification::whereIn('type', [
            'collaborator_approval_request',
            'collaborator_approved',
            'collaborator_rejected',
        ])->count());

        // admin ยังนำสมาชิกออกได้ในฐานะอำนาจสำรอง
        $this->actingAs($admin)
            ->deleteJson(route('tasks.collaborators.destroy', [$job->job_id, $participant->id]))
            ->assertOk();
        $this->assertFalse($job->fresh()->collaborators->contains('id', $participant->id));
    }
}
