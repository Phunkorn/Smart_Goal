<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ลากชื่องานเพื่อเลื่อนลำดับในโปรเจกต์ บนมุมมองตาราง (data-view="board")
 *
 * การลากเป็นของ resources/js/pages/mytasks/task-reorder.js และจำไว้ในเบราว์เซอร์ของผู้ใช้
 * ฝั่ง server มีหน้าที่เดียวคือประกาศหัวข้อชื่องานของทุกแถวเป็นที่จับ ทั้งหน้า "งานของฉัน"
 * และ Workspace ของสมาชิกฝั่ง Admin ซึ่งใช้ component เดียวกัน — ไม่มีไอคอนจับหรือเมนูแยก
 */
class MyTasksBoardTaskReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_task_title_on_the_user_board_is_a_drag_handle(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $user = $this->user($department);
        $project = $this->project($user);
        $this->task($project, $user, 'งาน A');
        $this->task($project, $user, 'งาน B');

        $html = $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="board-task-details__heading" data-task-reorder-handle'));
        // ไม่มีไอคอนจับและเมนูเลื่อนลำดับแบบเดิมเหลืออยู่ (งานทั้งสองใบไม่มีงานย่อย จึงไม่มีไอคอนจับของงานย่อยด้วย)
        $this->assertStringNotContainsString('bi-grip-vertical', $html);
        $this->assertStringNotContainsString('data-task-reorder-menu', $html);
    }

    public function test_the_admin_member_workspace_board_uses_the_same_drag_handle(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $admin = $this->user($department, 'admin');
        $member = $this->user($department);
        $project = $this->project($admin);
        $this->task($project, $admin, 'งานของสมาชิก A', $member);
        $this->task($project, $admin, 'งานของสมาชิก B', $member);

        $html = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member, 'view' => 'board']))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="board-task-details__heading" data-task-reorder-handle'));
        $this->assertStringNotContainsString('data-task-reorder-menu', $html);
    }

    private function user(Department $department, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function project(User $owner): WorkOrderList
    {
        return WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'โปรเจกต์ทดสอบ',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);
    }

    private function task(WorkOrderList $project, User $creator, string $topic, ?User $assignee = null): WorkOrder
    {
        $assignee ??= $creator;

        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $creator->id,
            'approved_at' => now(),
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
