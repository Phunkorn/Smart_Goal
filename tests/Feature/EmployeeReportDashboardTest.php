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

    public function test_employee_performance_counts_only_approved_primary_assignee_scope(): void
    {
        $other = $this->user('user');
        $assigned = $this->task($this->employee, ['job_topic' => 'Primary assigned']);
        $this->task($this->employee, ['job_topic' => 'Pending assigned', 'approval_status' => 'pending']);
        $this->task($this->employee, ['job_topic' => 'Rejected assigned', 'approval_status' => 'rejected']);
        $this->task($other, ['job_topic' => 'Creator only', 'created_by' => $this->employee->id]);
        $this->task($other, ['job_topic' => 'Leader only', 'leader_user_id' => $this->employee->id]);
        $collaboratorOnly = $this->task($other, ['job_topic' => 'Collaborator only']);
        DB::table('work_order_collaborators')->insert([
            'work_order_id' => $collaboratorOnly->job_id,
            'user_id' => $this->employee->id,
            'added_by' => $other->id,
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->task($other, ['job_topic' => 'Sibling project task']);
        $this->task($other, ['job_topic' => 'Unrelated task']);

        $response = $this->actingAs($this->admin)->get($this->employeeReportUrl());

        $response->assertOk();
        $this->assertSame(1, $response->viewData('totalJobs'));
        $this->assertSame([$assigned->job_id], $response->viewData('taskRows')->pluck('id')->all());
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

    public function test_employee_export_uses_assignee_approval_and_period_scope(): void
    {
        $other = $this->user('user');
        $included = $this->task($this->employee, ['job_topic' => 'Employee export included']);
        $this->task($this->employee, ['job_topic' => 'Employee export pending', 'approval_status' => 'pending']);
        $this->task($other, ['job_topic' => 'Employee export creator only', 'created_by' => $this->employee->id]);
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
        $this->assertStringNotContainsString('Employee export pending', $content);
        $this->assertStringNotContainsString('Employee export creator only', $content);
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
            ->assertSee('employee-chart-card--ontime', false)
            ->assertSee('employee-chart-card--priority', false)
            ->assertSee('employee-report__attention', false)
            ->assertSee('data-chart-kind="line"', false)
            ->assertSee('data-chart-kind="bar"', false)
            ->assertSee('data-chart-kind="doughnut"', false)
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
            'job_progress' => 50,
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
