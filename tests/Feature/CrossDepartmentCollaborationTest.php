<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
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

        $this->actingAs($this->itMember)->get(route('mytasks.quickview.task', $hidden))->assertForbidden();
    }

    /**
     * ผู้ร่วมงานข้ามแผนกไม่เห็นปุ่มที่กดแล้วได้ "This action is unauthorized"
     *
     * ช่องเพิ่มงานย่อย ปุ่มลาก เมนูย้าย/แก้ชื่อ/ลบงานย่อย ใช้ manageSubtasks ตัวเดียวกับ server
     * ส่วน "ขอเพิ่มงาน" ถูกปิดที่ WorkOrderListPolicy::requestTask ทั้งหน้าจอและ endpoint
     */
    public function test_cross_department_collaborator_gets_no_subtask_management_or_task_request_controls(): void
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
            ->assertDontSee('data-open-project-task-request', false);

        $this->actingAs($this->itMember)
            ->postJson(route('mytasks.lists.task-requests.store', $project), [
                'job_topic' => 'ขอเพิ่มข้ามแผนก',
                'job_priority' => 2,
                'job_start_at' => now()->addDay()->format('Y-m-d H:i'),
                'job_due_at' => now()->addDays(2)->format('Y-m-d H:i'),
            ])
            ->assertForbidden();

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

    public function test_approval_queue_parameter_renders_only_the_selected_queue(): void
    {
        $this->actingAs($this->itHead)->get(route('admin.approvals.index', ['approval_queue' => 'collaborator']))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'collaborator')
            ->assertSee('id="collaborator-approval-queue"', false)
            ->assertDontSee('id="assignment-approval-queue"', false)
            ->assertDontSee('id="share-approval-queue"', false);

        $this->actingAs($this->itHead)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'all')
            ->assertSee('id="assignment-approval-queue"', false)
            ->assertSee('id="collaborator-approval-queue"', false)
            ->assertSee('id="share-approval-queue"', false)
            ->assertDontSee('id="outgoing-collaboration-queue"', false);

        $this->actingAs($this->itHead)->get(route('admin.approvals.index', ['approval_queue' => 'bogus']))
            ->assertOk()
            ->assertViewHas('approvalQueue', 'all');
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
            ->assertSee('พนักงานที่ไปร่วม')
            ->assertSee('ผู้รับผิดชอบหลัก')
            ->assertSee('กำหนดส่ง / สถานะ')
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
