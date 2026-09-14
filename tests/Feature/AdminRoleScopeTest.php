<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\AdminApprovalQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ขอบเขตบทบาทของ admin — ผู้ดูแลระบบ ไม่ใช่ผู้ดูแลงาน
 *
 * หลักการเดียวที่ชุดทดสอบนี้พิสูจน์: admin จะถูกแจ้งเตือนและเห็นในคิว เฉพาะเรื่องที่
 * **มีแต่ admin ทำได้** (คำขอลบงาน) หรือเรื่องที่ **ไม่มีหัวหน้าแผนกรับผิดชอบ** เท่านั้น
 * นอกนั้นเป็นงานของหัวหน้าแผนก
 *
 * ใช้ผังแผนกจริงของระบบ ซึ่งเป็นสิ่งที่ทำให้ทั้งสองสาขาของกติกาถูกเดินจริง:
 *   IT      — มีหัวหน้าแผนก   → คำขอของแผนกนี้ไม่ควรถึง admin เลย
 *   Account — ไม่มีหัวหน้าแผนก → คำขอของแผนกนี้ต้องตกมาที่ admin
 */
class AdminRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $account;

    private User $itHead;

    private User $itStaff;

    private User $accountStaff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->account = Department::create(['department_name' => 'Account']);

        $this->itHead = $this->account('it.head', 'หัวหน้าไอที', 'user', $this->it, true);
        $this->itStaff = $this->account('it.staff', 'พนักงานไอที', 'user', $this->it);
        $this->accountStaff = $this->account('account.staff', 'พนักงานบัญชี', 'user', $this->account);
        $this->admin = $this->account('system.admin', 'ผู้ดูแลระบบ', 'admin', null);
    }

    // =====================================================================
    // 1. เสียงรบกวนที่ต้องหายไป
    // =====================================================================

    /** มอบหมายงานภายในแผนก — หัวหน้าแผนกต้องรู้ แต่ admin ไม่เกี่ยว */
    public function test_an_intra_department_assignment_notifies_the_head_but_not_admins(): void
    {
        $this->actingAs($this->itStaff)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'ติดตั้งเครื่องพิมพ์',
                'user_id' => $this->itHead->id,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertCreated();

        $this->assertFalse(
            SystemNotification::where('user_id', $this->admin->id)->exists(),
            'admin ต้องไม่ได้รับแจ้งเตือนการมอบหมายงานภายในแผนกเลย'
        );
    }

    /** งานภายในแผนกที่มอบให้คนอื่น — หัวหน้าแผนกได้รับฉบับสรุป admin ไม่ได้ */
    public function test_the_department_head_still_receives_the_intra_department_summary(): void
    {
        $this->actingAs($this->itStaff)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'ตรวจเครือข่าย',
                'user_id' => $this->itStaff->id,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertCreated();

        $this->assertTrue(
            SystemNotification::where('user_id', $this->itHead->id)
                ->where('type', 'same_department_assignment')->exists(),
            'หัวหน้าแผนกต้องยังเห็นภาระงานของแผนกตัวเอง'
        );
        $this->assertFalse(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'same_department_assignment')->exists());
    }

    /** คอมเมนต์เป็นบทสนทนาของคนทำงาน ไม่ใช่เรื่องของผู้ดูแลระบบ */
    public function test_a_task_comment_never_notifies_uninvolved_admins(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->itStaff->id, 'name' => 'โปรเจกต์ไอที', 'priority' => 2]);
        $task = $this->task($this->itStaff, ['work_order_list_id' => $project->id]);
        $task->collaborators()->attach($this->itHead->id, [
            'status' => 'accepted', 'added_by' => $this->itStaff->id, 'decided_by' => $this->itStaff->id,
        ]);

        $this->actingAs($this->itStaff)
            ->postJson(route('tasks.comments.store', $task->job_id), ['message' => 'อัปเดตความคืบหน้าแล้ว'])
            ->assertSuccessful();

        $this->assertTrue(SystemNotification::where('user_id', $this->itHead->id)
            ->where('type', 'task_comment')->exists(), 'ผู้ร่วมงานต้องได้รับ');
        $this->assertFalse(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'task_comment')->exists(), 'admin ที่ไม่เกี่ยวข้องกับงานต้องไม่ได้รับ');
    }

    // =====================================================================
    // 2. คิวคำขออนุมัติ
    // =====================================================================

    /** คำขอเข้าแผนกที่มีหัวหน้า — หัวหน้ารับไป ไม่ถึง admin ทั้งแจ้งเตือนและคิว */
    public function test_a_request_for_a_department_with_a_head_never_reaches_the_admin_queue(): void
    {
        $this->actingAs($this->accountStaff)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'งานฝากไอที',
                'user_id' => $this->itStaff->id,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertCreated();

        $this->assertTrue(SystemNotification::where('user_id', $this->itHead->id)
            ->where('type', 'cross_department_pending')->exists());
        $this->assertFalse(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'cross_department_pending')->exists());

        $this->actingAs($this->admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertDontSee('งานฝากไอที');

        $this->actingAs($this->itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('งานฝากไอที');
    }

    /** คำขอเข้าแผนกที่ไม่มีหัวหน้า — admin รับไป ทั้งแจ้งเตือนและคิว */
    public function test_a_request_for_a_headless_department_lands_on_the_admin_queue(): void
    {
        $this->actingAs($this->itStaff)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'งานฝากบัญชี',
                'user_id' => $this->accountStaff->id,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertCreated();

        $this->assertTrue(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'cross_department_pending')->exists());

        $this->actingAs($this->admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('งานฝากบัญชี');
    }

    /** ตัวนับของ admin ต้องนับเฉพาะงานไร้หัวหน้า ไม่ใช่ทั้งระบบ */
    public function test_the_admin_badge_counts_only_work_no_head_owns(): void
    {
        // เข้าแผนก IT ที่มีหัวหน้า — ไม่ควรถูกนับให้ admin
        $this->pendingAssignment($this->accountStaff, $this->itStaff, $this->it, 'ของหัวหน้าไอที');
        // เข้าแผนก Account ที่ไม่มีหัวหน้า — ต้องถูกนับให้ admin
        $this->pendingAssignment($this->itStaff, $this->accountStaff, $this->account, 'ไม่มีใครดูแล');

        $adminCounts = app(AdminApprovalQuery::class)->counts($this->admin);
        $this->assertSame(1, $adminCounts['assignments'], 'admin ต้องนับเฉพาะงานที่ไม่มีหัวหน้ารับผิดชอบ');

        $headCounts = app(AdminApprovalQuery::class)->counts($this->itHead->fresh());
        $this->assertSame(1, $headCounts['assignments'], 'หัวหน้าแผนกนับเฉพาะของแผนกตัวเอง');
    }

    /** อำนาจไม่ถูกตัด — admin ที่เปิด URL ตรงยังอนุมัติงานของแผนกที่มีหัวหน้าได้ */
    public function test_an_admin_can_still_approve_as_a_safety_override(): void
    {
        $job = $this->pendingAssignment($this->accountStaff, $this->itStaff, $this->it, 'ของหัวหน้าไอที');

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tasks.approval', $job->job_id), ['approval_status' => 'approved'])
            ->assertSuccessful();

        $this->assertSame('approved', $job->fresh()->approval_status);
    }

    // =====================================================================
    // 3. เมนูแถบข้าง
    // =====================================================================

    public function test_the_approvals_menu_is_hidden_from_admins_with_an_empty_queue(): void
    {
        $this->actingAs($this->admin)->get(route('board.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.approvals.index').'"', false);
    }

    public function test_the_approvals_menu_appears_for_an_admin_once_work_has_no_owner(): void
    {
        $this->pendingAssignment($this->itStaff, $this->accountStaff, $this->account, 'ไม่มีใครดูแล');

        $this->actingAs($this->admin)->get(route('board.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.approvals.index').'"', false);
    }

    /** หัวหน้าแผนกเห็นเมนูเสมอ เพราะเป็นหน้าที่ประจำ ไม่ใช่เหตุการณ์ */
    public function test_the_approvals_menu_is_always_visible_to_a_department_head(): void
    {
        $this->actingAs($this->itHead)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.approvals.index').'"', false);
    }

    // =====================================================================
    // 4. Member Workspace อ่านอย่างเดียว
    // =====================================================================

    public function test_the_member_workspace_is_read_only_for_admins(): void
    {
        $this->task($this->itStaff, ['job_topic' => 'งานของพนักงานไอที']);

        $this->actingAs($this->admin)
            ->get(route('admin.work-board.member', [$this->it, $this->itStaff]))
            ->assertOk()
            ->assertSee('งานของพนักงานไอที')
            ->assertDontSee('data-open-admin-assignment', false)
            ->assertDontSee('data-quick-template', false);
    }

    /** admin ยังต้องเห็นงานที่รออนุมัติได้ เพราะการมองเห็นทั้งระบบเป็นหน้าที่ของผู้ดูแลระบบ */
    public function test_an_admin_still_sees_unapproved_work_in_the_member_workspace(): void
    {
        $this->task($this->itStaff, ['job_topic' => 'งานรออนุมัติ', 'approval_status' => 'pending']);

        $this->actingAs($this->admin)
            ->get(route('admin.work-board.member', [$this->it, $this->itStaff]))
            ->assertOk()
            ->assertSee('งานรออนุมัติ');
    }

    /** หัวหน้าแผนกยังเห็นเฉพาะงานที่อนุมัติแล้วเหมือนเดิม */
    public function test_a_department_head_still_only_sees_approved_work(): void
    {
        $this->task($this->itStaff, ['job_topic' => 'งานรออนุมัติ', 'approval_status' => 'pending']);

        $this->actingAs($this->itHead)
            ->get(route('work-board.member', [$this->it, $this->itStaff, 'workspace' => 1]))
            ->assertOk()
            ->assertDontSee('งานรออนุมัติ');
    }

    /** endpoint สร้างงานใน Member Workspace ถูกลบทิ้ง ไม่ใช่แค่ซ่อนปุ่ม */
    public function test_the_member_workspace_assignment_endpoint_no_longer_exists(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->admin->id, 'name' => 'โปรเจกต์', 'priority' => 2]);

        $this->actingAs($this->admin)
            ->postJson("/admin/work-board/departments/{$this->it->id}/members/{$this->itStaff->id}/projects/{$project->id}/tasks", [
                'job_topic' => 'แอบสร้าง',
            ])
            ->assertNotFound();

        $this->assertFalse(WorkOrder::where('job_topic', 'แอบสร้าง')->exists());
    }

    // =====================================================================
    // 5. สิ่งที่ต้องไม่หายไป
    // =====================================================================

    /**
     * คำขอลบงานเป็นข้อยกเว้นที่ตั้งใจ
     *
     * WorkOrderPolicy::delete() เปิดให้ admin เท่านั้น ถ้าไม่แจ้ง admin คำขอนี้จะไม่มีใคร
     * ตัดสินได้เลย เทสต์นี้กันไม่ให้ถูกถอดออกตามหลักการ "ลดบทบาท admin"
     */
    public function test_delete_requests_must_still_reach_admins(): void
    {
        $task = $this->task($this->itStaff);

        $this->actingAs($this->itStaff)
            ->postJson(route('tasks.deleteRequest.store', $task->job_id), ['reason' => 'สร้างผิด'])
            ->assertSuccessful();

        $this->assertTrue(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'delete_request')->exists(),
            'admin ต้องยังได้รับคำขอลบงาน เพราะเป็นคนเดียวที่ตัดสินได้');

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tasks.deleteRequest.approve', $task->job_id))
            ->assertSuccessful();
    }

    // =====================================================================

    private function account(string $username, string $name, string $role, ?Department $department, bool $head = false): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => true,
        ]);
    }

    private function task(User $owner, array $overrides = []): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => 'งานทดสอบ',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
            ...$overrides,
        ]);
    }

    private function pendingAssignment(User $creator, User $assignee, Department $destination, string $topic): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'department_id' => $destination->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'pending',
        ]);
    }
}
