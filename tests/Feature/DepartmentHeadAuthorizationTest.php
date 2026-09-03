<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NotificationMaintenanceService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentHeadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_department_head_as_user_permission_overlay(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $admin = $this->user(null, false, 'admin');

        $this->actingAs($admin)->post(route('employees.store'), [
            'name' => 'IT Head',
            'username' => 'it-head',
            'email' => null,
            'phone' => null,
            'password' => 'SecurePassword!123',
            'password_confirmation' => 'SecurePassword!123',
            'role' => 'department_head',
            'is_active' => true,
            'department_id' => $department->id,
        ])->assertRedirect(route('employees.index'))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('users', [
            'username' => 'it-head',
            'role' => 'user',
            'is_department_head' => true,
            'department_id' => $department->id,
        ]);
    }

    public function test_destination_department_head_receives_and_can_decide_only_own_assignment_requests(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $requester = $this->user($it);
        $assignee = $this->user($sales);
        $salesHead = $this->user($sales, true);
        $itHead = $this->user($it, true);
        $admin = $this->user(null, false, 'admin');

        $this->actingAs($requester)->post(route('tasks.store'), [
            'job_topic' => 'Cross department request',
            'user_id' => $assignee->id,
            'job_start_at' => now()->format('Y-m-d'),
            'job_due_at' => now()->addDay()->format('Y-m-d'),
            'job_priority' => 2,
        ])->assertRedirect();

        $job = WorkOrder::where('job_topic', 'Cross department request')->firstOrFail();
        $this->assertSame('pending', $job->approval_status);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $salesHead->id,
            'work_order_id' => $job->job_id,
            'type' => 'cross_department_pending',
        ]);
        $this->assertSame(0, SystemNotification::where('user_id', $admin->id)
            ->where('work_order_id', $job->job_id)->count());

        $this->actingAs($itHead)->get(route('admin.approvals.index'))
            ->assertOk()->assertDontSee('Cross department request');
        $this->actingAs($itHead)->patchJson(route('admin.tasks.approval', $job), [
            'approval_status' => 'approved',
        ])->assertForbidden();

        $this->actingAs($salesHead)->get(route('admin.approvals.index'))
            ->assertOk()->assertSee('Cross department request');
        $this->actingAs($salesHead)->patchJson(route('admin.tasks.approval', $job), [
            'approval_status' => 'approved',
        ])->assertOk();
        $this->assertSame('approved', $job->fresh()->approval_status);
    }

    public function test_head_board_workspace_reports_and_sidebar_are_scoped_and_read_only(): void
    {
        $sales = Department::create(['department_name' => 'Sales']);
        $it = Department::create(['department_name' => 'IT']);
        $head = $this->user($sales, true);
        $member = $this->user($sales);
        $outsider = $this->user($it);
        $own = $this->task($sales, $member, 'Sales scoped task');
        $this->task($it, $outsider, 'IT secret task');

        // บอร์ดรวมต้องเห็นทุกแผนกเท่าที่ผู้ใช้ทั่วไปเห็น หัวหน้าห้ามเห็นน้อยกว่าลูกทีมตัวเอง
        $this->actingAs($head)->get(route('work-board.index'))
            ->assertOk()->assertSee('Sales')->assertSee('IT')->assertDontSee('IT secret task');

        // แผนกอื่นเปิดดูได้ แต่ต้องเป็นมุมมองของผู้ใช้ทั่วไป ไม่มีทางลัดเข้า Workspace เต็ม
        $this->actingAs($head)->get(route('work-board.department', $it))->assertOk();
        $this->actingAs($head)->get(route('work-board.member', [$sales, $member, 'workspace' => 1]))
            ->assertOk()
            ->assertSee('Sales scoped task')
            ->assertSee('class="notion-viewbar"', false)
            ->assertSee(route('work-board.member', [$sales, $member, 'workspace' => 1, 'view' => 'meeting']))
            ->assertDontSee('data-open-admin-assignment', false)
            ->assertSee('"can_work":false', false)
            ->assertSee('"can_manage_team":false', false);

        $this->actingAs($head)
            ->get(route('work-board.member', [$sales, $member, 'workspace' => 1, 'view' => 'meeting']))
            ->assertOk()
            ->assertSee('<!DOCTYPE html>', false)
            ->assertSee('class="notion-viewbar"', false)
            ->assertSee('notion-database" data-view="meeting"', false)
            ->assertSee('name="workspace" value="1"', false);
        // สมาชิกแผนกอื่นดูได้แค่งานวันนี้ ไม่มีลิงก์เข้า Workspace และเปิดตรงก็ยังโดนปฏิเสธ
        $this->actingAs($head)->get(route('work-board.member', [$it, $outsider]))
            ->assertOk()
            ->assertDontSee('data-preview-workspace-link', false);
        // ใส่ ?workspace=1 ตรง ๆ ก็ยังได้แค่มุมมองงานวันนี้ Workspace เต็มต้องไม่ถูก render เลย
        // (เท่ากับที่ผู้ใช้ทั่วไปโดน จึงไม่ตอบ 403 แต่ต้องพิสูจน์ว่าไม่มีเครื่องมือแก้ไขหลุดออกมา)
        $this->actingAs($head)->get(route('work-board.member', [$it, $outsider, 'workspace' => 1]))
            ->assertOk()
            ->assertDontSee('class="notion-viewbar"', false)
            ->assertDontSee('data-preview-workspace-link', false)
            // งานวันนี้ของคนนั้นเห็นได้ เท่ากับที่ผู้ใช้ทั่วไปเห็นอยู่แล้ว ไม่ใช่ข้อมูลใหม่ที่เพิ่งเปิด
            ->assertSee('IT secret task');

        $report = $this->actingAs($head)->get(route('reports.organization'));
        $report->assertOk()->assertDontSee('IT secret task');
        $this->assertSame($sales->id, $report->viewData('filters')['department_id']);
        $this->assertSame(1, $report->viewData('totalJobs'));
        $this->actingAs($head)->get(route('reports.employee', $outsider))->assertForbidden();

        $selfTask = $this->task($sales, $head, 'Head own task');
        $this->actingAs($head)->get(route('work-board.member', [$sales, $head, 'workspace' => 1]))
            ->assertOk()
            ->assertSee('Head own task')
            ->assertSee('"can_self_close":false', false)
            ->assertSee('"allowed_statuses":[2]', false);

        $this->actingAs($head)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('หัวหน้าแผนก')
            ->assertSee(route('admin.approvals.index'), false)
            ->assertDontSee('href="'.route('meetings.index').'"', false)
            ->assertSee('href="'.route('mytasks.index', ['view' => 'meeting']).'"', false)
            ->assertDontSee(route('employees.index'), false)
            ->assertDontSee(route('admin.departments.index'), false);

        $this->actingAs($head)->patchJson(route('tasks.updateStatus', $own), [
            'job_status' => 4,
        ])->assertForbidden();
        $this->actingAs($head)->patchJson(route('tasks.updateStatus', $selfTask), [
            'job_status' => 4,
        ])->assertOk();
    }

    public function test_candidate_department_head_decides_only_collaborator_requests_for_own_department(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $owner = $this->user($it);
        $candidate = $this->user($sales);
        $salesHead = $this->user($sales, true);
        $itHead = $this->user($it, true);
        $job = $this->task($it, $owner, 'Cross team request');
        $job->collaborators()->attach($candidate->id, [
            'status' => 'pending',
            'added_by' => $owner->id,
        ]);

        $this->actingAs($itHead)->patchJson(
            route('admin.tasks.collaborators.approval', [$job, $candidate]),
            ['status' => 'accepted'],
        )->assertForbidden();

        $this->actingAs($salesHead)->get(route('admin.approvals.index'))
            ->assertOk()->assertSee('Cross team request')->assertSee($candidate->name);
        $this->actingAs($salesHead)->patchJson(
            route('admin.tasks.collaborators.approval', [$job, $candidate]),
            ['status' => 'accepted'],
        )->assertOk();

        $this->assertSame(
            'accepted',
            $job->fresh()->collaborators()->findOrFail($candidate->id)->pivot->status,
        );
    }

    public function test_head_sees_department_meetings_but_cannot_edit_team_members_meeting(): void
    {
        $sales = Department::create(['department_name' => 'Sales']);
        $it = Department::create(['department_name' => 'IT']);
        $head = $this->user($sales, true);
        $member = $this->user($sales);
        $outsider = $this->user($it);
        $ownDepartmentMeeting = $this->meeting($member, 'Sales planning');
        $foreignMeeting = $this->meeting($outsider, 'IT confidential');

        $this->actingAs($head)->get(route('meetings.index', ['period' => 'all']))
            ->assertOk()->assertSee('Sales planning')->assertDontSee('IT confidential');
        $this->actingAs($head)->get(route('meetings.show', $ownDepartmentMeeting))->assertOk();
        $this->actingAs($head)->get(route('meetings.show', $foreignMeeting))->assertForbidden();
        $this->actingAs($head)->patchJson(route('meetings.update', $ownDepartmentMeeting), [
            'title' => 'Tampered',
        ])->assertForbidden();
    }

    /**
     * บอร์ดรวมเป็นภาพรวมทั้งองค์กร หัวหน้าแผนกจึงต้องเห็นแผนกครบเท่ากับผู้ใช้ทั่วไป
     * ความต่างของสิทธิ์อยู่ที่ "ลงลึกได้แค่ไหน" ไม่ใช่ "เห็นแผนกไหนบ้าง"
     */
    public function test_head_sees_every_department_but_only_manages_the_one_they_lead(): void
    {
        $sales = Department::create(['department_name' => 'Sales']);
        $it = Department::create(['department_name' => 'IT']);
        $head = $this->user($sales, true);
        $teammate = $this->user($sales);
        $outsider = $this->user($it);
        $plainUser = $this->user($sales);

        $this->task($sales, $teammate, 'Sales task');
        $this->task($it, $outsider, 'IT task');

        // หัวหน้าต้องเห็นรายชื่อแผนกเท่ากับผู้ใช้ทั่วไปเป๊ะ ๆ ไม่ใช่แค่แผนกตัวเอง
        $headDepartments = $this->actingAs($head)->get(route('work-board.index'))
            ->assertOk()->viewData('departments')->pluck('department_name')->sort()->values();
        $userDepartments = $this->actingAs($plainUser)->get(route('work-board.index'))
            ->assertOk()->viewData('departments')->pluck('department_name')->sort()->values();

        $this->assertEquals(['IT', 'Sales'], $headDepartments->all());
        $this->assertEquals($userDepartments->all(), $headDepartments->all());

        // แผนกที่ตนดูแล: เข้า Workspace เต็มได้
        $this->actingAs($head)->get(route('work-board.member', [$sales, $teammate]))
            ->assertOk()->assertSee('data-preview-workspace-link', false);
        $this->actingAs($head)->get(route('work-board.member', [$sales, $teammate, 'workspace' => 1]))
            ->assertOk()->assertSee('class="notion-viewbar"', false);

        // แผนกอื่น: เห็นงานวันนี้ได้ แต่ต้องไม่มีทางเข้า Workspace เต็ม
        $this->actingAs($head)->get(route('work-board.member', [$it, $outsider]))
            ->assertOk()
            ->assertSee('IT task')
            ->assertDontSee('data-preview-workspace-link', false)
            ->assertDontSee('class="notion-viewbar"', false);
    }

    /**
     * หัวหน้าแผนกต้องรู้เมื่อลูกทีมมอบหมายงานกันเองภายในแผนก
     *
     * เดิม notifyTaskAdmins() ส่งเฉพาะ role = 'admin' หัวหน้าจึงไม่เคยได้รับแจ้งเตือนเลย
     * ทั้งที่เป็นคนที่ต้องติดตามภาระงานของแผนกโดยตรง
     */
    public function test_department_head_is_notified_when_their_team_assigns_work_internally(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itHead = $this->user($it, true);
        $salesHead = $this->user($sales, true);
        $assigner = $this->user($it);
        $assignee = $this->user($it);

        $task = $this->task($it, $assignee, 'Internal IT assignment');
        $task->update(['created_by' => $assigner->id, 'leader_user_id' => $assigner->id]);

        app(NotificationService::class)
            ->notifyAssignmentCreated($task->fresh(), $assigner, $assignee, true);

        // หัวหน้าแผนกเจ้าของงานต้องได้รับ ส่วนหัวหน้าแผนกอื่นต้องไม่ได้
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $itHead->id,
            'type' => 'same_department_assignment',
            'work_order_id' => $task->job_id,
        ]);
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $salesHead->id,
            'type' => 'same_department_assignment',
        ]);

        // ผู้รับงานได้ฉบับ "มีงานใหม่" ของตัวเองแล้ว ต้องไม่ได้ฉบับของฝ่ายดูแลซ้ำอีก
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $assignee->id,
            'type' => 'task_assigned',
        ]);
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $assignee->id,
            'type' => 'same_department_assignment',
        ]);

        // ผู้มอบหมายเป็นคนกระทำเอง ไม่ต้องแจ้งกลับหาตัวเอง
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $assigner->id]);
    }

    /**
     * งานของลูกทีมไม่ได้อยู่ในหน้า "งานของฉัน" ของหัวหน้า
     * ลิงก์แจ้งเตือนจึงต้องพาไป Workspace ของสมาชิกคนนั้น ไม่งั้นกดแล้วเปิดงานไม่เจอ
     */
    public function test_head_notification_opens_the_member_workspace_not_their_own_task_page(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $head = $this->user($it, true);
        $assigner = $this->user($it);
        $assignee = $this->user($it);

        $task = $this->task($it, $assignee, 'Internal IT assignment');
        $task->update(['created_by' => $assigner->id, 'leader_user_id' => $assigner->id]);

        $notifications = app(NotificationService::class);
        $notifications->notifyAssignmentCreated($task->fresh(), $assigner, $assignee, true);

        $notification = SystemNotification::where('user_id', $head->id)
            ->where('type', 'same_department_assignment')
            ->firstOrFail();

        $this->assertSame(
            route('work-board.member', [
                'department' => $it->id,
                'user' => $assignee->id,
                'workspace' => 1,
                'open_task' => $task->job_id,
            ]),
            $notifications->target($notification, $head->fresh()),
        );

        // ปลายทางต้องเปิดได้จริงและเห็นงานนั้น ไม่ใช่แค่ URL ที่ถูกรูปแบบ
        $this->actingAs($head)->get($notifications->target($notification, $head->fresh()))
            ->assertOk()
            ->assertSee('Internal IT assignment');
    }

    /**
     * งานเลยกำหนดคือเรื่องเดียวในงานประจำวันที่หัวหน้าเข้าไปช่วยได้จริง จึงแจ้งเฉพาะกรณีนี้
     * ส่วน "ครบกำหนดวันนี้" เป็นจังหวะทำงานปกติ ถ้าแจ้งด้วยหัวหน้าจะโดนถล่มจนเลิกอ่าน
     */
    public function test_department_head_hears_about_overdue_work_but_not_routine_due_dates(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itHead = $this->user($it, true);
        $salesHead = $this->user($sales, true);
        $member = $this->user($it);

        $overdue = $this->task($it, $member, 'Overdue IT task');
        $overdue->update(['job_due_at' => now()->subDays(3)]);
        $dueToday = $this->task($it, $member, 'Due today IT task');
        $dueToday->update(['job_due_at' => now()]);

        app(NotificationMaintenanceService::class)->generateDeadlines();

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $itHead->id,
            'type' => 'deadline_overdue',
            'work_order_id' => $overdue->job_id,
        ]);
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $itHead->id,
            'type' => 'deadline_due_today',
        ]);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $salesHead->id]);

        // เจ้าของงานยังได้ครบทั้งสองแบบเหมือนเดิม การเพิ่มหัวหน้าต้องไม่ไปแทนที่ใคร
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $member->id,
            'type' => 'deadline_due_today',
            'work_order_id' => $dueToday->job_id,
        ]);

        // cron รันซ้ำในวันเดียวกันต้องไม่แจ้งหัวหน้าซ้ำ
        app(NotificationMaintenanceService::class)->generateDeadlines();
        $this->assertSame(1, SystemNotification::where('user_id', $itHead->id)
            ->where('type', 'deadline_overdue')->count());
    }

    /** งานที่หายไปจากแผนกต้องมีผู้รับผิดชอบรู้เสมอ ทั้งตอนขอลบและตอนลบจริง */
    public function test_department_head_hears_about_delete_requests_and_deletions(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itHead = $this->user($it, true);
        $salesHead = $this->user($sales, true);
        $admin = $this->user(null, false, 'admin');
        $member = $this->user($it);
        $task = $this->task($it, $member, 'Task to remove');

        $this->actingAs($member)->postJson(route('tasks.deleteRequest.store', $task->job_id), [
            'reason' => 'สร้างผิดโปรเจกต์',
        ])->assertOk();

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $itHead->id,
            'type' => 'delete_request',
            'work_order_id' => $task->job_id,
        ]);
        // ผู้ดูแลระบบยังเป็นผู้ตัดสินใจเหมือนเดิม หัวหน้าแค่รับรู้
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $admin->id,
            'type' => 'delete_request',
        ]);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $salesHead->id]);

        app(NotificationService::class)
            ->notifyTaskDeleted($task->fresh(), 'งาน "Task to remove" ถูกลบแล้ว', $admin);

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $itHead->id,
            'type' => 'task_deleted',
        ]);
    }

    /**
     * เมนู "รายงานแผนก" ต้องพาไปหน้าเลือกประเภทรายงานก่อน ไม่ใช่กระโดดข้ามไปภาพรวมเลย
     * และหน้าเลือกพนักงานต้องไม่โฆษณา "ทุกแผนก" ให้คนที่ดูได้แผนกเดียว
     */
    public function test_head_report_menu_lands_on_the_report_chooser_and_hides_cross_department_options(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $head = $this->user($it, true);
        $member = $this->user($it);
        $outsider = $this->user($sales);

        // เมนูต้องชี้ไปหน้าเลือกประเภทรายงาน ไม่ใช่ /reports/organization
        $this->actingAs($head)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee(route('reports.index'), false)
            ->assertDontSee('href="'.route('reports.organization').'"', false);

        $this->actingAs($head)->get(route('reports.index'))->assertOk();

        // หน้าเลือกพนักงาน: เห็นเฉพาะแผนกตัวเอง และต้องไม่มีตัวเลือก "ทุกแผนก" ที่ให้ผลเท่ากัน
        $this->actingAs($head)->get(route('reports.employees.index'))
            ->assertOk()
            ->assertSee($member->name)
            ->assertDontSee($outsider->name)
            ->assertDontSee('ทุกแผนก')
            ->assertSee('IT');

        // ข้อมูลยังถูกจำกัดที่เซิร์ฟเวอร์เหมือนเดิม ไม่ได้พึ่งการซ่อนปุ่ม
        $this->actingAs($head)->get(route('reports.employee', $outsider))->assertForbidden();
    }

    private function user(?Department $department, bool $head = false, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
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
            'job_start_at' => now(),
            'job_due_at' => now()->addDays(5),
        ]);
    }

    private function meeting(User $creator, string $title): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'Meeting description',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'location' => 'Meeting room',
            'created_by' => $creator->id,
        ]);
    }
}
