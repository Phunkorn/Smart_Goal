<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\RoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * พนักงานไปร่วมงานของแผนกอื่น
 *
 * สถานการณ์: แผนก Account เป็นเจ้าของงาน "ทดสอบข้ามแผนก" พนักงาน IT ถูกเชิญเข้าร่วม
 * - หัวหน้า IT (ต้นสังกัด) ดูงานและคอมเมนต์ได้ แต่ปรับเวลา/เปลี่ยนสถานะไม่ได้
 * - พนักงาน IT เห็นเฉพาะงานย่อยที่ตัวเองมีส่วนร่วม ไม่ใช่งานย่อยทุกใบ
 * - บอร์ดบอกว่างานใบนี้ข้ามแผนก และหน้าคำขออนุมัติมีแท็บติดตาม
 */
class CrossDepartmentCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $account;

    private User $itHead;

    private User $itMember;

    private User $accountOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->account = Department::create(['department_name' => 'Account']);
        $this->itHead = $this->user($this->it, ['is_department_head' => true]);
        $this->itMember = $this->user($this->it);
        $this->accountOwner = $this->user($this->account);
    }

    public function test_home_department_head_can_view_and_comment_but_not_change_the_task(): void
    {
        $task = $this->accountTask('ทดสอบข้ามแผนก');
        $task->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);

        $this->assertTrue($this->itHead->can('view', $task));
        $this->assertTrue($this->itHead->can('comment', $task));
        $this->assertTrue($this->itHead->can('viewComments', $task));
        $this->assertFalse($this->itHead->can('work', $task));
        $this->assertFalse($this->itHead->can('update', $task));

        $this->actingAs($this->itHead)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'หัวหน้าต้นสังกัดติดตามงาน'])
            ->assertCreated();

        $this->actingAs($this->itHead)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 5])
            ->assertForbidden();
        $this->actingAs($this->itHead)
            ->patchJson(route('tasks.schedule.update', $task), [
                'job_start_at' => now()->addDay()->format('Y-m-d H:i'),
                'job_due_at' => now()->addDays(3)->format('Y-m-d H:i'),
            ])
            ->assertForbidden();

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_head_of_an_unrelated_department_gains_nothing(): void
    {
        $task = $this->accountTask('ทดสอบข้ามแผนก');
        $task->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);
        $hrHead = $this->user(Department::create(['department_name' => 'HR']), ['is_department_head' => true]);

        $this->assertFalse($hrHead->can('view', $task));
        $this->assertFalse($hrHead->can('comment', $task));
        $this->actingAs($hrHead)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'blocked'])
            ->assertForbidden();
    }

    public function test_pending_collaboration_does_not_open_the_task_to_the_home_head(): void
    {
        $task = $this->accountTask('ยังไม่อนุมัติ');
        $task->collaborators()->attach($this->itMember->id, ['status' => 'pending']);

        $this->assertFalse($this->itHead->can('comment', $task));
    }

    public function test_member_workspace_opened_by_home_head_is_read_only_but_offers_comments(): void
    {
        $task = $this->accountTask('ทดสอบข้ามแผนก');
        $task->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);

        $this->actingAs($this->itHead)
            ->get(route('work-board.member', [$this->it, $this->itMember, 'workspace' => 1, 'view' => 'board']))
            ->assertOk()
            ->assertSee('data-view="board"', false)
            ->assertSee('data-cross-department="1"', false)
            ->assertSee('ร่วมงานข้ามแผนก · Account')
            ->assertSee('<option value="cross_department"', false)
            ->assertSee($this->inJsonIsland(route('tasks.comments.store', $task)), false)
            ->assertDontSee($this->inJsonIsland(route('tasks.details.update', $task->job_id)), false);
    }

    public function test_cross_department_collaborator_sees_only_the_subtasks_they_take_part_in(): void
    {
        $parent = $this->accountTask('ทดสอบข้ามแผนก');
        $parent->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);
        $mine = $this->accountTask('งานย่อยของ IT', ['parent_job_id' => $parent->job_id]);
        $mine->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);
        $hidden = $this->accountTask('งานย่อยภายในบัญชี', ['parent_job_id' => $parent->job_id]);

        $this->actingAs($this->itMember)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('ทดสอบข้ามแผนก')
            ->assertSee('งานย่อยของ IT')
            ->assertDontSee('งานย่อยภายในบัญชี');

        // หัวหน้าต้นสังกัดเห็นเท่ากับพนักงานของตัวเอง ไม่ได้เห็นงานย่อยภายในของแผนกอื่น
        $this->actingAs($this->itHead)
            ->get(route('work-board.member', [$this->it, $this->itMember, 'workspace' => 1, 'view' => 'board']))
            ->assertOk()
            ->assertSee('งานย่อยของ IT')
            ->assertDontSee('งานย่อยภายในบัญชี');

        $this->actingAs($this->itMember)->get(route('tasks.show', $hidden))->assertForbidden();
    }

    /**
     * ผู้ร่วมงานข้ามแผนกไม่เห็นปุ่มที่กดแล้วได้ "This action is unauthorized"
     *
     * ช่องเพิ่มงานย่อย ปุ่มลาก เมนูย้าย/แก้ชื่อ/ลบงานย่อย ใช้ manageSubtasks ตัวเดียวกับ server
     * ส่วน "ขอเพิ่มงาน" เปิดให้ผู้ร่วมงานข้ามแผนกใช้ได้แล้ว (กติกาเปลี่ยนตามที่เจ้าของระบบกำหนด
     * — ดู WorkOrderListPolicy::requestTask()) เพราะงานที่ถูกสร้างยังเป็นของเจ้าของโปรเจกต์
     * เหมือนเดิม ผู้ร่วมงานข้ามแผนกจึงไม่ได้สิทธิ์จัดการงานย่อยของงานอื่นในโปรเจกต์เพิ่มขึ้น
     */
    public function test_cross_department_collaborator_gets_no_subtask_management_controls_but_can_add_tasks(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->accountOwner->id, 'name' => 'ทดสอบข้ามแผนก']);
        $parent = $this->accountTask('งานของบัญชี', ['work_order_list_id' => $project->id]);
        $parent->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);
        $mine = $this->accountTask('งานย่อยของ IT', ['parent_job_id' => $parent->job_id, 'work_order_list_id' => $project->id]);
        $mine->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);

        $this->actingAs($this->itMember)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSeeInOrder(['board-project-group__title', 'ร่วมงานข้ามแผนก · Account', 'data-board-task'], false)
            ->assertDontSee('data-task-detail-create', false)
            ->assertDontSee('data-task-detail-drag', false)
            ->assertDontSee('data-task-detail-move', false)
            ->assertDontSee('data-task-detail-edit', false)
            ->assertDontSee('data-task-detail-delete', false)
            ->assertSee('data-open-project-task-request', false);

        $response = $this->actingAs($this->itMember)
            ->postJson(route('mytasks.lists.task-requests.store', $project), [
                'request_type' => 'task',
                'job_topic' => 'ขอเพิ่มข้ามแผนก',
                'job_priority' => 2,
                'job_start_at' => now()->addDay()->format('Y-m-d H:i'),
                'job_due_at' => now()->addDays(2)->format('Y-m-d H:i'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('work_orders', [
            'job_id' => $response->json('job_id'),
            'job_topic' => 'ขอเพิ่มข้ามแผนก',
            'user_id' => $this->accountOwner->id,
        ]);
        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $response->json('job_id'),
            'user_id' => $this->itMember->id,
            'status' => 'accepted',
        ]);

        // เจ้าของงานยังจัดการงานย่อยได้ครบเหมือนเดิม
        $this->actingAs($this->accountOwner)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('data-task-detail-create', false)
            ->assertSee('data-task-detail-move', false)
            ->assertDontSee('ร่วมงานข้ามแผนก · Account');
    }

    public function test_same_department_collaborator_still_sees_every_subtask(): void
    {
        $colleague = $this->user($this->account);
        $parent = $this->accountTask('งานภายในบัญชี');
        $parent->collaborators()->attach($colleague->id, ['status' => 'accepted']);
        $this->accountTask('งานย่อยที่หนึ่ง', ['parent_job_id' => $parent->job_id]);
        $this->accountTask('งานย่อยที่สอง', ['parent_job_id' => $parent->job_id]);

        $this->actingAs($colleague)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('งานย่อยที่หนึ่ง')
            ->assertSee('งานย่อยที่สอง')
            ->assertSee('data-cross-department="0"', false);
    }

    public function test_assignment_from_another_department_is_marked_with_its_origin(): void
    {
        $task = WorkOrder::create($this->taskAttributes('งานที่บัญชีมอบให้ IT', [
            'user_id' => $this->itMember->id,
            'department_id' => $this->it->id,
        ]));

        $this->actingAs($this->itMember)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($task->job_topic)
            ->assertSee('มอบหมายข้ามแผนกจาก Account');
    }

    /**
     * หน้านี้ของหัวหน้าแผนกคือหน้าติดตาม ไม่ใช่กล่องขาเข้าของคำขอ
     *
     * เมื่อไม่มีอะไรให้ตัดสิน (สภาพปกติหลังเลิกอนุมัติผู้เข้าร่วมโปรเจกต์) ต้องเปิดมาเจอ
     * แท็บติดตามทันที ไม่มีการ์ดคิวว่างและไม่มีแถบแท็บให้สับสนว่ายังต้องไปกดอะไร
     */
    public function test_a_head_with_nothing_to_decide_lands_straight_on_the_tracking_view(): void
    {
        $this->actingAs($this->itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'outgoing')
            ->assertSee('id="outgoing-collaboration-queue"', false)
            ->assertSee('งานข้ามแผนกของทีม')
            // การ์ดคิวที่ว่างต้องไม่ถูกวาดเลย ไม่ใช่วาดสถานะว่างทิ้งไว้
            ->assertDontSee('id="assignment-approval-queue"', false)
            ->assertDontSee('id="share-approval-queue"', false)
            ->assertDontSee('id="collaborator-approval-queue"', false)
            // ไม่มีการ์ดสรุป ไม่มีแถบแท็บ และไม่มีแถบของค้าง
            ->assertDontSee('admin-approvals-summary', false)
            ->assertDontSee('admin-approvals-tabs', false)
            ->assertDontSee('admin-approvals-pending', false)
            ->assertDontSee('data-approval-form', false);

        // ขอคิวที่ว่างมาตรง ๆ ก็ต้องไม่วาดการ์ดว่างให้หัวหน้าแผนก
        $this->actingAs($this->itHead)->get(route('admin.approvals.index', ['approval_queue' => 'share']))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'share')
            ->assertDontSee('id="share-approval-queue"', false);

        // ค่าที่ไม่รู้จักและคิวที่ถูกยกเลิก ตกกลับเป็นค่าตั้งต้นของ role นั้น
        foreach (['collaborator', 'bogus'] as $retired) {
            $this->actingAs($this->itHead)->get(route('admin.approvals.index', ['approval_queue' => $retired]))
                ->assertOk()
                ->assertViewHas('approvalQueue', 'outgoing')
                ->assertDontSee('id="collaborator-approval-queue"', false);
        }
    }

    /** มีของค้างจริงต้องพาไปที่ของค้างก่อน พร้อมแถบบอกจำนวนที่กดเข้าไปได้ */
    public function test_a_head_with_a_pending_request_is_taken_to_it_and_warned(): void
    {
        $outsider = $this->user($this->account);
        WorkOrder::create($this->taskAttributes('งานข้ามแผนกรออนุมัติ', [
            'user_id' => $this->itMember->id,
            'created_by' => $outsider->id,
            'assigned_by' => $outsider->id,
            'leader_user_id' => $outsider->id,
            'department_id' => $this->it->id,
            'approval_status' => 'pending',
        ]));

        $this->actingAs($this->itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'all')
            ->assertSee('id="assignment-approval-queue"', false)
            ->assertSee('งานข้ามแผนกรออนุมัติ')
            // แถบของค้างพาไปที่คิวนั้นตรง ๆ และแถบแท็บกลับมาเพราะมีมากกว่าหนึ่งแท็บ
            ->assertSee('admin-approvals-pending', false)
            ->assertSee(route('admin.approvals.index', ['approval_queue' => 'assignment']), false)
            ->assertSee('admin-approvals-tabs', false)
            // คิวแชร์ยังว่าง จึงยังไม่ถูกวาด
            ->assertDontSee('id="share-approval-queue"', false);
    }

    /** ฝั่ง admin ต้องไม่เปลี่ยนพฤติกรรม — ยังเป็นกล่องขาเข้าที่เห็นคิวว่างได้ */
    public function test_admin_still_sees_the_full_approval_inbox(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'department_id' => null,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'all')
            ->assertSee('คำขออนุมัติ')
            ->assertSee('admin-approvals-summary', false)
            ->assertSee('admin-approvals-tabs', false)
            ->assertSee('id="assignment-approval-queue"', false)
            ->assertSee('id="share-approval-queue"', false)
            ->assertDontSee('id="outgoing-collaboration-queue"', false);

        $this->actingAs($admin)->get(route('admin.approvals.index', ['approval_queue' => 'share']))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'share')
            ->assertSee('id="share-approval-queue"', false)
            ->assertDontSee('id="assignment-approval-queue"', false)
            ->assertDontSee('id="outgoing-collaboration-queue"', false);
    }

    public function test_outgoing_tab_lists_home_members_working_for_other_departments(): void
    {
        $task = $this->accountTask('ทดสอบข้ามแผนก');
        $task->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);
        $internal = WorkOrder::create($this->taskAttributes('งานภายใน IT', [
            'user_id' => $this->itMember->id,
            'created_by' => $this->itMember->id,
            'leader_user_id' => $this->itMember->id,
            'department_id' => $this->it->id,
        ]));
        $internal->collaborators()->attach($this->user($this->it)->id, ['status' => 'accepted']);

        $this->actingAs($this->itHead)->get(route('admin.approvals.index', ['approval_queue' => 'outgoing']))
            ->assertOk()
            ->assertSee('id="outgoing-collaboration-queue"', false)
            ->assertSee('แผนกAccount')
            ->assertSee('ทดสอบข้ามแผนก')
            ->assertSee($this->itMember->name)
            ->assertSee('พนักงาน')
            ->assertSee('หัวหน้าโปรเจกต์')
            ->assertSee('กำลังเข้าร่วม')
            ->assertSee('กำหนดส่ง')
            // แถบสรุปและตัวกรองของหน้าติดตาม — ตัวกรองเป็นปุ่มฝั่ง client ไม่ใช่ฟอร์ม
            ->assertSee('พนักงานที่ไปช่วย')
            ->assertSee('data-cross-dept-filter="late"', false)
            ->assertSee('data-cross-dept-search', false)
            // หน้านี้เป็นการติดตาม ไม่ใช่คิวอนุมัติ จึงต้องไม่มีฟอร์มตัดสินใจ
            ->assertDontSee('data-approval-form', false)
            ->assertSee($task->job_due_at->timezone('Asia/Bangkok')->format('d/m/Y'))
            ->assertDontSee('งานภายใน IT')
            ->assertSee(route('work-board.member', [
                $this->it->id,
                $this->itMember,
                'workspace' => 1,
                'view' => 'board',
                'status' => 'cross_department',
                'open_task' => $task->job_id,
            ]));

        // หัวหน้าแผนกเจ้าของงานไม่ได้เห็นลูกทีมของ IT ในแท็บนี้
        $accountHead = $this->user($this->account, ['is_department_head' => true]);
        $this->actingAs($accountHead)->get(route('admin.approvals.index', ['approval_queue' => 'outgoing']))
            ->assertOk()
            ->assertDontSee('ทดสอบข้ามแผนก');
    }

    /**
     * บทบาทในการ์ดของพนักงาน — หัวหน้าแผนกต้องไม่ถูกเรียกว่าพนักงาน
     *
     * "หัวหน้าแผนก" เป็นธงแยก (users.is_department_head) ไม่ใช่ค่าใน users.role
     * การ์ดนี้เคยเขียนข้อความบทบาทเองว่า "พนักงานแผนก…" จึงมองข้ามธงนั้น และหัวหน้าแผนก
     * ที่ไปร่วมโปรเจกต์ของแผนกอื่นก็ขึ้นเป็นพนักงานทั้งที่เป็นหัวหน้า
     */
    public function test_outgoing_tab_names_a_participating_department_head_as_head_not_member(): void
    {
        $task = $this->accountTask('ทดสอบข้ามแผนก');
        $task->collaborators()->attach($this->itHead->id, ['status' => 'accepted']);
        $task->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);

        $response = $this->actingAs($this->itHead)
            ->get(route('admin.approvals.index', ['approval_queue' => 'outgoing']))
            ->assertOk()
            // หัวหน้าแผนกที่ไปร่วมงานต่างแผนกยังต้องอยู่ในรายการเหมือนเดิม
            ->assertSee($this->itHead->name)
            ->assertSee($this->itMember->name)
            ->assertSee('หัวหน้าแผนก · IT')
            ->assertSee('พนักงาน · IT')
            // ข้อความบทบาทที่ฝังไว้ในการ์ดของเดิมหายไปแล้ว — ไม่ใช้คำว่า "พนักงานแผนก" ใน <small> อีก
            // (หัวเรื่องของหน้ายังมีประโยค "ติดตามว่าพนักงานแผนกIT…" ซึ่งเป็นคำบรรยาย ไม่ใช่ป้ายบทบาท)
            ->assertDontSee('<small>พนักงานแผนก', false);

        $html = $response->getContent();
        $this->assertStringContainsString(RoleLabel::withDepartment($this->itHead->fresh()), $html);
        $this->assertStringContainsString(RoleLabel::withDepartment($this->itMember->fresh()), $html);
    }

    /**
     * หัวหน้าโปรเจกต์ในป้ายของแถวงานต้องมีรูปจริง ไม่ใช่ไอคอนคนเดียวกันทุกแถว
     * ทั้งสองกรณีมาจาก components/user-avatar-content ที่เดียว (รูปถ้ามี ไม่งั้นตัวย่อชื่อ)
     */
    public function test_outgoing_tab_shows_the_project_leader_avatar(): void
    {
        $this->accountOwner->update(['name' => 'Wanida Account', 'profile_image' => 'profile-images/leader.jpg']);
        $withPhoto = $this->accountTask('งานที่หัวหน้ามีรูป');
        $withPhoto->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);

        $plainLeader = $this->user($this->account, ['name' => 'Kittipong Account', 'profile_image' => null]);
        $withoutPhoto = $this->accountTask('งานที่หัวหน้าไม่มีรูป', [
            'user_id' => $plainLeader->id,
            'created_by' => $plainLeader->id,
            'leader_user_id' => $plainLeader->id,
        ]);
        $withoutPhoto->collaborators()->attach($this->itMember->id, ['status' => 'accepted']);

        $response = $this->actingAs($this->itHead)
            ->get(route('admin.approvals.index', ['approval_queue' => 'outgoing']))
            ->assertOk()
            ->assertSee('cross-dept-track__avatar--xs', false)
            ->assertSee('หัวหน้าโปรเจกต์ Wanida Account')
            ->assertSee('หัวหน้าโปรเจกต์ Kittipong Account')
            // รูปจริงเสิร์ฟผ่าน MediaController ไม่ใช่ URL ของ storage โดยตรง
            ->assertSee(route('media.profile', $this->accountOwner), false)
            ->assertDontSee('profile-images/leader.jpg');

        // หัวหน้าที่ไม่มีรูปได้ตัวย่อชื่อในวงกลมเดียวกัน ไม่ได้หายไปและไม่ได้กลายเป็นรูปว่าง
        $this->assertStringContainsString(
            '<span class="cross-dept-track__avatar cross-dept-track__avatar--xs" aria-hidden="true">',
            $response->getContent()
        );
        $this->assertMatchesRegularExpression(
            '/cross-dept-track__avatar--xs" aria-hidden="true">\s*K\s*<\/span>/u',
            $response->getContent()
        );
    }

    /** URL ในเกาะ JSON ถูก json_encode มาแล้ว เครื่องหมาย / จึงกลายเป็น \/ */
    private function inJsonIsland(string $url): string
    {
        return str_replace('/', '\/', $url);
    }

    private function user(Department $department, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function accountTask(string $topic, array $attributes = []): WorkOrder
    {
        return WorkOrder::create($this->taskAttributes($topic, $attributes));
    }

    private function taskAttributes(string $topic, array $attributes = []): array
    {
        return array_merge([
            'user_id' => $this->accountOwner->id,
            'created_by' => $this->accountOwner->id,
            'leader_user_id' => $this->accountOwner->id,
            'department_id' => $this->account->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDays(2),
        ], $attributes);
    }
}
