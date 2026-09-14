<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderShare;
use App\Models\WorkOrderShareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การแชร์งานและคำขอเข้าร่วม
 *
 * สิ่งที่ชุดทดสอบนี้ต้องพิสูจน์มากที่สุดคือ "การอนุมัติของผู้แชร์ไม่ได้แปลว่าจบเสมอ"
 * คำขอในแผนกจบในตัวเอง ส่วนคำขอข้ามแผนกต้องตกไปที่คิวคำขออนุมัติของหัวหน้าแผนก
 * ตามกติกาเดิมของ CollaboratorInvitationService ซึ่งเป็นหัวใจของการไม่สร้าง
 * logic อนุมัติชุดใหม่
 */
class WorkOrderShareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * งานที่แชร์ได้ = งานย่อย จึงต้องมีงานแม่เสมอ
     *
     * ทุกเทสต์ในไฟล์นี้แชร์ "งานย่อย" เพราะ WorkOrderPolicy::share() อนุญาตเฉพาะงาน
     * ที่มี parent_job_id การแชร์ที่ระดับงานแม่เท่ากับเปิดงานทุกใบใต้มันให้คนนอก
     */
    private function task(User $owner, array $overrides = []): WorkOrder
    {
        $parent = $this->parentTask($owner);

        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'parent_job_id' => $parent->job_id,
            'job_topic' => 'ติดตั้งเครื่องพิมพ์',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
            ...$overrides,
        ]);
    }

    /** งานแม่ที่งานย่อยสังกัด — แชร์ไม่ได้ด้วยตัวเอง */
    private function parentTask(User $owner, array $overrides = []): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => 'งานแม่',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
            ...$overrides,
        ]);
    }

    private function member(?Department $department, string $role = 'user', array $overrides = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            ...$overrides,
        ]);
    }

    public function test_only_the_task_creator_or_leader_can_share_a_task(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $outsider = $this->member($department);
        $task = $this->task($owner);

        $this->actingAs($outsider)
            ->postJson(route('shares.store', $task->job_id), ['scope' => 'department'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('shares.store', $task->job_id), ['scope' => 'department'])
            ->assertCreated();

        $this->assertSame(1, WorkOrderShare::where('work_order_id', $task->job_id)->count());
    }

    /**
     * งานแม่แชร์ไม่ได้ แชร์ได้เฉพาะงานย่อย
     *
     * การเปิดให้คนนอกขอเข้าร่วมที่ระดับงานแม่ เท่ากับรับเขาเข้ามาในงานทุกใบที่อยู่ใต้มัน
     * ทั้งที่สิ่งที่ต้องการคือให้มาช่วยงานชิ้นใดชิ้นหนึ่ง การแชร์จึงต้องเกิดที่งานย่อย
     */
    public function test_only_a_subtask_can_be_shared(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $parent = $this->parentTask($owner);
        $subtask = $this->task($owner, ['parent_job_id' => $parent->job_id]);

        $this->actingAs($owner)
            ->postJson(route('shares.store', $parent->job_id), ['scope' => 'department'])
            ->assertForbidden();

        $this->assertSame(0, WorkOrderShare::where('work_order_id', $parent->job_id)->count());

        $this->actingAs($owner)
            ->postJson(route('shares.store', $subtask->job_id), ['scope' => 'department'])
            ->assertCreated();

        $this->assertFalse($owner->can('share', $parent));
        $this->assertTrue($owner->fresh()->can('share', $subtask));
    }

    public function test_a_task_cannot_be_shared_twice_while_a_share_is_open(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $task = $this->task($owner);

        $this->actingAs($owner)->postJson(route('shares.store', $task->job_id), ['scope' => 'department'])->assertCreated();
        $this->actingAs($owner)->postJson(route('shares.store', $task->job_id), ['scope' => 'department'])
            ->assertStatus(422);

        $this->assertSame(1, WorkOrderShare::where('work_order_id', $task->job_id)->count());
    }

    public function test_a_department_scoped_share_is_hidden_from_other_departments(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $colleague = $this->member($it);
        $stranger = $this->member($hr);
        $share = $this->openShare($owner, $this->task($owner), 'department');

        $this->actingAs($colleague)->get(route('shares.index'))
            ->assertOk()
            ->assertSee('ติดตั้งเครื่องพิมพ์');

        $this->actingAs($stranger)->get(route('shares.index'))
            ->assertOk()
            ->assertDontSee('ติดตั้งเครื่องพิมพ์');

        $this->actingAs($stranger)
            ->postJson(route('shares.requests.store', $share))
            ->assertForbidden();
    }

    public function test_an_organization_scoped_share_reaches_every_department(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $stranger = $this->member($hr);
        $this->openShare($owner, $this->task($owner), 'organization');

        $this->actingAs($stranger)->get(route('shares.index'))
            ->assertOk()
            ->assertSee('ติดตั้งเครื่องพิมพ์');
    }

    public function test_a_viewer_cannot_reach_the_share_page(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $viewer = $this->member($department, 'viewer');

        $this->actingAs($viewer)->get(route('shares.index'))->assertForbidden();
    }

    public function test_requesting_to_join_notifies_the_sharer(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $share = $this->openShare($owner, $this->task($owner), 'department');

        $this->actingAs($colleague)
            ->postJson(route('shares.requests.store', $share))
            ->assertCreated();

        $this->assertDatabaseHas('work_order_share_requests', [
            'work_order_share_id' => $share->id,
            'requester_id' => $colleague->id,
            'status' => 'pending',
        ]);

        $this->assertTrue(SystemNotification::where('user_id', $owner->id)
            ->where('type', 'share_join_requested')
            ->exists());
    }

    public function test_the_same_person_cannot_request_twice(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $share = $this->openShare($owner, $this->task($owner), 'department');

        $this->actingAs($colleague)->postJson(route('shares.requests.store', $share))->assertCreated();
        $this->actingAs($colleague)->postJson(route('shares.requests.store', $share))->assertStatus(422);

        $this->assertSame(1, WorkOrderShareRequest::where('work_order_share_id', $share->id)->count());
    }

    /**
     * แผนกเดียวกัน — จบในขั้นเดียว
     */
    public function test_approving_a_same_department_request_adds_the_collaborator_immediately(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $task = $this->task($owner);
        $share = $this->openShare($owner, $task, 'department');
        $request = $this->join($share, $colleague);

        $this->actingAs($owner)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertOk()
            ->assertJsonPath('collaborator_status', 'accepted');

        $pivot = $task->fresh()->collaborators->firstWhere('id', $colleague->id);
        $this->assertNotNull($pivot, 'ผู้ขอต้องถูกเพิ่มเป็นผู้ร่วมงาน');
        $this->assertSame('accepted', $pivot->pivot->status);
        $this->assertSame('approved', $request->fresh()->status);
    }

    /**
     * หัวหน้าแผนกแชร์งานของแผนกตัวเอง — อนุมัติแล้วจบในขั้นเดียว
     *
     * หัวหน้าแผนกที่ดูแลแผนกปลายทางของงานคือผู้มีอำนาจสูงสุดของงานนั้นอยู่แล้ว การส่ง
     * คำขอต่อจึงเท่ากับให้เขาขออนุมัติจากตัวเอง และเดิมมันเด้งไปหา admin ด้วย
     */
    public function test_a_department_head_who_shares_admits_a_cross_department_requester_immediately(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $head = $this->member($it, 'user', ['is_department_head' => true]);
        $stranger = $this->member($hr);
        $hrHead = $this->member($hr, 'user', ['is_department_head' => true]);
        $admin = $this->member(null, 'admin');
        $task = $this->task($head);
        $share = $this->openShare($head, $task, 'organization');
        $request = $this->join($share, $stranger);

        $this->actingAs($head)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertOk()
            ->assertJsonPath('collaborator_status', 'accepted');

        $pivot = $task->fresh()->collaborators->firstWhere('id', $stranger->id);
        $this->assertSame('accepted', $pivot->pivot->status, 'หัวหน้าแผนกอนุมัติแล้วต้องเข้าร่วมทันที');
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertFalse($request->fresh()->awaitsDepartmentHead());

        // ไม่มีใครถูกรบกวนให้อนุมัติซ้ำอีกชั้น
        foreach ([$admin, $hrHead] as $bystander) {
            $this->assertFalse(
                SystemNotification::where('user_id', $bystander->id)
                    ->whereIn('type', ['share_join_awaiting_head', 'collaborator_approval_request'])
                    ->exists(),
                'ไม่ควรมีคำขออนุมัติชั้นที่สองถูกส่งออกไปเลย'
            );
        }
    }

    /**
     * พนักงานธรรมดาแชร์ ผู้ขอต่างแผนก — ส่งต่อหัวหน้าแผนกของ "ผู้แชร์"
     *
     * ไม่ใช่หัวหน้าแผนกของผู้ขอ เพราะงานเป็นของแผนกผู้แชร์ คนที่ต้องตัดสินว่าจะรับคนนอก
     * เข้ามาในงานของแผนกจึงเป็นหัวหน้าแผนกนั้น
     */
    public function test_a_plain_employee_escalates_a_cross_department_request_to_their_own_head(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $itHead = $this->member($it, 'user', ['is_department_head' => true]);
        $hrHead = $this->member($hr, 'user', ['is_department_head' => true]);
        $stranger = $this->member($hr);
        $task = $this->task($owner);
        $share = $this->openShare($owner, $task, 'organization');
        $request = $this->join($share, $stranger);

        $this->actingAs($owner)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertOk();

        $request->refresh();
        $this->assertSame('awaiting_head', $request->status);
        $this->assertTrue($request->awaitsDepartmentHead());
        $this->assertSame($owner->id, $request->decided_by, 'ต้องเก็บว่าผู้แชร์เป็นคนอนุมัติชั้นแรก');

        // ยังไม่แตะ pivot ผู้ร่วมงานจนกว่าจะผ่านชั้นที่สอง
        $this->assertFalse($task->fresh()->collaborators->contains('id', $stranger->id));

        $this->assertTrue(SystemNotification::where('user_id', $itHead->id)
            ->where('type', 'share_join_awaiting_head')->exists(), 'หัวหน้าแผนกของผู้แชร์ต้องได้รับคำขอ');
        $this->assertFalse(SystemNotification::where('user_id', $hrHead->id)
            ->where('type', 'share_join_awaiting_head')->exists(), 'หัวหน้าแผนกของผู้ขอไม่เกี่ยวกับชั้นนี้');

        // หัวหน้าแผนกของผู้แชร์เห็นคำขอในหน้าคำขออนุมัติ แล้วอนุมัติจบ
        $this->actingAs($itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('ผู้ขอร่วมงานจากการแชร์งาน')
            ->assertSee($stranger->name);

        $this->actingAs($itHead)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertOk()
            ->assertJsonPath('collaborator_status', 'accepted');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($itHead->id, $request->head_decided_by);
        $this->assertSame($owner->id, $request->decided_by, 'การตัดสินชั้นแรกต้องไม่ถูกเขียนทับ');
        $this->assertSame('accepted', $task->fresh()->collaborators->firstWhere('id', $stranger->id)->pivot->status);
    }

    public function test_a_head_of_another_department_can_neither_see_nor_decide_the_request(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $hrHead = $this->member($hr, 'user', ['is_department_head' => true]);
        $stranger = $this->member($hr);
        $share = $this->openShare($owner, $this->task($owner), 'organization');
        $request = $this->join($share, $stranger);

        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertOk();

        $this->actingAs($hrHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertDontSee($stranger->name);

        $this->actingAs($hrHead)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertForbidden();
    }

    /**
     * แผนกของผู้แชร์ไม่มีหัวหน้า — admin รับไปตาม fallback เดิมของ
     * NotificationService::departmentApprovalRecipientIds()
     */
    public function test_admins_receive_the_escalation_when_the_department_has_no_head(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $stranger = $this->member($hr);
        $admin = $this->member(null, 'admin');
        $share = $this->openShare($owner, $this->task($owner), 'organization');
        $request = $this->join($share, $stranger);

        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertOk();

        $this->assertTrue(SystemNotification::where('user_id', $admin->id)
            ->where('type', 'share_join_awaiting_head')->exists());

        $this->actingAs($admin)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertOk();

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_the_second_tier_can_also_reject_the_request(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $itHead = $this->member($it, 'user', ['is_department_head' => true]);
        $stranger = $this->member($hr);
        $task = $this->task($owner);
        $share = $this->openShare($owner, $task, 'organization');
        $request = $this->join($share, $stranger);

        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertOk();

        $this->actingAs($itHead)
            ->patchJson(route('shares.requests.reject', $request), ['decision_reason' => 'ทีมเต็มแล้ว'])
            ->assertOk();

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame($itHead->id, $request->head_decided_by);
        $this->assertFalse($task->fresh()->collaborators->contains('id', $stranger->id));
    }

    public function test_deciding_an_escalated_request_twice_reports_a_conflict(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $itHead = $this->member($it, 'user', ['is_department_head' => true]);
        $stranger = $this->member($hr);
        $share = $this->openShare($owner, $this->task($owner), 'organization');
        $request = $this->join($share, $stranger);

        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertOk();
        $this->actingAs($itHead)->patchJson(route('shares.requests.approve', $request))->assertOk();
        $this->actingAs($itHead)->patchJson(route('shares.requests.approve', $request))->assertStatus(409);
    }

    /**
     * การเชิญผู้ร่วมงานแบบเดิมต้องไม่เปลี่ยนพฤติกรรมเลย
     *
     * invite() ได้พารามิเตอร์ใหม่ actorHasFinalSay ที่ค่าเริ่มต้นเป็น false ผู้เรียกเดิม
     * ทุกจุดจึงต้องได้ผลเหมือนเดิม — คำขอข้ามแผนกยังเป็น pending และยังไปที่คิวของ
     * หัวหน้าแผนก "ผู้ถูกเชิญ" ตามโมเดลยืมตัวคนแบบเดิม
     */
    public function test_the_classic_collaborator_invite_flow_is_unchanged(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $owner = $this->member($it);
        $hrHead = $this->member($hr, 'user', ['is_department_head' => true]);
        $stranger = $this->member($hr);
        $task = $this->task($owner);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$stranger->id]])
            ->assertSuccessful();

        $pivot = $task->fresh()->collaborators->firstWhere('id', $stranger->id);
        $this->assertSame('pending', $pivot->pivot->status, 'การเชิญข้ามแผนกแบบเดิมต้องยังเป็น pending');
        $this->assertTrue(SystemNotification::where('user_id', $hrHead->id)
            ->where('type', 'collaborator_approval_request')->exists(),
            'ยังต้องเข้าคิวของหัวหน้าแผนกผู้ถูกเชิญเหมือนเดิม');
    }

    public function test_approving_the_same_request_twice_reports_a_conflict(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $share = $this->openShare($owner, $this->task($owner), 'department');
        $request = $this->join($share, $colleague);

        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertOk();
        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertStatus(409);
    }

    public function test_rejecting_a_request_records_the_reason_and_notifies_the_requester(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $share = $this->openShare($owner, $this->task($owner), 'department');
        $request = $this->join($share, $colleague);

        $this->actingAs($owner)
            ->patchJson(route('shares.requests.reject', $request), ['decision_reason' => 'ทีมเต็มแล้ว'])
            ->assertOk();

        $this->assertDatabaseHas('work_order_share_requests', [
            'id' => $request->id,
            'status' => 'rejected',
            'decision_reason' => 'ทีมเต็มแล้ว',
        ]);

        $this->assertTrue(SystemNotification::where('user_id', $colleague->id)
            ->where('type', 'share_join_rejected')
            ->exists());
    }

    public function test_only_the_sharer_can_decide_a_request(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $bystander = $this->member($department);
        $share = $this->openShare($owner, $this->task($owner), 'department');
        $request = $this->join($share, $colleague);

        $this->actingAs($bystander)
            ->patchJson(route('shares.requests.approve', $request))
            ->assertForbidden();
    }

    public function test_closing_a_share_cancels_the_requests_that_were_still_waiting(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $share = $this->openShare($owner, $this->task($owner), 'department');
        $request = $this->join($share, $colleague);

        $this->actingAs($owner)->deleteJson(route('shares.destroy', $share))->assertOk();

        $this->assertSame('closed', $share->fresh()->status);
        $this->assertSame('cancelled', $request->fresh()->status);
    }

    /**
     * ปิดประกาศแล้วต้องหายไปจากหน้า ไม่ใช่ค้างไว้เป็นรายการที่กดอะไรไม่ได้
     *
     * ของเดิมยังแสดงพร้อมป้าย "ปิดแล้ว" ซึ่งกลายเป็นประวัติว่าครั้งหนึ่งเคยเปิดแชร์
     * งานใบไหนไว้บ้าง ทั้งที่ผู้แชร์ตั้งใจกดปิดเพื่อให้มันหายไป
     */
    public function test_a_closed_share_disappears_from_the_page(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $sharer = $this->member($department);
        $task = $this->task($sharer, ['job_topic' => 'งานที่เคยแชร์']);

        $this->actingAs($sharer)
            ->postJson(route('shares.store', $task->job_id), ['scope' => 'department'])
            ->assertCreated();

        $share = WorkOrderShare::query()->where('work_order_id', $task->job_id)->firstOrFail();

        $this->actingAs($sharer)->get(route('shares.index', ['tab' => 'incoming']))
            ->assertOk()
            ->assertSee($task->job_topic);

        $this->actingAs($sharer)
            ->deleteJson(route('shares.destroy', $share))
            ->assertOk();

        $this->actingAs($sharer)->get(route('shares.index', ['tab' => 'incoming']))
            ->assertOk()
            ->assertDontSee($task->job_topic)
            ->assertDontSee('ปิดแล้ว');

        // แถวยังอยู่ในฐานข้อมูล ประวัติจึงตรวจย้อนหลังได้ แค่ไม่ถูกแสดงบนหน้า
        $this->assertDatabaseHas('work_order_shares', ['id' => $share->id, 'status' => 'closed']);
    }

    /**
     * การ์ดในฟีดบอกว่าใครชวน และงานย่อยที่ถูกแชร์อยู่ใต้งานไหน
     *
     * ชื่องานย่อยอย่างเดียวไม่พอให้ตัดสินใจ คนอ่านต้องเห็นบริบทว่ามันเป็นส่วนหนึ่ง
     * ของงานอะไรในโปรเจกต์ไหน
     */
    public function test_the_feed_card_names_the_sharer_and_its_parent_task(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $sharer = $this->member($department);
        $viewer = $this->member($department);
        $task = $this->task($sharer, ['job_topic' => 'ติดตั้งระบบ']);

        $this->actingAs($sharer)
            ->postJson(route('shares.store', $task->job_id), ['scope' => 'department'])
            ->assertCreated();

        $this->actingAs($viewer)->get(route('shares.index'))
            ->assertOk()
            // ใครชวน พร้อมรูปประจำตัวที่ใช้คอมโพเนนต์เดียวกับบอร์ดงาน
            ->assertSee($sharer->name)
            ->assertSee('wb-avatar', false)
            ->assertSee('แชร์งานนี้ให้ร่วมทำ')
            // รายการที่ถูกแชร์ พร้อมบริบทว่าอยู่ใต้งานไหน
            ->assertSee($task->job_topic)
            ->assertSee($task->parent->job_topic)
            ->assertSee('เข้าร่วมเพื่อทำงานย่อยรายการนี้');
    }

    public function test_a_closed_task_can_no_longer_take_join_requests(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $task = $this->task($owner);
        $share = $this->openShare($owner, $task, 'department');

        $task->update(['job_status' => 4]);

        $this->actingAs($colleague)
            ->postJson(route('shares.requests.store', $share))
            ->assertStatus(422);
    }

    /**
     * ผู้ที่เข้าร่วมผ่านการแชร์ออกจากงานเองไม่ได้ ต้องให้เจ้าของเอาออก
     *
     * เป็นกฎเดิมของระบบ (WorkOrderPolicy::respondToInvitation() คืน false เสมอ)
     * การแชร์งานต้องไม่กลายเป็นทางลัดข้ามกฎนี้
     */
    public function test_a_member_who_joined_through_a_share_cannot_remove_themselves(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $colleague = $this->member($department);
        $task = $this->task($owner);
        $share = $this->openShare($owner, $task, 'department');
        $request = $this->join($share, $colleague);
        $this->actingAs($owner)->patchJson(route('shares.requests.approve', $request))->assertOk();

        $this->actingAs($colleague)
            ->deleteJson(route('tasks.collaborators.destroy', [$task->job_id, $colleague->id]))
            ->assertForbidden();

        $this->assertTrue($task->fresh()->collaborators->contains('id', $colleague->id));

        $this->actingAs($owner)
            ->deleteJson(route('tasks.collaborators.destroy', [$task->job_id, $colleague->id]))
            ->assertSuccessful();

        $this->assertFalse($task->fresh()->collaborators->contains('id', $colleague->id));
    }

    /**
     * ปุ่มแชร์อยู่บนงานย่อยเท่านั้น งานแม่ต้องไม่มีให้กด
     *
     * สิทธิ์จริงตัดสินที่ policy อยู่แล้ว แต่ปุ่มที่กดแล้วเจอ 403 คือ UI ที่หลอกผู้ใช้
     * เทสต์นี้จึงยืนยันทั้งสองด้าน: มีบนงานย่อย และไม่มีเมื่อในหน้ามีแต่งานแม่
     */
    public function test_the_share_menu_item_appears_only_on_subtask_rows(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->member($department);
        $this->task($owner);

        $this->actingAs($owner)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-share-task', false);

        $lonelyOwner = $this->member($department);
        $this->parentTask($lonelyOwner);

        $this->actingAs($lonelyOwner)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertDontSee('data-share-task', false);
    }

    private function openShare(User $owner, WorkOrder $task, string $scope): WorkOrderShare
    {
        return WorkOrderShare::create([
            'work_order_id' => $task->job_id,
            'shared_by' => $owner->id,
            'department_id' => $owner->department_id,
            'scope' => $scope,
            'status' => 'open',
        ]);
    }

    private function join(WorkOrderShare $share, User $requester): WorkOrderShareRequest
    {
        return WorkOrderShareRequest::create([
            'work_order_share_id' => $share->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
        ]);
    }
}
