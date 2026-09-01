<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $departmentResponse = $this->actingAs($viewer)
            ->get(route('work-board.department', $department))
            ->assertOk()
            ->assertSee($assignee->name)
            ->assertSee($project->name)
            ->assertSee('data-work-board-directory', false)
            ->assertSee('data-member-card', false)
            ->assertSee('data-member-preview-trigger', false)
            ->assertSee(route('work-board.member', [$department, $assignee]), false)
            ->assertDontSee('wb-overview', false)
            ->assertDontSee('wb-member-table__head', false)
            ->assertDontSee('wb-member-row', false)
            ->assertDontSee($job->job_topic)
            ->assertDontSee($job->job_details);

        $preview = $this->actingAs($viewer)
            ->get(route('work-board.member', [$department, $assignee]))
            ->assertOk()
            ->assertSee($job->job_topic)
            ->assertSee($project->name)
            ->assertSee('กำลังทำ')
            ->assertSee('สำคัญด่วน')
            ->assertSee('data-preview-readonly', false)
            ->assertDontSee($job->job_details)
            ->assertDontSee($collaborator->name)
            ->assertDontSee('data-preview-task-link', false)
            ->assertDontSee(route('tasks.show', $job), false)
            ->assertDontSee(route('admin.work-board.member', [$department, $assignee]), false)
            ->assertDontSee('<form', false);

        $this->assertStringNotContainsString('href=', $preview->getContent());
        $this->assertCount(3, $departmentResponse->viewData('members'));
    }

    public function test_department_list_is_a_card_grid_showing_only_the_member_count(): void
    {
        $department = Department::create(['department_name' => 'Callcenter']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $teammate = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $project = WorkOrderList::create(['user_id' => $teammate->id, 'name' => 'โปรเจกต์ที่ต้องไม่โผล่']);
        WorkOrder::create([
            'user_id' => $teammate->id,
            'department_id' => $department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'งานที่ต้องไม่โผล่',
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('work-board.index'))
            ->assertOk()
            ->assertSee('wb-department-grid', false)
            ->assertSee('wb-department-card', false)
            ->assertSee('แผนกทั้งหมด')
            ->assertSee('ภาพรวมแผนกและสมาชิกในองค์กร')
            ->assertSee('ข้อมูลล่าสุดจากระบบ Smart Goal')
            ->assertSee('สมาชิก')
            ->assertSee('ดูรายละเอียด')
            ->assertSee(route('work-board.department', $department), false);

        // การ์ดต้องเหลือแค่จำนวนสมาชิก ห้ามให้ metric โปรเจกต์/งาน หรือ layout แบบแถวเดิมกลับมา
        $response->assertDontSee('โปรเจกต์')
            ->assertDontSee('wb-department-row', false)
            ->assertDontSee('wb-department-stat', false)
            ->assertDontSee('progress', false);

        $listed = $response->viewData('departments')->firstWhere('id', $department->id);

        $this->assertSame('CA', $listed->board_code);
        $this->assertSame(2, $listed->member_count);
    }

    public function test_user_preview_exposes_only_approved_assigned_task_overview_fields(): void
    {
        $department = Department::create(['department_name' => 'Security']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $otherMember = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $collaborator = User::factory()->create(['name' => 'Hidden Collaborator', 'role' => 'user', 'department_id' => $department->id]);
        $project = WorkOrderList::create(['user_id' => $member->id, 'name' => 'Visible Project']);

        $approved = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $viewer->id,
            'leader_user_id' => $member->id,
            'department_id' => $department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'Approved overview task',
            'job_details' => 'Sensitive task details',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        WorkOrderUpdate::create([
            'work_order_id' => $approved->job_id,
            'user_id' => $member->id,
            'note' => 'Sensitive update note',
        ]);
        JobImage::create([
            'job_id' => $approved->job_id,
            'file_path' => 'job-attachments/'.$approved->job_id.'/hidden-plan.pdf',
            'original_name' => 'hidden-plan.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $member->id,
        ]);
        $approved->collaborators()->attach($collaborator->id, [
            'added_by' => $viewer->id,
            'status' => 'accepted',
        ]);

        WorkOrder::create([
            'user_id' => $member->id,
            'department_id' => $department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'Pending secret task',
            'job_details' => 'Pending secret details',
            'job_priority' => 3,
            'job_status' => 2,
            'approval_status' => 'pending',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        WorkOrder::create([
            'user_id' => $otherMember->id,
            'department_id' => $department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'Wrong member task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($viewer)
            ->get(route('work-board.member', [$department, $member]))
            ->assertOk()
            ->assertSee('Approved overview task')
            ->assertSee('Visible Project')
            ->assertDontSee('Pending secret task')
            ->assertDontSee('Wrong member task')
            ->assertDontSee('Sensitive task details')
            ->assertDontSee('Sensitive update note')
            ->assertDontSee('hidden-plan.pdf')
            ->assertDontSee('application/pdf')
            ->assertDontSee('Hidden Collaborator')
            ->assertDontSee('data-attachment', false)
            ->assertDontSee('data-preview-task-link', false)
            ->assertDontSee('tasks/', false);
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

    public function test_department_member_filters_use_name_email_status_and_project(): void
    {
        $department = Department::create(['department_name' => 'Filter Team']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $doingMember = User::factory()->create([
            'name' => 'Alpha Member',
            'email' => 'alpha@example.test',
            'role' => 'user',
            'department_id' => $department->id,
        ]);
        $doneMember = User::factory()->create([
            'name' => 'Beta Member',
            'email' => 'beta@example.test',
            'role' => 'user',
            'department_id' => $department->id,
        ]);
        $doingProject = WorkOrderList::create(['user_id' => $doingMember->id, 'name' => 'Doing Project']);
        $doneProject = WorkOrderList::create(['user_id' => $doneMember->id, 'name' => 'Done Project']);

        foreach ([
            [$doingMember, $doingProject, 'Doing task', 2],
            [$doneMember, $doneProject, 'Done task', 4],
        ] as [$member, $project, $topic, $status]) {
            WorkOrder::create([
                'user_id' => $member->id,
                'department_id' => $department->id,
                'work_order_list_id' => $project->id,
                'job_topic' => $topic,
                'job_priority' => 2,
                'job_status' => $status,
                'approval_status' => 'approved',
                'job_start_at' => now(),
                'job_due_at' => now()->addDay(),
            ]);
        }

        $search = $this->actingAs($viewer)
            ->get(route('work-board.department', [$department, 'search' => 'beta@example.test']))
            ->assertOk();
        $this->assertSame([$doneMember->id], $search->viewData('members')->pluck('id')->all());

        $status = $this->actingAs($viewer)
            ->get(route('work-board.department', [$department, 'status' => 'doing']))
            ->assertOk();
        $this->assertSame([$doingMember->id], $status->viewData('members')->pluck('id')->all());

        $project = $this->actingAs($viewer)
            ->get(route('work-board.department', [$department, 'project_id' => $doneProject->id]))
            ->assertOk();
        $this->assertSame([$doneMember->id], $project->viewData('members')->pluck('id')->all());
    }

    public function test_user_preview_preserves_cross_department_browsing_and_rejects_invalid_member_context(): void
    {
        $first = Department::create(['department_name' => 'First']);
        $second = Department::create(['department_name' => 'Second']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $first->id]);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $second->id]);
        $inactive = User::factory()->create(['role' => 'user', 'department_id' => $second->id, 'is_active' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $first->id]);

        $this->actingAs($viewer)->get(route('work-board.department', $second))->assertOk();
        $this->actingAs($viewer)->get(route('work-board.member', [$second, $member]))->assertOk();
        $this->actingAs($viewer)->get(route('work-board.member', [$first, $member]))->assertNotFound();
        $this->actingAs($viewer)->get(route('work-board.member', [$second, $inactive]))->assertNotFound();
        $this->actingAs($admin)->get(route('work-board.member', [$second, $member]))->assertForbidden();
    }

    public function test_department_directory_query_count_does_not_scale_with_member_cards(): void
    {
        $department = Department::create(['department_name' => 'Scale Team']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        DB::enableQueryLog();
        $this->actingAs($viewer)->get(route('work-board.department', $department))->assertOk();
        $singleMemberQueries = count(DB::getQueryLog());

        User::factory()->count(12)->create(['role' => 'user', 'department_id' => $department->id]);
        DB::flushQueryLog();
        $this->actingAs($viewer)->get(route('work-board.department', $department))->assertOk();
        $manyMemberQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleMemberQueries + 1, $manyMemberQueries);
    }

    public function test_work_board_rejects_non_user_role_and_mismatched_department_member(): void
    {
        $first = Department::create(['department_name' => 'IT']);
        $second = Department::create(['department_name' => 'HR']);
        $user = User::factory()->create(['role' => 'user', 'department_id' => $first->id]);
        $otherMember = User::factory()->create(['role' => 'user', 'department_id' => $second->id]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $first->id]);
        $readOnlyViewer = User::factory()->create(['role' => 'viewer', 'department_id' => $first->id]);

        $this->actingAs($user)
            ->get(route('work-board.member', [$first, $otherMember]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('work-board.index'))
            ->assertForbidden();

        $this->actingAs($readOnlyViewer)
            ->get(route('work-board.member', [$second, $otherMember]))
            ->assertForbidden();
    }
}
