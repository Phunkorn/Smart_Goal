<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ReportMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeReportDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', ReportMetrics::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Technology']);
        $this->admin = $this->user('admin');
        $this->employee = $this->user('user');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * รายชื่อพนักงานของรายงานโปรเจกต์มีเฉพาะพนักงาน active (role user) — admin เห็นทุกแผนก
     * (แทนหน้าเลือกพนักงานเดิมที่มีช่องค้นหาและชิปแผนก)
     */
    public function test_the_project_report_owner_list_holds_only_active_employees(): void
    {
        $otherDepartment = Department::create(['department_name' => 'Finance']);
        $matching = $this->user('user', true, $otherDepartment, ['name' => 'สมชาย ใจดี']);
        $this->user('user', false, $otherDepartment, ['name' => 'สมหญิง ปิดใช้งาน']);
        $this->user('viewer', true, $otherDepartment, ['name' => 'สมศรี Viewer']);

        $response = $this->actingAs($this->admin)->get(route('reports.projects'));

        $response->assertOk()
            ->assertSee($matching->name.' · Finance')
            ->assertDontSee('สมหญิง ปิดใช้งาน')
            ->assertDontSee('สมศรี Viewer');
        $this->assertEqualsCanonicalizing(
            [$this->employee->id, $matching->id],
            $response->viewData('owners')->pluck('id')->all()
        );

        // หน้าเลือกพนักงานเดิมพาไปรายงานโปรเจกต์พร้อมแผนกที่เลือกไว้
        $this->actingAs($this->admin)
            ->get(route('reports.employees.index', ['department' => $otherDepartment->id, 'search' => 'สมชาย']))
            ->assertRedirect(route('reports.projects', ['department' => $otherDepartment->id]));
    }

    public function test_normal_user_cannot_access_selection_employee_report_or_export(): void
    {
        $normal = $this->user('user');

        $this->actingAs($normal)->get(route('reports.employees.index'))->assertForbidden();
        $this->actingAs($normal)->get(route('reports.employee', $this->employee))->assertForbidden();
        $this->actingAs($normal)->get(route('reports.employeeExportCsv', $this->employee))->assertForbidden();
        // รายงานโปรเจกต์ของพนักงานทั่วไปคือรายงานของตัวเองเสมอ แม้แก้ ?owner= เป็นคนอื่น
        $this->actingAs($normal)
            ->get(route('reports.projects', ['owner' => $this->employee->id]))
            ->assertOk()
            ->assertViewHas('owner', fn (User $owner) => $owner->is($normal));
    }

    /**
     * หัวหน้าแผนกเลือกลูกทีมและตัวเองได้จากช่องเดียวกัน ในหน้าเดียวกัน
     */
    public function test_department_head_picks_the_team_or_themselves_from_the_project_report(): void
    {
        $head = $this->user('user', true, $this->department, [
            'name' => 'หัวหน้า เทคโนโลยี',
            'is_department_head' => true,
        ]);
        $teamMember = $this->user('user', true, $this->department, ['name' => 'สมาชิกในทีม']);

        $overview = $this->actingAs($head)->get(route('reports.projects'));

        $overview->assertOk()
            ->assertViewIs('reports.projects.index')
            ->assertViewHas('isTeamView', true)
            ->assertSee($teamMember->name)
            ->assertSee('หัวหน้า เทคโนโลยี (ฉัน)');
        $this->assertEqualsCanonicalizing(
            [$this->employee->id, $teamMember->id, $head->id],
            $overview->viewData('owners')->pluck('id')->all()
        );

        $this->actingAs($head)->get(route('reports.employee', $head))
            ->assertRedirect(route('reports.projects', ['owner' => $head->id]));
        $this->actingAs($head)->get(route('reports.projects', ['owner' => $head->id]))
            ->assertOk()
            ->assertViewHas('owner', fn (User $owner) => $owner->is($head));

        // รายงานของฉันเดิมรวมเข้ารายงานโปรเจกต์แล้ว ลิงก์เดิมพามาหน้าเดียวกัน
        $this->actingAs($head)->get('/my-reports')->assertRedirect('/reports/projects');

        $this->actingAs($head)->get(route('reports.projects', ['owner' => $teamMember->id]))
            ->assertOk()
            ->assertViewHas('owner', fn (User $owner) => $owner->is($teamMember))
            ->assertSee('id="projectReportOwner"', false);
    }

    public function test_viewer_keeps_read_only_access_to_selection_employee_report_and_export(): void
    {
        $viewer = $this->user('viewer');
        $this->task($this->employee, ['job_topic' => 'Viewer readable report task']);

        $this->actingAs($viewer)->get(route('reports.employees.index'))
            ->assertRedirect(route('reports.projects'));
        $this->actingAs($viewer)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('ดูข้อมูลเท่านั้น');
        $this->actingAs($viewer)->get($this->employeeReportUrl())
            ->assertOk()
            ->assertSee('Viewer readable report task');
        $this->actingAs($viewer)->get(route('reports.employeeExportCsv', $this->employee))
            ->assertRedirect(route('reports.projects.csv', ['owner' => $this->employee->id]));
        $this->actingAs($viewer)->get(route('reports.projects.csv', ['owner' => $this->employee->id]))->assertOk();
    }

    public function test_invalid_inactive_admin_and_viewer_employee_targets_are_rejected(): void
    {
        $inactive = $this->user('user', false);
        $viewer = $this->user('viewer');

        $this->actingAs($this->admin)->get('/reports/employees/999999')->assertNotFound();
        $this->actingAs($this->admin)->get(route('reports.employee', $inactive))->assertNotFound();
        $this->actingAs($this->admin)->get(route('reports.employee', $this->admin))->assertNotFound();
        $this->actingAs($this->admin)->get(route('reports.employee', $viewer))->assertNotFound();
        $this->actingAs($this->admin)->get(route('reports.employeeExportCsv', $inactive))->assertNotFound();

        // รายงานโปรเจกต์ไม่เคยสร้างรายงานของเป้าหมายที่ไม่ใช่พนักงาน active — ได้ภาพรวมแทน
        foreach ([$inactive, $this->admin, $viewer] as $target) {
            $this->actingAs($this->admin)->get(route('reports.projects', ['owner' => $target->id]))
                ->assertOk()
                ->assertViewHas('isTeamView', true);
        }
    }

    public function test_employee_performance_counts_assigned_work_and_lists_accepted_joined_work_separately(): void
    {
        $other = $this->user('user');
        $assigned = $this->task($this->employee, ['job_topic' => 'Primary assigned']);
        $this->task($this->employee, ['job_topic' => 'Pending assigned', 'approval_status' => 'pending']);
        $this->task($this->employee, ['job_topic' => 'Rejected assigned', 'approval_status' => 'rejected']);
        $creatorOnly = $this->task($other, ['job_topic' => 'Creator only', 'created_by' => $this->employee->id]);
        $leaderOnly = $this->task($other, ['job_topic' => 'Leader only', 'leader_user_id' => $this->employee->id]);
        $collaboratorOnly = $this->task($other, ['job_topic' => 'Collaborator only']);
        $this->collaborate($collaboratorOnly, $other, 'accepted');
        $pendingCollaborator = $this->task($other, ['job_topic' => 'Collaborator pending']);
        $this->collaborate($pendingCollaborator, $other, 'pending');
        $this->task($other, ['job_topic' => 'Unrelated task']);

        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());

        $response->assertOk();
        // KPI งานที่รับผิดชอบนับเฉพาะงานของตัวเอง ผู้ร่วมงานที่ตอบรับแล้วขึ้นเป็นแถว "ร่วมทำ" แยกต่างหาก
        // ผู้สร้าง หัวหน้างาน และคำเชิญที่ยังไม่ตอบรับไม่นับทั้งสองแบบ
        $this->assertSame(1, $response->viewData('totalJobs'));
        $this->assertSame(
            [$assigned->job_id => 'owned', $collaboratorOnly->job_id => 'joined'],
            $response->viewData('taskRows')->sortBy('id')->pluck('role.key', 'id')->all()
        );
        $this->assertSame(1, collect($response->viewData('kpis'))->firstWhere('key', 'joined')['value']);
        foreach ([$creatorOnly, $leaderOnly] as $excluded) {
            $response->assertDontSee($excluded->job_topic);
        }
        $response->assertDontSee('Collaborator pending');
    }

    public function test_employee_task_table_labels_each_row_as_owned_or_joined(): void
    {
        $other = $this->user('user');
        $this->task($this->employee, ['job_topic' => 'Owned task']);
        $joined = $this->task($other, ['job_topic' => 'Joined task']);
        $this->collaborate($joined, $other, 'accepted');

        $rows = $this->actingAs($this->admin)->get($this->employeeReportUrl())
            ->assertOk()
            ->assertSee('<th scope="col">บทบาท</th>', false)
            ->assertSee('project-report__role--joined', false)
            ->viewData('taskRows')
            ->keyBy('topic');

        $this->assertEqualsCanonicalizing(['Owned task', 'Joined task'], $rows->keys()->all());
        $this->assertSame('รับผิดชอบ', $rows['Owned task']['role']['label']);
        $this->assertSame('ร่วมทำ', $rows['Joined task']['role']['label']);
    }

    public function test_completed_kpi_counts_work_closed_inside_the_month_even_when_created_earlier(): void
    {
        $this->task($this->employee, [
            'job_topic' => 'Historical completion',
            'job_status' => 4,
            'job_due_at' => '2026-08-20 00:00:00',
            'job_completed_at' => '2026-08-20 16:59:59',
            'created_at' => '2026-05-01 09:00:00',
            'updated_at' => '2026-08-20 16:59:59',
        ]);
        $this->task($this->employee, [
            'job_topic' => 'Completed on the first of September in Bangkok',
            'job_status' => 4,
            'job_completed_at' => '2026-08-31 17:00:01',
            'created_at' => '2026-05-01 09:00:00',
            'updated_at' => '2026-08-31 17:00:01',
        ]);

        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());
        $completed = collect($response->viewData('kpis'))->firstWhere('key', 'completed');

        // 2026-08-31 17:00:01 UTC คือวันที่ 1 กันยายนตามเวลากรุงเทพ จึงไม่ใช่ผลงานของเดือนสิงหาคม
        $this->assertSame(1, $completed['value']);
        $this->assertSame(['Historical completion'], $response->viewData('taskRows')->pluck('topic')->all());
    }

    public function test_employee_export_covers_assigned_and_joined_work_and_keeps_approval_and_period_scope(): void
    {
        $other = $this->user('user');
        $included = $this->task($this->employee, ['job_topic' => 'Employee export included']);
        $this->task($this->employee, ['job_topic' => 'Employee export pending', 'approval_status' => 'pending']);
        $joined = $this->task($other, ['job_topic' => 'Employee export joined']);
        $this->collaborate($joined, $other, 'accepted');
        $this->task($this->employee, ['job_topic' => 'Employee export outside period', 'created_at' => '2026-05-01 09:00:00', 'updated_at' => '2026-05-01 09:00:00']);

        $response = $this->actingAs($this->admin)->get(route('reports.projects.csv', [
            'owner' => $this->employee->id,
            'month' => '2026-08',
        ]));
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString($included->job_topic, $content);
        // กำหนดส่ง 2026-08-31 17:00 UTC แสดงเป็นเวลากรุงเทพ
        $this->assertStringContainsString('01/09/2026 00:00', $content);
        $this->assertStringContainsString('Employee export joined', $content);
        $this->assertStringContainsString('บทบาท', $content);
        $this->assertStringContainsString('ร่วมทำ', $content);
        $this->assertStringContainsString('ผู้เข้าร่วม', $content);
        $this->assertStringNotContainsString('Employee export pending', $content);
        $this->assertStringNotContainsString('Employee export outside period', $content);
    }

    public function test_empty_employee_report_has_safe_zero_values_and_an_empty_table_state(): void
    {
        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());

        $response->assertOk()
            ->assertSee('ยังไม่มีงานโปรเจกต์ในเดือนสิงหาคม 2569')
            ->assertDontSee('แสดง 1 -')
            ->assertDontSee('NaN');
        $this->assertSame(0, $response->viewData('totalJobs'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], array_column($response->viewData('kpis'), 'value'));
    }

    private function user(string $role, bool $active = true, ?Department $department = null, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'department_id' => ($department ?? $this->department)->id,
            'must_change_password' => false,
            'is_active' => $active,
        ], $attributes));
    }

    private function task(User $assignee, array $attributes = []): WorkOrder
    {
        $values = array_merge([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'job_topic' => 'Employee report task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => '2026-08-01 09:00:00',
            'job_due_at' => '2026-08-31 17:00:00',
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-08-10 09:00:00',
        ], $attributes);
        $timestamps = array_intersect_key($values, array_flip(['created_at', 'updated_at']));
        unset($values['created_at'], $values['updated_at']);
        $job = WorkOrder::create($values);
        $job->forceFill($timestamps)->saveQuietly();

        return $job->refresh();
    }

    public function test_employee_table_and_export_name_the_assigner_and_collaborators(): void
    {
        $head = $this->user('user', true, $this->department, ['name' => 'หัวหน้าไอที', 'is_department_head' => true]);
        $joiner = $this->user('user', true, $this->department, ['name' => 'เพื่อนร่วมงาน']);
        $job = $this->task($this->employee, [
            'job_topic' => 'Team named task',
            'created_by' => $head->id,
            'assigned_by' => $head->id,
            'leader_user_id' => $this->employee->id,
        ]);
        $this->collaborate($job, $head, 'accepted', $joiner);

        $page = $this->actingAs($this->admin)->get($this->employeeReportUrl());
        $page->assertOk()
            ->assertSee('ผู้รับผิดชอบ')
            ->assertSee('ผู้เข้าร่วม')
            ->assertSee($this->employee->name)
            ->assertSee('เพื่อนร่วมงาน');

        $row = $page->viewData('taskRows')->firstWhere('topic', 'Team named task');
        $this->assertSame('หัวหน้าไอที', $row['assigner']);
        $this->assertSame([$joiner->name], array_column($row['participants'], 'name'));

        $csv = $this->actingAs($this->admin)->get(route('reports.projects.csv', [
            'owner' => $this->employee->id, 'month' => '2026-08',
        ]))->streamedContent();

        $this->assertStringContainsString('มอบหมายโดย', $csv);
        $this->assertStringContainsString('หัวหน้าไอที', $csv);
        $this->assertStringContainsString('เพื่อนร่วมงาน', $csv);
    }

    public function test_employee_report_folds_subtasks_under_their_parent(): void
    {
        $parent = $this->task($this->employee, ['job_topic' => 'ติดตั้ง CRM']);
        $this->task($this->employee, ['job_topic' => 'สำรวจความต้องการ', 'parent_job_id' => $parent->job_id]);
        $standalone = $this->task($this->employee, ['job_topic' => 'ทำรายงาน']);

        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());

        // หน้าหัวหน้าใช้ชุดคอลัมน์เดียวกับหน้าพนักงาน
        $response->assertOk()
            ->assertSee('<th scope="col">หัวข้อโปรเจกต์</th>', false)
            ->assertSee('1 รายการ')
            ->assertSee('สำรวจความต้องการ');
        $this->assertSame(2, $response->viewData('totalJobs'));
        $this->assertEqualsCanonicalizing(
            [$parent->job_id, $standalone->job_id],
            $response->viewData('taskRows')->pluck('id')->all()
        );
    }

    private function collaborate(WorkOrder $job, User $invitedBy, string $status, ?User $member = null): void
    {
        DB::table('work_order_collaborators')->insert([
            'work_order_id' => $job->job_id,
            'user_id' => ($member ?? $this->employee)->id,
            'added_by' => $invitedBy->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function employeeReportUrl(): string
    {
        return route('reports.projects', [
            'owner' => $this->employee->id,
            'month' => '2026-08',
        ]);
    }
}
