<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\DepartmentTaskVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Member Workspace แบบอ่านอย่างเดียวสำหรับผู้ได้สิทธิ์ "เห็นทุกคนในแผนกของตนเอง"
 *
 * กติกา (WorkOrderPolicy::viewMemberWorkspace):
 *   - own_department เปิด Workspace ของสมาชิกแผนกเดียวกันได้ อ่านอย่างเดียว
 *   - custom และ involved ไม่ได้สิทธิ์นี้ — เห็นแค่ Preview เหมือนเดิม
 *   - หัวหน้าแผนกและ admin คงกติกาเดิม
 *
 * การซ่อนปุ่มไม่ใช่การกันสิทธิ์ ทุกเทสต์จึงยิง URL ตรงด้วย ไม่ใช่ดูแค่ว่ามีปุ่มหรือไม่
 */
class OwnDepartmentMemberWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $sales;

    private User $member;

    private User $grantee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->sales = Department::create(['department_name' => 'Sales']);
        $this->member = $this->employee($this->it, 'สมาชิก IT');
        $this->grantee = $this->employee($this->it, 'ผู้ได้สิทธิ์ทั้งแผนก', DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
    }

    public function test_policy_matrix_for_opening_a_member_workspace(): void
    {
        $plain = $this->employee($this->it, 'พนักงานทั่วไป');
        $custom = $this->employee($this->sales, 'สิทธิ์กำหนดเอง', DepartmentTaskVisibility::SCOPE_CUSTOM, [$this->it]);
        $head = $this->employee($this->it, 'หัวหน้า IT', head: true);
        $salesHead = $this->employee($this->sales, 'หัวหน้า Sales', head: true);
        $salesMember = $this->employee($this->sales, 'สมาชิก Sales');
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null, 'is_active' => true, 'must_change_password' => false]);
        $viewer = User::factory()->create(['role' => 'viewer', 'department_id' => $this->it->id, 'is_active' => true, 'must_change_password' => false]);

        $allows = fn (User $actor, User $subject) => Gate::forUser($actor)->allows('viewMemberWorkspace', [WorkOrder::class, $subject]);

        $this->assertTrue($allows($this->grantee, $this->member), 'own_department เปิดของสมาชิกแผนกเดียวกันได้');
        $this->assertFalse($allows($this->grantee, $salesMember), 'own_department ต้องไม่ข้ามไปแผนกอื่น');
        $this->assertFalse($allows($plain, $this->member), 'สิทธิ์เริ่มต้นได้แค่ Preview');
        $this->assertFalse($allows($custom, $this->member), 'custom ไม่ได้สิทธิ์เปิด Workspace ของแผนกที่เลือก');
        $this->assertTrue($allows($head, $this->member));
        $this->assertFalse($allows($salesHead, $this->member), 'หัวหน้าแผนกอื่นยังเปิดไม่ได้เหมือนเดิม');
        $this->assertTrue($allows($admin, $this->member));
        $this->assertFalse($allows($viewer, $this->member));

        // บัญชีที่ถูกปิดใช้งานไม่มีสิทธิ์ใด ๆ — กติกาเดียวกับ DepartmentTaskVisibility::departmentIdsFor()
        $this->grantee->forceFill(['is_active' => false])->save();
        $this->assertFalse($allows($this->grantee->fresh(), $this->member));
    }

    public function test_own_department_grantee_sees_the_link_and_opens_a_read_only_workspace(): void
    {
        $task = $this->task($this->it, $this->member, 'งานประจำของสมาชิก');
        $workspaceUrl = route('work-board.member', [$this->it, $this->member, 'workspace' => 1]);

        $this->actingAs($this->grantee)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertSee('data-preview-workspace-link', false)
            ->assertSee('href="'.e($workspaceUrl).'"', false)
            ->assertSee('อ่านอย่างเดียว');

        $response = $this->actingAs($this->grantee)->get($workspaceUrl)
            ->assertOk()
            ->assertSee('class="notion-viewbar"', false)
            ->assertSee('งานประจำของสมาชิก')
            ->assertViewHas('isReadOnlyWorkspace', true)
            ->assertViewHas('viaDepartmentHead', true);

        // ไม่มีเครื่องมือเขียนใด ๆ หลุดมา
        $this->assertTrue($response->viewData('manageableTaskLists')->isEmpty());
        $this->assertTrue($response->viewData('availableCollaborators')->isEmpty());
        $response->assertDontSee('data-board-rename-task', false)
            ->assertDontSee('data-board-delete-task', false)
            ->assertDontSee('data-open-user-task-create', false);

        // และ server ปฏิเสธการเขียนจริง ไม่ใช่แค่ซ่อนปุ่ม
        $this->actingAs($this->grantee)
            ->patchJson(route('tasks.updateStatus', ['id' => $task->job_id]), ['job_status' => 5])
            ->assertForbidden();
        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_plain_and_custom_users_keep_the_preview_only(): void
    {
        $plain = $this->employee($this->it, 'พนักงานทั่วไป');
        $custom = $this->employee($this->sales, 'สิทธิ์กำหนดเอง', DepartmentTaskVisibility::SCOPE_CUSTOM, [$this->it]);
        $this->task($this->it, $this->member, 'งานวันนี้ของสมาชิก');

        foreach ([$plain, $custom] as $actor) {
            $this->actingAs($actor)
                ->get(route('work-board.member', [$this->it, $this->member]))
                ->assertOk()
                ->assertSee('งานวันนี้ของสมาชิก')
                ->assertDontSee('data-preview-workspace-link', false)
                ->assertDontSee('data-preview-task-link', false);

            // URL ตรงก็ไม่เปิด Workspace — ได้ Preview เท่าเดิม
            $this->actingAs($actor)
                ->get(route('work-board.member', [$this->it, $this->member, 'workspace' => 1]))
                ->assertOk()
                ->assertDontSee('class="notion-viewbar"', false);
        }
    }

    public function test_grantee_cannot_open_a_member_workspace_of_another_department(): void
    {
        $salesMember = $this->employee($this->sales, 'สมาชิก Sales');

        $this->actingAs($this->grantee)
            ->get(route('work-board.member', [$this->sales, $salesMember]))
            ->assertOk()
            ->assertDontSee('data-preview-workspace-link', false);

        $this->actingAs($this->grantee)
            ->get(route('work-board.member', [$this->sales, $salesMember, 'workspace' => 1]))
            ->assertOk()
            ->assertDontSee('class="notion-viewbar"', false);
    }

    /**
     * Workspace เป็นของสมาชิก จึงอาจมีงานข้ามแผนกที่เขาไปร่วม — own_department ไม่ครอบคลุมงานนั้น
     * หัวหน้าแผนกยังเห็นตามกติกาเดิม (WorkOrderPolicy::overseesMemberOnTask)
     */
    public function test_grantee_workspace_only_shows_tasks_the_grantee_may_view(): void
    {
        $head = $this->employee($this->it, 'หัวหน้า IT', head: true);
        $salesOwner = $this->employee($this->sales, 'เจ้าของงาน Sales');
        $own = $this->task($this->it, $this->member, 'งานในแผนก IT');
        $crossDepartment = $this->task($this->sales, $salesOwner, 'งานลับของแผนก Sales');
        $crossDepartment->collaborators()->attach($this->member->id, ['added_by' => $salesOwner->id, 'status' => 'accepted']);

        $this->assertFalse($this->grantee->can('view', $crossDepartment->fresh()), 'สมมติฐานของเทสต์: ผู้ได้สิทธิ์ดูงานแผนก IT เปิดงาน Sales ไม่ได้');

        $granteeView = $this->actingAs($this->grantee)
            ->get(route('work-board.member', [$this->it, $this->member, 'workspace' => 1]))
            ->assertOk()
            ->assertSee('งานในแผนก IT')
            ->assertDontSee('งานลับของแผนก Sales');
        $this->assertFalse($granteeView->viewData('activeTasks')->contains('job_id', $crossDepartment->job_id));
        $this->assertSame(['projects' => 0, 'tasks' => 1], $granteeView->viewData('totals'));

        $headView = $this->actingAs($head)
            ->get(route('work-board.member', [$this->it, $this->member, 'workspace' => 1]))
            ->assertOk()
            ->assertSee('งานลับของแผนก Sales');
        $this->assertTrue($headView->viewData('activeTasks')->contains('job_id', $own->job_id));
    }

    /**
     * มุมมองบอร์ด kanban ของ Member Workspace ต้องวาดงานของ "สมาชิกที่ถูกดู"
     *
     * เดิมกรองด้วย participate ของผู้ที่ล็อกอิน หัวหน้าหรือผู้ได้สิทธิ์ที่เปิดดูจึงเห็นเฉพาะงาน
     * ที่ตัวเองบังเอิญอยู่ด้วย งานที่สมาชิกสร้างและทำเองหายไปจากบอร์ดทั้งหมด
     */
    public function test_member_workspace_kanban_draws_the_members_own_tasks_for_every_viewer(): void
    {
        $head = $this->employee($this->it, 'หัวหน้า IT', head: true);
        $task = $this->task($this->it, $this->member, 'งานที่สมาชิกสร้างและทำเอง');

        foreach ([$head, $this->grantee] as $viewer) {
            $html = $this->actingAs($viewer)
                ->get(route('work-board.member', [$this->it, $this->member, 'workspace' => 1, 'view' => 'table']))
                ->assertOk()
                ->getContent();

            $this->assertMatchesRegularExpression('/data-kanban-card\s+data-id="'.$task->job_id.'"/', $html, $viewer->name.' ต้องเห็นการ์ดงานของสมาชิกในบอร์ด');
        }
    }

    private function employee(Department $department, string $name, string $scope = DepartmentTaskVisibility::SCOPE_INVOLVED, array $departments = [], bool $head = false): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
            'task_visibility_scope' => $scope,
        ]);
        $user->visibleDepartments()->sync(collect($departments)->pluck('id')->all());

        return $user->fresh();
    }

    private function task(Department $department, User $assignee, string $topic): WorkOrder
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
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDays(3),
        ]);
    }
}
