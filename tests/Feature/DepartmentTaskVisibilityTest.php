<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\MemberWorkloadQuery;
use App\Support\CrossDepartmentWork;
use App\Support\DepartmentTaskVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * สิทธิ์ "ดูงานของแผนก" ที่ admin มอบให้พนักงานเป็นรายคน
 *
 * กติกา: Can See Task != Participates In Task — สิทธิ์นี้เติมแค่การมองเห็น
 * ไม่เปลี่ยน role ไม่เพิ่มสิทธิ์เขียนใด ๆ และไม่ทำให้ผู้ใช้ถูกนับเป็นผู้มีส่วนร่วม
 * ของงานใบใด รายงาน ภาระงาน และรายชื่อผู้รับแจ้งเตือนจึงต้องไม่เปลี่ยน
 */
class DepartmentTaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $marketing;

    private Department $hr;

    /** เจ้าของงานฝั่ง Marketing */
    private User $marketingOwner;

    /** เจ้าของงานฝั่ง IT */
    private User $itOwner;

    private WorkOrder $marketingTask;

    private WorkOrder $itTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->marketing = Department::create(['department_name' => 'Marketing']);
        $this->hr = Department::create(['department_name' => 'HR']);

        $this->marketingOwner = $this->employee($this->marketing);
        $this->itOwner = $this->employee($this->it);

        $this->marketingTask = $this->task($this->marketing, $this->marketingOwner, 'แคมเปญสงกรานต์');
        $this->itTask = $this->task($this->it, $this->itOwner, 'อัปเกรดเซิร์ฟเวอร์');
    }

    /** Case 1 — ไม่มี grant แล้ว behavior ต้องเหมือนเดิมทุกอย่าง */
    public function test_employee_without_a_grant_keeps_the_original_visibility(): void
    {
        $plain = $this->employee($this->it);

        $this->assertSame(DepartmentTaskVisibility::SCOPE_INVOLVED, $plain->task_visibility_scope);
        $this->assertSame([], DepartmentTaskVisibility::departmentIdsFor($plain));

        $this->assertFalse($plain->can('view', $this->itTask));
        $this->assertFalse($plain->can('view', $this->marketingTask));

        $this->actingAs($plain)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertDontSee($this->itTask->job_topic)
            ->assertDontSee($this->marketingTask->job_topic);
    }

    /** Case 2 — เห็นงานทั้งหมดของแผนกที่ได้รับสิทธิ์ แม้ไม่ได้ร่วมงานใบนั้น */
    public function test_granted_employee_sees_every_task_of_a_granted_department(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $second = $this->task($this->marketing, $this->marketingOwner, 'ถ่ายภาพสินค้าใหม่');

        $this->assertTrue($grantee->can('view', $this->marketingTask));
        $this->assertTrue($grantee->can('view', $second));

        $this->actingAs($grantee)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($this->marketingTask->job_topic)
            ->assertSee($second->job_topic)
            // ใครรับผิดชอบงานอะไรต้องอ่านออกจากหน้าเดียวกัน
            ->assertSee($this->marketingOwner->name);
    }

    /** Case 2b — เห็นแล้วต้องไม่ได้สิทธิ์เขียนเพิ่มแม้ข้อเดียว */
    public function test_grant_adds_no_write_ability_on_a_granted_task(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->assertTrue($grantee->can('view', $this->marketingTask));

        $forbidden = [
            'work', 'update', 'participate', 'comment', 'manageTeam', 'manageSubtasks',
            'share', 'submitForReview', 'review', 'reopen', 'overrideStatus', 'delete',
            'deleteOwn', 'approve',
        ];

        foreach ($forbidden as $ability) {
            $this->assertFalse(
                $grantee->can($ability, $this->marketingTask),
                'สิทธิ์ดูงานของแผนกต้องไม่เติม ability: '.$ability
            );
        }

        // การ์ด Kanban ขอ participate ผู้รับสิทธิ์จึงต้องไม่ได้การ์ด
        $this->actingAs($grantee)->get(route('mytasks.kanban-card', $this->marketingTask))->assertNotFound();
    }

    /** Case 3 — สิทธิ์จำกัดอยู่แค่แผนกที่ให้ไว้ */
    public function test_grant_is_limited_to_the_granted_departments(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->assertTrue($grantee->can('view', $this->marketingTask));
        $this->assertFalse($grantee->can('view', $this->itTask));

        $this->actingAs($grantee)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($this->marketingTask->job_topic)
            ->assertDontSee($this->itTask->job_topic);
    }

    /** Case 4 — กำหนดเองได้หลายแผนก */
    public function test_custom_grant_covers_several_departments(): void
    {
        $hrTask = $this->task($this->hr, $this->employee($this->hr), 'ประเมินผลกลางปี');
        $grantee = $this->grant(
            $this->employee($this->hr),
            DepartmentTaskVisibility::SCOPE_CUSTOM,
            [$this->hr, $this->marketing]
        );

        $this->assertEqualsCanonicalizing(
            [$this->hr->id, $this->marketing->id],
            DepartmentTaskVisibility::departmentIdsFor($grantee)
        );

        $this->assertTrue($grantee->can('view', $hrTask));
        $this->assertTrue($grantee->can('view', $this->marketingTask));
        $this->assertFalse($grantee->can('view', $this->itTask));

        $this->actingAs($grantee)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($hrTask->job_topic)
            ->assertSee($this->marketingTask->job_topic)
            ->assertDontSee($this->itTask->job_topic);
    }

    /** Case 5 — เป็นผู้ร่วมงานแล้วได้สิทธิ์ของผู้ร่วมงานตามระบบเดิม ไม่ถูก grant กดทับ */
    public function test_collaborator_permissions_win_over_the_grant(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $other = $this->task($this->marketing, $this->marketingOwner, 'งานที่ไม่ได้ร่วม');

        $this->marketingTask->collaborators()->attach($grantee->id, ['status' => 'accepted']);
        $grantee = $grantee->fresh();

        // งานที่ร่วม — สิทธิ์ผู้ร่วมงานเดิมครบ
        $this->assertTrue($grantee->can('view', $this->marketingTask));
        $this->assertTrue($grantee->can('work', $this->marketingTask));
        $this->assertTrue($grantee->can('comment', $this->marketingTask));

        // งานอื่นในแผนกเดียวกัน — ยังอ่านอย่างเดียวตามเดิม
        $this->assertTrue($grantee->can('view', $other));
        $this->assertFalse($grantee->can('work', $other));
    }

    /** Case 6 — หัวหน้าแผนกไม่เปลี่ยนพฤติกรรม */
    public function test_department_head_behaviour_is_unchanged(): void
    {
        $head = $this->employee($this->marketing, true);

        $this->assertSame(DepartmentTaskVisibility::SCOPE_INVOLVED, $head->task_visibility_scope);
        $this->assertSame([], DepartmentTaskVisibility::departmentIdsFor($head), 'หัวหน้าต้องไม่ได้ grant โดยปริยาย');

        // สิทธิ์เดิมของหัวหน้ายังมาจาก overseesDepartment ไม่ใช่สิทธิ์ใหม่
        $this->assertTrue($head->can('view', $this->marketingTask));
        $this->assertTrue($head->can('comment', $this->marketingTask));
        $this->assertFalse($head->can('view', $this->itTask));

        $this->actingAs($head)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($this->marketingTask->job_topic)
            ->assertDontSee($this->itTask->job_topic);
    }

    /** Case 6b — หัวหน้าที่ได้ grant เพิ่มเห็นแผนกอื่นได้ แต่ไม่ได้อำนาจหัวหน้าในแผนกนั้น */
    public function test_a_head_with_an_extra_grant_only_gains_read_access_there(): void
    {
        $head = $this->grant(
            $this->employee($this->hr, true),
            DepartmentTaskVisibility::SCOPE_CUSTOM,
            [$this->marketing]
        );

        $this->assertTrue($head->can('view', $this->marketingTask));
        $this->assertFalse($head->can('comment', $this->marketingTask));
        $this->assertFalse($head->can('work', $this->marketingTask));
        $this->assertFalse($head->can('view', $this->itTask));
    }

    /** Case 7 — Admin ไม่เปลี่ยนพฤติกรรม และ grant ไม่มีผลกับ admin/viewer */
    public function test_admin_behaviour_is_unchanged(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'department_id' => null,
            'is_active' => true,
            'must_change_password' => false,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_CUSTOM,
        ]);
        $admin->visibleDepartments()->sync([$this->marketing->id]);

        // admin เห็นทุกงานด้วยสิทธิ์เดิม และไม่ใช้สิทธิ์ชุดนี้เลย
        $this->assertSame([], DepartmentTaskVisibility::departmentIdsFor($admin->fresh()));
        $this->assertTrue($admin->can('view', $this->itTask));
        $this->assertTrue($admin->can('view', $this->marketingTask));
    }

    /** Case 8 — เปิดด้วย URL ตรงไปงานในแผนกที่ได้รับสิทธิ์ */
    public function test_direct_url_to_a_granted_department_task_opens(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->actingAs($grantee)->get(route('tasks.show', $this->marketingTask->job_id))
            ->assertRedirect(route('mytasks.index', ['open_task' => $this->marketingTask->job_id]));
    }

    /** Case 9 — URL ตรงไปแผนกที่ไม่ได้รับสิทธิ์ยังถูกปฏิเสธตามระบบเดิม */
    public function test_direct_url_outside_the_grant_is_still_refused(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->actingAs($grantee)->get(route('tasks.show', $this->itTask->job_id))->assertForbidden();
    }

    /** Case 10 — รายงาน ภาระงาน และการแจ้งเตือนต้องไม่เปลี่ยนเพราะการมองเห็น */
    public function test_grant_never_changes_reports_or_workload(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        // ภาระงานของ Member Workspace และบอร์ดแผนก
        $this->assertSame(
            [],
            app(MemberWorkloadQuery::class)->forMember($grantee)->pluck('job_id')->all(),
            'งานที่แค่มองเห็นต้องไม่กลายเป็นภาระงานของผู้ใช้'
        );

        // นิยาม attribution ของรายงานทุกหน้า
        $this->assertSame([], WorkOrder::query()->contributedBy($grantee->id)->pluck('job_id')->all());
        $this->assertSame([], WorkOrder::query()->assignedToAny([$grantee->id])->pluck('job_id')->all());
        $this->assertSame([], WorkOrder::query()->joinedByAny([$grantee->id])->pluck('job_id')->all());

        // และไม่กลายเป็นผู้รับแจ้งเตือนของงานที่เขาแค่มองเห็น
        $this->assertSame(0, SystemNotification::query()->where('user_id', $grantee->id)->count());
    }

    /**
     * viewAsContext ต้องเป็นเท็จสำหรับงานที่เห็นเพราะสิทธิ์แผนก
     *
     * ถ้าเป็นจริง CrossDepartmentWork::restrictChildren() จะตัดงานย่อยออกทั้งหมด
     * และ MyTaskController::index() จะหักงานใบนั้นออกจากตัวนับตัวกรองเงียบ ๆ
     */
    public function test_view_as_context_stays_false_for_a_granted_task(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->assertTrue($grantee->can('view', $this->marketingTask));
        $this->assertFalse($grantee->can('viewAsContext', $this->marketingTask));
    }

    /** คิวรีรายการกับ policy ต้องให้ผลตรงกันทุกใบ ทั้งสองทาง */
    public function test_policy_and_workspace_query_agree_on_every_task(): void
    {
        $grantee = $this->grant(
            $this->employee($this->hr),
            DepartmentTaskVisibility::SCOPE_CUSTOM,
            [$this->marketing]
        );
        $this->task($this->marketing, $this->marketingOwner, 'งานที่สองของ Marketing');
        $this->task($this->hr, $this->employee($this->hr), 'งานของ HR ที่ไม่ได้ให้สิทธิ์');

        $visibleIds = WorkOrder::query()->visibleInProjectsFor($grantee)->pluck('job_id')
            ->map(fn ($id) => (int) $id)->all();

        foreach (WorkOrder::all() as $task) {
            $this->assertSame(
                $grantee->can('view', $task),
                in_array((int) $task->job_id, $visibleIds, true),
                'policy กับคิวรีตอบไม่ตรงกันที่งาน '.$task->job_id
            );
        }
    }

    /** งานย่อยในแผนกที่ได้รับสิทธิ์ต้องไม่ถูกตัดทิ้ง (CrossDepartmentWork::isOutsider) */
    public function test_granted_employee_sees_every_subtask_of_a_granted_task(): void
    {
        $grantee = $this->grant(
            $this->employee($this->it),
            DepartmentTaskVisibility::SCOPE_CUSTOM,
            [$this->marketing]
        );

        $project = WorkOrderList::create(['user_id' => $this->marketingOwner->id, 'name' => 'แผนสื่อสารองค์กร']);
        $parent = $this->task($this->marketing, $this->marketingOwner, 'งานแม่ของ Marketing', [
            'work_order_list_id' => $project->id,
        ]);
        $first = $this->task($this->marketing, $this->marketingOwner, 'งานย่อยใบที่หนึ่ง', [
            'work_order_list_id' => $project->id,
            'parent_job_id' => $parent->job_id,
        ]);
        $second = $this->task($this->marketing, $this->marketingOwner, 'งานย่อยใบที่สอง', [
            'work_order_list_id' => $project->id,
            'parent_job_id' => $parent->job_id,
        ]);

        $loaded = WorkOrder::with('children')->findOrFail($parent->job_id);
        CrossDepartmentWork::restrictChildren(collect([$loaded]), $grantee);

        $this->assertFalse(
            CrossDepartmentWork::childrenWereRestricted($loaded),
            'ผู้ได้รับสิทธิ์ดูงานของแผนกไม่ใช่คนนอก งานย่อยจึงต้องไม่ถูกตัด'
        );
        $this->assertEqualsCanonicalizing(
            [$first->job_id, $second->job_id],
            $loaded->children->pluck('job_id')->all()
        );

        $this->actingAs($grantee)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($parent->job_topic)
            ->assertSee($first->job_topic)
            ->assertSee($second->job_topic);
    }

    /** ผู้ถูกเชิญข้ามแผนกแบบเดิมต้องยังถูกตัดงานย่อยเหมือนเดิม — การผ่อนต้องไม่หลวมเกิน */
    public function test_an_invited_outsider_without_a_grant_is_still_restricted(): void
    {
        $outsider = $this->employee($this->it);

        $project = WorkOrderList::create(['user_id' => $this->marketingOwner->id, 'name' => 'แผนสื่อสารองค์กร']);
        $parent = $this->task($this->marketing, $this->marketingOwner, 'งานแม่ของ Marketing', [
            'work_order_list_id' => $project->id,
        ]);
        $mine = $this->task($this->marketing, $this->marketingOwner, 'งานย่อยที่ถูกเชิญ', [
            'work_order_list_id' => $project->id,
            'parent_job_id' => $parent->job_id,
        ]);
        $sibling = $this->task($this->marketing, $this->marketingOwner, 'งานย่อยของคนอื่น', [
            'work_order_list_id' => $project->id,
            'parent_job_id' => $parent->job_id,
        ]);
        $mine->collaborators()->attach($outsider->id, ['status' => 'accepted']);

        $loaded = WorkOrder::with('children')->findOrFail($parent->job_id);
        CrossDepartmentWork::restrictChildren(collect([$loaded]), $outsider->fresh());

        $this->assertTrue(CrossDepartmentWork::childrenWereRestricted($loaded));
        $this->assertSame([$mine->job_id], $loaded->children->pluck('job_id')->all());
        $this->assertNotContains($sibling->job_id, $loaded->children->pluck('job_id')->all());
    }

    /** อ่านบทสนทนาได้ แต่เขียนไม่ได้ */
    public function test_grantee_reads_comments_but_cannot_post_one(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->assertTrue($grantee->can('viewComments', $this->marketingTask));
        $this->assertFalse($grantee->can('comment', $this->marketingTask));

        $this->actingAs($grantee)
            ->post(route('tasks.comments.store', $this->marketingTask), ['body' => 'ขอแทรกความเห็น'])
            ->assertForbidden();
    }

    /** งานที่ยังไม่อนุมัติหรือถูกปฏิเสธต้องไม่โผล่ผ่านสิทธิ์นี้ */
    public function test_grant_never_reveals_a_task_that_is_not_approved(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $pending = $this->task($this->marketing, $this->marketingOwner, 'งานที่รออนุมัติ', [
            'approval_status' => 'pending',
        ]);
        $rejected = $this->task($this->marketing, $this->marketingOwner, 'งานที่ถูกปฏิเสธ', [
            'approval_status' => 'rejected',
        ]);

        $this->assertFalse($grantee->can('view', $pending));
        $this->assertFalse($grantee->can('view', $rejected));

        $visibleIds = WorkOrder::query()->visibleInProjectsFor($grantee)->pluck('job_id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains((int) $pending->job_id, $visibleIds);
        $this->assertNotContains((int) $rejected->job_id, $visibleIds);
    }

    /** โหมด "แผนกของตนเอง" ต้องย้ายตามผู้ใช้เมื่อ admin ย้ายแผนก */
    public function test_own_department_grant_follows_the_employee_to_a_new_department(): void
    {
        $grantee = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);

        $this->assertTrue($grantee->can('view', $this->marketingTask));
        $this->assertFalse($grantee->can('view', $this->itTask));

        $this->actingAs($this->admin())->patch(route('employees.update', $grantee), [
            'name' => $grantee->name,
            'username' => $grantee->username,
            'email' => null,
            'phone' => null,
            'role' => 'user',
            'is_active' => true,
            'department_id' => $this->it->id,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT,
        ])->assertRedirect(route('employees.index'))->assertSessionDoesntHaveErrors();

        $grantee = $grantee->fresh();

        $this->assertSame([$this->it->id], DepartmentTaskVisibility::departmentIdsFor($grantee));
        $this->assertTrue($grantee->can('view', $this->itTask));
        $this->assertFalse($grantee->can('view', $this->marketingTask));
        $this->assertDatabaseCount('user_task_visibility_departments', 0);
    }

    /** บัญชีระบบและบัญชีที่ปิดใช้งานไม่มีสิทธิ์ชุดนี้ */
    public function test_grant_is_ignored_for_system_accounts_and_inactive_employees(): void
    {
        $inactive = $this->employee($this->marketing);
        $inactive->forceFill([
            'is_active' => false,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT,
        ])->save();

        $this->assertSame([], DepartmentTaskVisibility::departmentIdsFor($inactive->fresh()));

        $viewer = User::factory()->create([
            'role' => 'viewer',
            'department_id' => null,
            'is_active' => true,
            'must_change_password' => false,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT,
        ]);
        $this->assertSame([], DepartmentTaskVisibility::departmentIdsFor($viewer));

        // และฟอร์มบัญชีระบบต้องบีบค่ากลับเป็น involved แม้มีการยัด payload เข้ามา
        $this->actingAs($this->admin())->post(route('admin.accounts.store'), [
            'name' => 'System Viewer',
            'username' => 'system.viewer',
            'email' => null,
            'phone' => null,
            'password' => 'SecurePassword!123',
            'role' => 'viewer',
            'is_active' => true,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_CUSTOM,
            'visible_department_ids' => [$this->marketing->id],
        ])->assertRedirect(route('admin.accounts.index'))->assertSessionDoesntHaveErrors();

        $created = User::where('username', 'system.viewer')->firstOrFail();
        $this->assertSame(DepartmentTaskVisibility::SCOPE_INVOLVED, $created->task_visibility_scope);
        $this->assertDatabaseCount('user_task_visibility_departments', 0);
    }

    /** Admin ตั้งค่าและถอนสิทธิ์จากฟอร์มพนักงานได้ และมีร่องรอยในบันทึกตรวจสอบ */
    public function test_admin_can_grant_and_revoke_from_the_employee_form(): void
    {
        $admin = $this->admin();
        $employee = $this->employee($this->hr);

        $this->actingAs($admin)->patch(route('employees.update', $employee), [
            'name' => $employee->name,
            'username' => $employee->username,
            'email' => null,
            'phone' => null,
            'role' => 'user',
            'is_active' => true,
            'department_id' => $this->hr->id,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_CUSTOM,
            'visible_department_ids' => [$this->hr->id, $this->marketing->id],
        ])->assertRedirect(route('employees.index'))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('user_task_visibility_departments', [
            'user_id' => $employee->id,
            'department_id' => $this->marketing->id,
        ]);
        $this->assertDatabaseCount('user_task_visibility_departments', 2);

        // บันทึกตรวจสอบต้องเห็นทั้งโหมดและรายชื่อแผนก
        $log = ActivityLog::query()->latest('id')->firstOrFail();
        $this->assertArrayHasKey('visible_department_ids', $log->changes['after'] ?? []);
        $this->assertArrayHasKey('task_visibility_scope', $log->changes['after'] ?? []);
        $this->assertEqualsCanonicalizing(
            [$this->hr->id, $this->marketing->id],
            $log->changes['after']['visible_department_ids']
        );

        // สลับไปโหมดอื่นต้องล้างรายชื่อทิ้ง ไม่ใช่เก็บค้างไว้
        $this->actingAs($admin)->patch(route('employees.update', $employee), [
            'name' => $employee->name,
            'username' => $employee->username,
            'email' => null,
            'phone' => null,
            'role' => 'user',
            'is_active' => true,
            'department_id' => $this->hr->id,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_INVOLVED,
        ])->assertRedirect(route('employees.index'))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('user_task_visibility_departments', 0);
        $this->assertSame(DepartmentTaskVisibility::SCOPE_INVOLVED, $employee->fresh()->task_visibility_scope);
    }

    /** "กำหนดเอง" ที่ไม่เลือกแผนกเลยต้องถูกปฏิเสธ ไม่ใช่บันทึกเป็นสิทธิ์ที่ไม่ให้อะไร */
    public function test_custom_mode_requires_at_least_one_department(): void
    {
        $employee = $this->employee($this->hr);

        $this->actingAs($this->admin())->patch(route('employees.update', $employee), [
            'name' => $employee->name,
            'username' => $employee->username,
            'email' => null,
            'phone' => null,
            'role' => 'user',
            'is_active' => true,
            'department_id' => $this->hr->id,
            'task_visibility_scope' => DepartmentTaskVisibility::SCOPE_CUSTOM,
        ])->assertSessionHasErrors('visible_department_ids');

        $this->assertSame(DepartmentTaskVisibility::SCOPE_INVOLVED, $employee->fresh()->task_visibility_scope);
    }

    /** การ์ดพนักงานต้องบอกขอบเขตการมองเห็นงานของทุกคน และโมดัลต้องมีส่วนตั้งค่า */
    public function test_employee_card_and_modal_expose_the_visibility_scope(): void
    {
        $plain = $this->employee($this->it);
        $own = $this->grant($this->employee($this->marketing), DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $custom = $this->grant(
            $this->employee($this->hr),
            DepartmentTaskVisibility::SCOPE_CUSTOM,
            [$this->marketing, $this->hr]
        );

        $this->assertSame('เฉพาะงานที่เกี่ยวข้อง', DepartmentTaskVisibility::summary($plain));
        $this->assertSame('ทุกคนใน Marketing', DepartmentTaskVisibility::summary($own->fresh()));
        $this->assertSame('Marketing, HR', DepartmentTaskVisibility::summary($custom->fresh()));

        $this->actingAs($this->admin())->get(route('employees.index'))
            ->assertOk()
            ->assertSee('<table class="employee-table">', false)
            ->assertSee('ทุกคนใน Marketing')
            ->assertSee('Marketing, HR')
            ->assertSee('ขอบเขตการมองเห็นงาน')
            ->assertSee('name="task_visibility_scope"', false)
            ->assertSee('name="visible_department_ids[]"', false);
    }

    /** หน้าบัญชีระบบต้องไม่มีส่วนนี้เลย เพราะ admin กับผู้เข้าชมไม่ใช้สิทธิ์ชุดนี้ */
    public function test_system_account_modal_has_no_visibility_section(): void
    {
        $this->actingAs($this->admin())->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertDontSee('ขอบเขตการมองเห็นงาน')
            ->assertDontSee('name="task_visibility_scope"', false);
    }

    /** แผนกที่เลือกไว้ถูกลบ ต้องไม่เงียบเหมือนไม่เคยถูกมอบสิทธิ์ */
    public function test_custom_grant_states_plainly_when_no_department_is_left(): void
    {
        $extra = Department::create(['department_name' => 'ชั่วคราว']);
        $grantee = $this->grant($this->employee($this->hr), DepartmentTaskVisibility::SCOPE_CUSTOM, [$extra]);

        $extra->delete();
        $grantee = $grantee->fresh();

        $this->assertSame([], DepartmentTaskVisibility::departmentIdsFor($grantee));
        $this->assertSame('กำหนดเอง (ยังไม่ได้เลือกแผนก)', DepartmentTaskVisibility::summary($grantee));
        $this->assertFalse($grantee->can('view', $this->marketingTask));
    }

    /**
     * สิทธิ์นี้ต้องไม่ทำให้หน้างานยิงคิวรีเพิ่มรายงาน
     *
     * WorkOrderPolicy::view() ถูกถามทีละงานหลายสิบใบต่อหน้า ถ้า departmentIdsFor()
     * อ่านตาราง pivot ใหม่ทุกครั้ง หน้าเดียวจะมีคิวรีเพิ่มตามจำนวนงาน
     * loadMissing() บน User instance เดียวของ request จึงต้องจำกัดไว้ที่ครั้งเดียว
     */
    public function test_the_grant_costs_at_most_one_extra_query_per_request(): void
    {
        $grantee = $this->grant(
            $this->employee($this->hr),
            DepartmentTaskVisibility::SCOPE_CUSTOM,
            [$this->marketing]
        );

        foreach (range(1, 12) as $index) {
            $this->task($this->marketing, $this->marketingOwner, 'งาน Marketing ใบที่ '.$index);
        }

        $pivotQueries = 0;
        DB::listen(function ($query) use (&$pivotQueries): void {
            if (str_contains($query->sql, 'user_task_visibility_departments')) {
                $pivotQueries++;
            }
        });

        $this->actingAs($grantee)->get(route('mytasks.index', ['view' => 'board']))->assertOk();

        $this->assertLessThanOrEqual(
            1,
            $pivotQueries,
            'ตาราง pivot ถูกอ่าน '.$pivotQueries.' ครั้งต่อหนึ่งหน้า — relation ไม่ถูก cache ตาม instance'
        );
    }

    /**
     * @param  array<int, Department>  $departments
     */
    private function grant(User $user, string $scope, array $departments = []): User
    {
        $user->forceFill(['task_visibility_scope' => $scope])->save();
        $user->visibleDepartments()->sync(collect($departments)->pluck('id')->all());

        return $user->fresh();
    }

    private function employee(Department $department, bool $head = false): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'department_id' => null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(Department $department, User $assignee, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $department->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDays(5),
            ...$overrides,
        ]);
    }
}
