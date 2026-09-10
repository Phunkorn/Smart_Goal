<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AdminReportService;
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
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', AdminReportService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Technology']);
        $this->admin = $this->user('admin');
        $this->employee = $this->user('user');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_selection_lists_only_active_employees_and_supports_department_and_search(): void
    {
        $otherDepartment = Department::create(['department_name' => 'Finance']);
        $matching = $this->user('user', true, $otherDepartment, ['name' => 'สมชาย ใจดี']);
        $this->user('user', false, $otherDepartment, ['name' => 'สมหญิง ปิดใช้งาน']);
        $this->user('viewer', true, $otherDepartment, ['name' => 'สมศรี Viewer']);

        $response = $this->actingAs($this->admin)->get(route('reports.employees.index', [
            'department' => $otherDepartment->id,
            'search' => 'สมชาย',
        ]));

        $response->assertOk()
            ->assertSee($matching->name)
            ->assertDontSee('สมหญิง ปิดใช้งาน')
            ->assertDontSee('สมศรี Viewer');
        $this->assertSame([$matching->id], $response->viewData('employees')->pluck('id')->all());
    }

    public function test_normal_user_cannot_access_selection_employee_report_or_export(): void
    {
        $normal = $this->user('user');

        $this->actingAs($normal)->get(route('reports.employees.index'))->assertForbidden();
        $this->actingAs($normal)->get(route('reports.employee', $this->employee))->assertForbidden();
        $this->actingAs($normal)->get(route('reports.employeeExportCsv', $this->employee))->assertForbidden();
    }

    public function test_department_head_sees_an_own_report_action_but_is_not_listed_with_the_team(): void
    {
        $head = $this->user('user', true, $this->department, [
            'name' => 'หัวหน้า เทคโนโลยี',
            'is_department_head' => true,
        ]);
        $teamMember = $this->user('user', true, $this->department, ['name' => 'สมาชิกในทีม']);

        $selection = $this->actingAs($head)->get(route('reports.employees.index'));

        $selection->assertOk()
            ->assertSee('ดูรายงานของฉัน')
            ->assertSee(route('reports.my'), false)
            ->assertSee($teamMember->name)
            ->assertSee('ไม่รวมตัวคุณ');
        $this->assertEqualsCanonicalizing(
            [$this->employee->id, $teamMember->id],
            $selection->viewData('employees')->pluck('id')->all()
        );
        $this->assertSame(2, $selection->viewData('departments')->first()->active_users_count);
        $grid = substr($selection->getContent(), strpos($selection->getContent(), 'employee-picker__grid'));
        $this->assertStringNotContainsString($head->name, $grid);

        $this->actingAs($head)->get(route('reports.employee', $head))
            ->assertRedirect(route('reports.my'));

        $this->actingAs($head)->get(route('reports.my'))
            ->assertOk()
            ->assertSee('ดูรายงานลูกทีม')
            ->assertSee(route('reports.employees.index'), false)
            ->assertDontSee('employee-operational', false);

        $this->actingAs($head)->get(route('reports.employee', $teamMember))
            ->assertOk()
            ->assertSee('ดูรายงานของฉัน')
            ->assertSee(route('reports.my'), false);
    }

    public function test_viewer_keeps_read_only_access_to_selection_employee_report_and_export(): void
    {
        $viewer = $this->user('viewer');
        $this->task($this->employee, ['job_topic' => 'Viewer readable report task']);

        $this->actingAs($viewer)->get(route('reports.employees.index'))
            ->assertOk()
            ->assertSee('ดูข้อมูลเท่านั้น');
        $this->actingAs($viewer)->get($this->employeeReportUrl())
            ->assertOk()
            ->assertSee('Viewer readable report task');
        $this->actingAs($viewer)->get(route('reports.employeeExportCsv', [
            'user' => $this->employee,
            'period' => 'this_month',
        ]))->assertOk();
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
    }

    public function test_employee_performance_counts_every_approved_contribution_including_joined_work(): void
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
        // งานที่ไปร่วมกับคนอื่นเป็นผลงานของพนักงานด้วย จึงต้องนับเท่ากับหน้ารายงานของฉัน
        $this->assertSame(4, $response->viewData('totalJobs'));
        $this->assertSame(1, $response->viewData('ownedJobs'));
        $this->assertSame(3, $response->viewData('joinedJobs'));
        $this->assertEqualsCanonicalizing(
            [$assigned->job_id, $creatorOnly->job_id, $leaderOnly->job_id, $collaboratorOnly->job_id],
            $response->viewData('taskRows')->pluck('id')->all()
        );
        // คำเชิญที่ยังไม่ตอบรับยังไม่ถือเป็นผลงาน
        $response->assertDontSee('Collaborator pending');
    }

    public function test_employee_task_table_labels_the_role_held_on_each_contribution(): void
    {
        $other = $this->user('user');
        $this->task($this->employee, ['job_topic' => 'Owned task']);
        $joined = $this->task($other, ['job_topic' => 'Joined task']);
        $this->collaborate($joined, $other, 'accepted');

        $rows = $this->actingAs($this->admin)->get($this->employeeReportUrl())
            ->assertOk()
            ->assertSee('ผู้ร่วมงาน')
            ->viewData('taskRows')
            ->keyBy('topic');

        $this->assertSame('owner', $rows['Owned task']['role']['key']);
        $this->assertSame('collaborator', $rows['Joined task']['role']['key']);
    }

    public function test_employee_completed_timeline_and_on_time_metrics_use_completion_date(): void
    {
        $this->task($this->employee, [
            'job_topic' => 'Historical on-time completion',
            'job_status' => 4,
            'job_due_at' => '2026-08-20 00:00:00',
            'job_completed_at' => '2026-08-20 16:59:59',
            'created_at' => '2026-05-01 09:00:00',
            'updated_at' => '2026-08-20 16:59:59',
        ]);
        $this->task($this->employee, [
            'job_topic' => 'Late completion',
            'job_status' => 4,
            'job_due_at' => '2026-08-20 00:00:00',
            'job_completed_at' => '2026-08-20 17:00:01',
        ]);

        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());
        $august = $response->viewData('monthlySummary')->firstWhere('key', '2026-08');

        $this->assertSame(2, $august['completed']);
        $this->assertSame(2, $response->viewData('onTimeEligible'));
        $this->assertSame(1, $response->viewData('onTimeCount'));
        $this->assertSame(50, $response->viewData('onTimeRate'));
    }

    public function test_employee_export_covers_contributions_with_role_column_and_keeps_approval_and_period_scope(): void
    {
        $other = $this->user('user');
        $included = $this->task($this->employee, ['job_topic' => 'Employee export included']);
        $this->task($this->employee, ['job_topic' => 'Employee export pending', 'approval_status' => 'pending']);
        $joined = $this->task($other, ['job_topic' => 'Employee export joined']);
        $this->collaborate($joined, $other, 'accepted');
        $this->task($this->employee, ['job_topic' => 'Employee export outside period', 'created_at' => '2026-05-01 09:00:00', 'updated_at' => '2026-05-01 09:00:00']);

        $response = $this->actingAs($this->admin)->get(route('reports.employeeExportCsv', [
            'user' => $this->employee,
            'period' => 'custom',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]));
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString($included->job_topic, $content);
        $this->assertStringContainsString('Employee export joined', $content);
        $this->assertStringContainsString('บทบาทของฉัน', $content);
        $this->assertStringContainsString('ผู้ร่วมงาน', $content);
        $this->assertStringNotContainsString('Employee export pending', $content);
        $this->assertStringNotContainsString('Employee export outside period', $content);
    }

    public function test_empty_employee_report_has_safe_zero_values_and_chart_states(): void
    {
        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());

        $response->assertOk()
            ->assertSee('data-chart-state="loading"', false)
            ->assertSee('ยังไม่มีข้อมูลในช่วงเวลานี้')
            ->assertDontSee('NaN');
        $this->assertSame(0, $response->viewData('totalJobs'));
        $this->assertSame(0, $response->viewData('onTimeRate'));
        $this->assertSame([0, 0, 0, 0, 0], $response->viewData('chartData')['priority']['values']);
    }

    public function test_employee_report_owns_balanced_chart_kinds_and_keeps_task_table_outside_dashboard(): void
    {
        $html = $this->actingAs($this->admin)->get($this->employeeReportUrl())
            ->assertOk()
            ->assertSee('employee-chart-card--trend', false)
            ->assertSee('employee-chart-card--status', false)
            ->assertSee('employee-chart-card--completed', false)
            ->assertSee('employee-chart-card--priority', false)
            ->assertSee('employee-report__attention', false)
            ->assertSee('data-chart-kind="line"', false)
            ->assertSee('data-chart-kind="bar"', false)
            ->assertSee('data-chart-kind="doughnut"', false)
            ->assertDontSee('employeeOnTimeChart', false)
            ->assertSee('employee-report__ontime', false)
            ->assertSee('report-kpi-band', false)
            ->getContent();

        $dashboardEnd = strpos($html, '</section>', strpos($html, 'employee-report__dashboard'));
        $taskTable = strpos($html, 'employee-report__tasks');

        $this->assertNotFalse($dashboardEnd);
        $this->assertNotFalse($taskTable);
        $this->assertGreaterThan($dashboardEnd, $taskTable);
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
            ->assertSee('เจ้าของงาน')
            ->assertSee('ผู้ร่วมงาน')
            ->assertSee($this->employee->name)
            ->assertSee('เพื่อนร่วมงาน');

        $team = $page->viewData('taskRows')->firstWhere('topic', 'Team named task')['team'];
        $this->assertSame('หัวหน้าไอที', $team['assigner']['name']);
        $this->assertSame([$joiner->name], array_column($team['collaborators'], 'name'));

        $csv = $this->actingAs($this->admin)->get(route('reports.employeeExportCsv', [
            'user' => $this->employee, 'period' => 'custom',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
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
            ->assertSee('<th>หัวข้อโปรเจกต์</th><th>ชื่องาน</th><th>งานย่อย</th>', false)
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
        return route('reports.employee', [
            'user' => $this->employee,
            'period' => 'custom',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);
    }
}
