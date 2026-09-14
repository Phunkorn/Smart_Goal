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
 * สถานการณ์ข้ามแผนกทั้งสามเส้นทาง ด้วยผังบัญชีจริงของระบบ
 *
 * จำลองบัญชีจริงสามใบและโครงแผนกจริง เพราะพฤติกรรมของทั้งสามเส้นทางขึ้นกับสองสิ่งนี้
 * โดยตรง และ "แผนก Account ไม่มีหัวหน้า" คือเงื่อนไขที่ทำให้เห็นพฤติกรรม fallback
 * ที่หาไม่เจอถ้าทุกแผนกมีหัวหน้าครบ:
 *
 *   phunkorn.sr     role=user  is_department_head=true   แผนก IT       (หัวหน้าแผนกไอที)
 *   thanabodee      role=user  is_department_head=false  แผนก IT       (พนักงาน IT)
 *   phunkorn.sr123  role=user  is_department_head=false  แผนก Account  (พนักงาน Account)
 *
 * แผนก IT มีหัวหน้า ส่วน Account ไม่มีหัวหน้าเลย — ทุกเส้นทางที่วิ่งไปหา "หัวหน้าแผนก
 * ของฝั่ง Account" จึงตกไปหา admin ตาม fallback ของ
 * NotificationService::departmentApprovalRecipientIds()
 *
 * ทดสอบผ่าน HTTP endpoint จริงทุกข้อ ไม่เรียก service ตรง ๆ เพื่อให้ครอบคลุมทั้ง
 * validation, policy, สถานะที่บันทึกลงฐานข้อมูล และรูปร่าง response
 */
class CrossDepartmentScenarioTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $account;

    private User $itHead;      // phunkorn.sr

    private User $itStaff;     // thanabodee

    private User $accountStaff; // phunkorn.sr123

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->account = Department::create(['department_name' => 'Account']);

        $this->itHead = $this->account('phunkorn.sr', 'พันกร ศรีทอน', 'user', $this->it, true);
        $this->itStaff = $this->account('thanabodee', 'Benzz', 'user', $this->it);
        $this->accountStaff = $this->account('phunkorn.sr123', 'ทดสอบ', 'user', $this->account);
        $this->admin = $this->account('admin.system', 'ผู้ดูแลระบบ', 'admin', null);
    }

    // =====================================================================
    // 1. มอบหมายงานข้ามแผนก
    // =====================================================================

    /**
     * หัวหน้าแผนก IT มอบหมายงานให้พนักงาน Account
     *
     * ปลายทางคือแผนก Account ซึ่งไม่มีหัวหน้า คำขอจึงตกไปหา admin ตาม fallback
     * งานต้องยัง pending จนกว่าจะมีคนอนุมัติ และผู้รับต้องยังไม่เห็นงาน
     */
    public function test_assigning_across_departments_creates_a_pending_work_order(): void
    {
        $response = $this->actingAs($this->itHead)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'งานข้ามแผนก',
                'job_topic' => 'ตรวจสอบระบบบัญชี',
                'user_id' => $this->accountStaff->id,
                'job_priority' => 2,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDays(3)->toDateString(),
            ])
            ->assertCreated();

        $job = WorkOrder::findOrFail($response->json('job_id'));

        $this->assertSame('pending', $job->approval_status, 'งานข้ามแผนกต้องรออนุมัติก่อน');
        $this->assertSame((int) $this->accountStaff->id, (int) $job->user_id);
        $this->assertSame((int) $this->itHead->id, (int) $job->created_by);
        $this->assertNull($job->approved_by);

        // ผู้รับยังเปิดงานไม่ได้จนกว่าจะอนุมัติ
        $this->assertTrue($this->admin->can('view', $job));

        // Account ไม่มีหัวหน้า คำขอจึงไปหา admin
        $this->assertTrue(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'cross_department_pending')->exists(),
            'แผนกปลายทางไม่มีหัวหน้า คำขอต้องตกไปหา admin');
        $this->assertFalse(SystemNotification::where('user_id', $this->itHead->id)
            ->where('type', 'cross_department_pending')->exists(),
            'หัวหน้าแผนกต้นทางไม่ใช่ผู้อนุมัติของเส้นทางนี้');
    }

    /** งานในแผนกเดียวกันต้องไม่ต้องขออนุมัติเลย */
    public function test_assigning_inside_the_same_department_is_approved_immediately(): void
    {
        $response = $this->actingAs($this->itHead)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'งานในแผนก',
                'job_topic' => 'ติดตั้งเครื่องพิมพ์',
                'user_id' => $this->itStaff->id,
                'job_priority' => 2,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertCreated();

        $job = WorkOrder::findOrFail($response->json('job_id'));

        $this->assertSame('approved', $job->approval_status);
        $this->assertTrue($this->itStaff->can('view', $job));
        $this->assertFalse(SystemNotification::where('type', 'cross_department_pending')->exists());
    }

    /** อนุมัติแล้วผู้รับจึงเห็นงาน และสถานะต้องเปลี่ยนครบทุกฟิลด์ */
    public function test_an_admin_can_approve_the_cross_department_assignment(): void
    {
        $job = $this->crossDepartmentJob();

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tasks.approval', $job->job_id), ['approval_status' => 'approved'])
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame('approved', $job->approval_status);
        $this->assertSame((int) $this->admin->id, (int) $job->approved_by);
        $this->assertNotNull($job->approved_at);
        $this->assertTrue($this->accountStaff->can('view', $job), 'อนุมัติแล้วผู้รับต้องเปิดงานได้');
    }

    /** พนักงานธรรมดาต้องอนุมัติงานข้ามแผนกไม่ได้ */
    public function test_a_plain_employee_cannot_approve_a_cross_department_assignment(): void
    {
        $job = $this->crossDepartmentJob();

        $this->actingAs($this->itStaff)
            ->patchJson(route('admin.tasks.approval', $job->job_id), ['approval_status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $job->fresh()->approval_status);
    }

    // =====================================================================
    // 2. ดึงคนข้ามแผนกเข้ามาในงาน (ผู้ร่วมงาน)
    // =====================================================================

    /**
     * ดึงพนักงาน Account เข้างานของ IT
     *
     * โมเดลของเส้นทางนี้คือ "ยืมตัวคน" ผู้อนุมัติจึงเป็นหัวหน้าแผนกของคนที่ถูกยืม
     * ซึ่งคือ Account ที่ไม่มีหัวหน้า จึงตกไปหา admin
     */
    public function test_pulling_a_cross_department_person_into_a_task_waits_for_approval(): void
    {
        $job = $this->approvedTask($this->itStaff);

        $this->actingAs($this->itStaff)
            ->postJson(route('tasks.collaborators.store', $job->job_id), [
                'collaborators' => [$this->accountStaff->id],
            ])
            ->assertSuccessful();

        $pivot = $job->fresh()->collaborators->firstWhere('id', $this->accountStaff->id);
        $this->assertSame('pending', $pivot->pivot->status);
        $this->assertNull($pivot->pivot->decided_by);
        $this->assertSame((int) $this->itStaff->id, (int) $pivot->pivot->added_by);

        $this->assertTrue(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'collaborator_approval_request')->exists());
    }

    /** คนในแผนกเดียวกันเข้าร่วมได้ทันที ไม่ต้องอนุมัติ */
    public function test_pulling_a_same_department_person_is_immediate(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->itHead)
            ->postJson(route('tasks.collaborators.store', $job->job_id), [
                'collaborators' => [$this->itStaff->id],
            ])
            ->assertSuccessful();

        $pivot = $job->fresh()->collaborators->firstWhere('id', $this->itStaff->id);
        $this->assertSame('accepted', $pivot->pivot->status);
        $this->assertNotNull($pivot->pivot->responded_at);
    }

    /** ผู้ร่วมงานเองไม่มีสิทธิ์จัดการทีม แม้จะอยู่ในงานแล้ว */
    public function test_a_collaborator_cannot_manage_the_team(): void
    {
        $job = $this->approvedTask($this->itHead);
        $job->collaborators()->attach($this->itStaff->id, [
            'status' => 'accepted', 'added_by' => $this->itHead->id, 'decided_by' => $this->itHead->id,
        ]);

        $this->actingAs($this->itStaff)
            ->postJson(route('tasks.collaborators.store', $job->job_id), [
                'collaborators' => [$this->accountStaff->id],
            ])
            ->assertForbidden();
    }

    /** ผู้รับผิดชอบหลักถูกนำออกจากทีมไม่ได้ */
    public function test_the_task_owner_cannot_be_removed_from_the_team(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->itHead)
            ->deleteJson(route('tasks.collaborators.destroy', [$job->job_id, $this->itHead->id]))
            ->assertStatus(422);
    }

    // =====================================================================
    // 3. แชร์งาน
    // =====================================================================

    /**
     * หัวหน้าแผนก IT แชร์งาน พนักงาน Account ขอเข้าร่วม
     *
     * นี่คือเคสที่เคยพัง: หัวหน้ากดอนุมัติแล้วระบบยังส่งคำขอต่อไปหา admin
     * ตอนนี้ต้องจบในขั้นเดียว
     */
    public function test_the_it_head_sharing_admits_an_account_employee_immediately(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->itHead)
            ->postJson(route('shares.store', $job->job_id), [
                'scope' => 'organization',
                'note' => 'ใครสนใจงานบัญชีมาช่วยกันได้',
            ])
            ->assertCreated();

        $share = WorkOrderShare::where('work_order_id', $job->job_id)->firstOrFail();
        $this->assertSame('organization', $share->scope);
        $this->assertSame((int) $this->it->id, (int) $share->department_id, 'ต้องเก็บแผนกผู้แชร์เป็นสแนปช็อต');

        $this->actingAs($this->accountStaff)
            ->postJson(route('shares.requests.store', $share))
            ->assertCreated();

        $shareRequest = WorkOrderShareRequest::where('work_order_share_id', $share->id)->firstOrFail();
        $this->assertSame('pending', $shareRequest->status);

        $this->actingAs($this->itHead)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk()
            ->assertJsonPath('collaborator_status', 'accepted');

        $shareRequest->refresh();
        $this->assertSame('approved', $shareRequest->status);
        $this->assertNull($shareRequest->head_decided_by, 'จบตั้งแต่ชั้นผู้แชร์ ไม่มีการตัดสินชั้นสอง');

        $pivot = $job->fresh()->collaborators->firstWhere('id', $this->accountStaff->id);
        $this->assertSame('accepted', $pivot->pivot->status);
        $this->assertTrue($this->accountStaff->fresh()->can('view', $job->fresh()));

        $this->assertFalse(
            SystemNotification::whereIn('type', ['share_join_awaiting_head', 'collaborator_approval_request'])->exists(),
            'หัวหน้าแผนกแชร์เอง ต้องไม่มีคำขออนุมัติชั้นที่สองเกิดขึ้นเลย'
        );
    }

    /**
     * พนักงาน IT แชร์งาน พนักงาน Account ขอเข้าร่วม
     *
     * ต้องส่งต่อให้หัวหน้าแผนก IT (แผนกของผู้แชร์) ไม่ใช่ฝั่ง Account
     */
    public function test_an_it_employee_sharing_escalates_to_the_it_head(): void
    {
        $job = $this->approvedTask($this->itStaff);
        $share = $this->openShare($this->itStaff, $job, 'organization');

        $this->actingAs($this->accountStaff)
            ->postJson(route('shares.requests.store', $share))
            ->assertCreated();

        $shareRequest = WorkOrderShareRequest::where('work_order_share_id', $share->id)->firstOrFail();

        $this->actingAs($this->itStaff)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk();

        $shareRequest->refresh();
        $this->assertSame('awaiting_head', $shareRequest->status);
        $this->assertSame((int) $this->itStaff->id, (int) $shareRequest->decided_by);
        $this->assertFalse($job->fresh()->collaborators->contains('id', $this->accountStaff->id),
            'ยังไม่ถูกเพิ่มเข้าทีมจนกว่าจะผ่านชั้นที่สอง');

        $this->assertTrue(SystemNotification::where('user_id', $this->itHead->id)
            ->where('type', 'share_join_awaiting_head')->exists(),
            'หัวหน้าแผนกของผู้แชร์ต้องได้รับคำขอ');
        $this->assertFalse(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'share_join_awaiting_head')->exists(),
            'แผนกผู้แชร์มีหัวหน้าอยู่แล้ว จึงไม่ต้องรบกวน admin');

        // หัวหน้าแผนกเห็นคำขอในหน้าคำขออนุมัติ แล้วกดอนุมัติจบ
        $this->actingAs($this->itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('ผู้ขอร่วมงานจากการแชร์งาน')
            ->assertSee($this->accountStaff->name);

        $this->actingAs($this->itHead)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk()
            ->assertJsonPath('collaborator_status', 'accepted');

        $shareRequest->refresh();
        $this->assertSame('approved', $shareRequest->status);
        $this->assertSame((int) $this->itHead->id, (int) $shareRequest->head_decided_by);
        $this->assertSame((int) $this->itStaff->id, (int) $shareRequest->decided_by, 'ชั้นแรกต้องไม่ถูกเขียนทับ');
        $this->assertSame('accepted',
            $job->fresh()->collaborators->firstWhere('id', $this->accountStaff->id)->pivot->status);
    }

    /**
     * พนักงาน Account แชร์งาน — แผนกตัวเองไม่มีหัวหน้า
     *
     * fallback ต้องไปหา admin ตามที่ตกลงไว้ ไม่ใช่ค้างอยู่เฉย ๆ โดยไม่มีใครตัดสิน
     */
    public function test_a_share_from_a_department_without_a_head_escalates_to_admins(): void
    {
        $job = $this->approvedTask($this->accountStaff);
        $share = $this->openShare($this->accountStaff, $job, 'organization');

        $this->actingAs($this->itStaff)
            ->postJson(route('shares.requests.store', $share))
            ->assertCreated();

        $shareRequest = WorkOrderShareRequest::where('work_order_share_id', $share->id)->firstOrFail();

        $this->actingAs($this->accountStaff)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk();

        $this->assertSame('awaiting_head', $shareRequest->fresh()->status);
        $this->assertTrue(SystemNotification::where('user_id', $this->admin->id)
            ->where('type', 'share_join_awaiting_head')->exists(),
            'แผนกไม่มีหัวหน้า ต้องตกไปหา admin');

        $this->actingAs($this->admin)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertOk();

        $this->assertSame('approved', $shareRequest->fresh()->status);
    }

    /** ขอบเขต "เฉพาะแผนก" ต้องไม่รั่วไปแผนกอื่น */
    public function test_a_department_scoped_share_never_leaks_to_another_department(): void
    {
        $job = $this->approvedTask($this->itHead);
        $this->openShare($this->itHead, $job, 'department');

        $this->actingAs($this->itStaff)->get(route('shares.index'))
            ->assertOk()->assertSee($job->job_topic);

        $this->actingAs($this->accountStaff)->get(route('shares.index'))
            ->assertOk()->assertDontSee($job->job_topic);
    }

    /** หัวหน้าแผนก IT ต้องไม่เห็นคำขอของงานที่เป็นของแผนกอื่น */
    public function test_the_it_head_cannot_decide_a_share_request_belonging_to_another_department(): void
    {
        $job = $this->approvedTask($this->accountStaff);
        $share = $this->openShare($this->accountStaff, $job, 'organization');
        $shareRequest = $this->join($share, $this->itStaff);

        $this->actingAs($this->accountStaff)
            ->patchJson(route('shares.requests.approve', $shareRequest))->assertOk();

        $this->actingAs($this->itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertDontSee('ขอเข้าร่วมงาน '.$job->job_topic);

        $this->actingAs($this->itHead)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertForbidden();
    }

    // =====================================================================
    // 4. Validation ของข้อมูลที่ส่งเข้ามา
    // =====================================================================

    /** ค่า scope ต้องเป็นหนึ่งในสองค่าที่ระบบรู้จักเท่านั้น */
    public function test_the_share_scope_must_be_one_of_the_known_values(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->itHead)
            ->postJson(route('shares.store', $job->job_id), ['scope' => 'everyone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');

        $this->actingAs($this->itHead)
            ->postJson(route('shares.store', $job->job_id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');

        $this->assertSame(0, WorkOrderShare::count(), 'คำขอที่ validate ไม่ผ่านต้องไม่สร้างประกาศ');
    }

    /** ข้อความชวนยาวเกินกำหนดต้องถูกปฏิเสธ ไม่ใช่ถูกตัดเงียบ ๆ */
    public function test_an_over_long_share_note_is_rejected(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->itHead)
            ->postJson(route('shares.store', $job->job_id), [
                'scope' => 'department',
                'note' => str_repeat('ก', 501),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    /** เหตุผลการปฏิเสธยาวเกินกำหนดต้องถูกปฏิเสธ */
    public function test_an_over_long_rejection_reason_is_rejected(): void
    {
        $job = $this->approvedTask($this->itHead);
        $share = $this->openShare($this->itHead, $job, 'organization');
        $shareRequest = $this->join($share, $this->accountStaff);

        $this->actingAs($this->itHead)
            ->patchJson(route('shares.requests.reject', $shareRequest), [
                'decision_reason' => str_repeat('ก', 1001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('decision_reason');

        $this->assertSame('pending', $shareRequest->fresh()->status);
    }

    /** วันครบกำหนดก่อนวันเริ่มต้องไม่ผ่าน */
    public function test_a_due_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAs($this->itHead)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'ช่วงเวลาผิด',
                'user_id' => $this->itStaff->id,
                'job_start_at' => now()->addDays(5)->toDateString(),
                'job_due_at' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_due_at');
    }

    /** มอบหมายให้ผู้ใช้ที่ไม่มีอยู่จริงต้องไม่ผ่าน */
    public function test_assigning_to_a_missing_user_is_rejected(): void
    {
        $this->actingAs($this->itHead)
            ->postJson(route('mytasks.create'), [
                'job_topic' => 'ผู้รับไม่มีจริง',
                'user_id' => 999999,
                'job_start_at' => now()->toDateString(),
                'job_due_at' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    /** เพิ่มผู้ร่วมงานโดยไม่ส่งรายชื่อมาต้องไม่ผ่าน */
    public function test_adding_collaborators_requires_a_non_empty_list(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->itHead)
            ->postJson(route('tasks.collaborators.store', $job->job_id), ['collaborators' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('collaborators');
    }

    /** สถานะการอนุมัติรับได้แค่ accepted / rejected */
    public function test_the_collaborator_decision_only_accepts_known_statuses(): void
    {
        $job = $this->approvedTask($this->itStaff);
        $job->collaborators()->attach($this->accountStaff->id, [
            'status' => 'pending', 'added_by' => $this->itStaff->id,
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tasks.collaborators.approval', [$job->job_id, $this->accountStaff->id]), [
                'status' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    /** endpoint อนุมัติงานใช้ชื่อฟิลด์ approval_status และรับได้แค่ approved/rejected */
    public function test_the_assignment_approval_endpoint_validates_its_field(): void
    {
        $job = $this->crossDepartmentJob();

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tasks.approval', $job->job_id), ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approval_status');

        $this->actingAs($this->admin)
            ->patchJson(route('admin.tasks.approval', $job->job_id), ['approval_status' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approval_status');

        $this->assertSame('pending', $job->fresh()->approval_status);
    }

    // =====================================================================
    // 5. ขอบเขตสิทธิ์
    // =====================================================================

    /** คนที่ไม่ได้เป็นเจ้าของงานแชร์งานของคนอื่นไม่ได้ */
    public function test_a_bystander_cannot_share_someone_elses_task(): void
    {
        $job = $this->approvedTask($this->itHead);

        $this->actingAs($this->accountStaff)
            ->postJson(route('shares.store', $job->job_id), ['scope' => 'organization'])
            ->assertForbidden();

        $this->actingAs($this->itStaff)
            ->postJson(route('shares.store', $job->job_id), ['scope' => 'organization'])
            ->assertForbidden();
    }

    /** ผู้แชร์กดขอเข้าร่วมงานของตัวเองไม่ได้ */
    public function test_the_sharer_cannot_request_to_join_their_own_share(): void
    {
        $job = $this->approvedTask($this->itHead);
        $share = $this->openShare($this->itHead, $job, 'organization');

        $this->actingAs($this->itHead)
            ->postJson(route('shares.requests.store', $share))
            ->assertForbidden();
    }

    /** คนนอกที่ไม่ใช่ผู้แชร์ตัดสินคำขอไม่ได้ */
    public function test_a_third_party_cannot_decide_a_share_request(): void
    {
        $job = $this->approvedTask($this->itStaff);
        $share = $this->openShare($this->itStaff, $job, 'organization');
        $shareRequest = $this->join($share, $this->accountStaff);

        $this->actingAs($this->accountStaff)
            ->patchJson(route('shares.requests.approve', $shareRequest))
            ->assertForbidden();

        $this->assertSame('pending', $shareRequest->fresh()->status);
    }

    /** viewer ถูกกันออกจากทุกเส้นทางของการแชร์งาน */
    public function test_a_viewer_is_locked_out_of_every_share_route(): void
    {
        $viewer = $this->account('viewer.only', 'ผู้เข้าชม', 'viewer', null);
        $job = $this->approvedTask($this->itHead);
        $share = $this->openShare($this->itHead, $job, 'organization');

        $this->actingAs($viewer)->get(route('shares.index'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('shares.store', $job->job_id), ['scope' => 'organization'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('shares.requests.store', $share))->assertForbidden();
    }

    // =====================================================================
    // ตัวช่วย
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

    /**
     * งานที่ใช้ทดสอบการแชร์ — เป็น "งานย่อย" เสมอ
     *
     * WorkOrderPolicy::share() อนุญาตเฉพาะงานที่มี parent_job_id เพราะการแชร์ที่ระดับ
     * งานแม่เท่ากับเปิดงานทุกใบใต้มันให้คนนอก งานแม่จึงถูกสร้างให้เป็นแค่ที่สังกัด
     */
    private function approvedTask(User $owner, string $topic = 'งานทดสอบข้ามแผนก'): WorkOrder
    {
        $parent = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => $topic.' (งานแม่)',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
        ]);

        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'parent_job_id' => $parent->job_id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
        ]);
    }

    private function crossDepartmentJob(): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $this->accountStaff->id,
            'created_by' => $this->itHead->id,
            'assigned_by' => $this->itHead->id,
            'leader_user_id' => $this->itHead->id,
            'department_id' => $this->account->id,
            'job_topic' => 'ตรวจสอบระบบบัญชี',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDays(3),
            'approval_status' => 'pending',
        ]);
    }

    private function openShare(User $sharer, WorkOrder $task, string $scope): WorkOrderShare
    {
        return WorkOrderShare::create([
            'work_order_id' => $task->job_id,
            'shared_by' => $sharer->id,
            'department_id' => $sharer->department_id,
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
