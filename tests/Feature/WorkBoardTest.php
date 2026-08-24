<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_follow_work_board_department_and_member_flow_with_real_data(): void
    {
        $department = Department::create(['department_name' => 'เทคโนโลยีสารสนเทศ']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $assignee = User::factory()->create([
            'name' => 'สมชาย ใจดี',
            'role' => 'user',
            'department_id' => $department->id,
        ]);
        $collaborator = User::factory()->create([
            'name' => 'สุดา ร่วมงาน',
            'role' => 'user',
            'department_id' => $department->id,
        ]);
        $project = WorkOrderList::create(['user_id' => $assignee->id, 'name' => 'ระบบ CRM ใหม่']);
        $job = WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $viewer->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'พัฒนาระบบจัดการลูกค้า',
            'job_details' => 'เชื่อมต่อข้อมูลลูกค้าจากระบบเดิม',
            'job_priority' => 3,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_progress' => 40,
            'job_start_at' => now(),
            'job_due_at' => now()->addWeek(),
        ]);
        $job->collaborators()->attach($collaborator->id, [
            'added_by' => $viewer->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('work-board.index'))
            ->assertOk()
            ->assertSee('บอร์ดทุกแผนก')
            ->assertSee($department->department_name)
            ->assertDontSee($project->name);

        $this->actingAs($viewer)
            ->get(route('work-board.department', $department))
            ->assertOk()
            ->assertSee($assignee->name)
            ->assertSee($project->name)
            ->assertSee('กำลังทำ');

        $this->actingAs($viewer)
            ->get(route('work-board.member', [$department, $assignee]))
            ->assertOk()
            ->assertSee($job->job_topic)
            ->assertSee($job->job_details)
            ->assertSee($collaborator->name, false)
            ->assertSee('สำคัญด่วน');
    }

    public function test_member_board_labels_every_task_priority_with_the_five_level_scale(): void
    {
        $department = Department::create(['department_name' => 'Priority Dept']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $assignee = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $project = WorkOrderList::create(['user_id' => $assignee->id, 'name' => 'Priority project']);

        foreach ([1, 2, 3, 4, 5] as $priority) {
            WorkOrder::create([
                'user_id' => $assignee->id,
                'created_by' => $assignee->id,
                'leader_user_id' => $assignee->id,
                'department_id' => $department->id,
                'work_order_list_id' => $project->id,
                'job_topic' => 'Priority task '.$priority,
                'job_priority' => $priority,
                'job_status' => 2,
                'approval_status' => 'approved',
                'job_progress' => 0,
                'job_start_at' => now(),
                'job_due_at' => now()->addWeek(),
            ]);
        }

        $this->actingAs($viewer)
            ->get(route('work-board.member', [$department, $assignee]))
            ->assertOk()
            ->assertSee('routine')
            ->assertSee('สำคัญไม่ด่วน')
            ->assertSee('สำคัญด่วน')
            ->assertSee('ด่วนไม่ค่อยสำคัญ')
            ->assertSee('ไม่รีบ ไม่มีกำหนด');
    }

    public function test_work_board_rejects_non_user_role_and_mismatched_department_member(): void
    {
        $first = Department::create(['department_name' => 'IT']);
        $second = Department::create(['department_name' => 'HR']);
        $user = User::factory()->create(['role' => 'user', 'department_id' => $first->id]);
        $otherMember = User::factory()->create(['role' => 'user', 'department_id' => $second->id]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $first->id]);

        $this->actingAs($user)
            ->get(route('work-board.member', [$first, $otherMember]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('work-board.index'))
            ->assertForbidden();
    }
}
