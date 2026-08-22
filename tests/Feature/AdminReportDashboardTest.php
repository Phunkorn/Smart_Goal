<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AdminReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReportDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', AdminReportService::BUSINESS_TIMEZONE));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_admin_and_viewer_can_open_organization_report_but_user_cannot(): void
    {
        $admin = $this->user('admin');
        $viewer = $this->user('viewer');
        $user = $this->user('user');

        $this->actingAs($admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('รายงานภาพรวมองค์กร')
            ->assertSee('reportTrendChart', false)
            ->assertSee('reportStatusChart', false)
            ->assertSee('สำคัญด่วน')
            ->assertSee('ไม่รีบ ไม่มีกำหนด')
            ->assertDontSee('ดูข้อมูลเท่านั้น');

        $this->actingAs($viewer)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('ดูข้อมูลเท่านั้น');

        $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($user)->get(route('reports.my'))->assertOk();
    }

    public function test_department_and_every_task_priority_filter_are_isolated(): void
    {
        $admin = $this->user('admin');
        $firstDepartment = Department::create(['department_name' => 'Operations']);
        $secondDepartment = Department::create(['department_name' => 'Finance']);

        foreach (range(1, 5) as $priority) {
            $this->task($firstDepartment, ['job_topic' => "Priority {$priority}", 'job_priority' => $priority]);
        }
        $this->task($secondDepartment, ['job_topic' => 'Other department', 'job_priority' => 4]);

        foreach (range(1, 5) as $priority) {
            $response = $this->actingAs($admin)->get(route('reports.index', [
                'period' => 'custom',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'department' => $firstDepartment->id,
                'priority' => $priority,
            ]));

            $response->assertOk();
            $this->assertSame(1, $response->viewData('totalJobs'));
            $this->assertSame($priority, $response->viewData('filters')['priority']);
        }
    }

    public function test_period_and_status_filters_use_the_normalized_report_dataset(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Planning']);
        $this->task($department, ['job_topic' => 'August overdue', 'job_due_at' => '2026-08-01 00:00:00']);
        $this->task($department, ['job_topic' => 'July task', 'created_at' => '2026-07-20 00:00:00', 'updated_at' => '2026-07-20 00:00:00']);

        $response = $this->actingAs($admin)->get(route('reports.index', [
            'period' => 'this_month',
            'status' => 'late',
        ]));

        $response->assertOk();
        $this->assertSame('this_month', $response->viewData('filters')['period']);
        $this->assertSame('late', $response->viewData('filters')['status']);
        $this->assertSame(1, $response->viewData('totalJobs'));
    }

    public function test_overdue_uses_bangkok_end_of_day_and_one_definition_across_aggregates(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Technology']);
        $today = $this->task($department, ['job_topic' => 'Due today', 'job_due_at' => '2026-08-22 00:00:00']);
        $past = $this->task($department, ['job_topic' => 'Past due', 'job_due_at' => '2026-08-21 00:00:00']);
        $persistedLate = $this->task($department, ['job_topic' => 'Persisted late', 'job_status' => 6, 'job_due_at' => '2026-08-30 00:00:00']);
        $this->task($department, ['job_topic' => 'Completed past due', 'job_status' => 4, 'job_completed_at' => '2026-08-20 00:00:00', 'job_due_at' => '2026-08-18 00:00:00']);

        $response = $this->actingAs($admin)->get($this->customReportUrl());
        $lateStatus = $response->viewData('statusSummary')->firstWhere('key', 'late');
        $departmentRow = $response->viewData('departmentSummary')->firstWhere('id', $department->id);

        $response->assertOk();
        $this->assertSame(2, $response->viewData('overdueJobs'));
        $this->assertSame(2, $lateStatus['value']);
        $this->assertSame(2, $departmentRow['overdue']);
        $this->assertFalse(app(AdminReportService::class)->isOverdue($today));
        $this->assertTrue(app(AdminReportService::class)->isOverdue($past));
        $this->assertTrue(app(AdminReportService::class)->isOverdue($persistedLate));
    }

    public function test_created_and_completed_metrics_use_their_own_dates_inside_the_filtered_cohort(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Sales']);
        $this->task($department, [
            'job_topic' => 'Completes next month',
            'job_status' => 4,
            'job_completed_at' => '2026-09-02 00:00:00',
        ]);
        $this->task($department, [
            'job_topic' => 'Completed this month',
            'job_status' => 4,
            'job_completed_at' => '2026-08-20 00:00:00',
        ]);

        $response = $this->actingAs($admin)->get($this->customReportUrl());
        $august = $response->viewData('monthlySummary')->firstWhere('key', '2026-08');

        $response->assertOk();
        $this->assertSame(2, $response->viewData('totalJobs'));
        $this->assertSame(1, $response->viewData('completedJobs'));
        $this->assertSame(2, $august['created']);
        $this->assertSame(1, $august['completed']);
    }

    public function test_department_performance_counts_only_active_employees_and_guards_zero_rate(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'People']);
        $this->user('user', $department, true);
        $this->user('user', $department, false);
        $this->user('viewer', $department, true);

        $response = $this->actingAs($admin)->get($this->customReportUrl());
        $row = $response->viewData('departmentSummary')->firstWhere('id', $department->id);

        $this->assertSame(1, $row['employees']);
        $this->assertSame(0, $row['total']);
        $this->assertSame(0, $row['rate']);
    }

    public function test_attention_list_is_approved_incomplete_sorted_and_limited_to_ten(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Service']);

        foreach (range(1, 12) as $offset) {
            $this->task($department, [
                'job_topic' => "Attention {$offset}",
                'job_due_at' => CarbonImmutable::now()->subDays(13 - $offset),
                'job_priority' => (($offset - 1) % 5) + 1,
            ]);
        }
        $this->task($department, ['job_topic' => 'Rejected overdue', 'approval_status' => 'rejected', 'job_due_at' => '2026-08-01 00:00:00']);
        $this->task($department, ['job_topic' => 'Completed overdue', 'job_status' => 4, 'job_completed_at' => '2026-08-20 00:00:00', 'job_due_at' => '2026-08-01 00:00:00']);

        $response = $this->actingAs($admin)->get($this->customReportUrl());
        $attention = $response->viewData('attentionJobs');

        $this->assertCount(10, $attention);
        $this->assertSame('Attention 1', $attention->first()['topic']);
        $this->assertFalse($attention->contains('topic', 'Rejected overdue'));
        $this->assertFalse($attention->contains('topic', 'Completed overdue'));
        $this->assertSame([1, 2, 3, 4, 5], $attention->pluck('priority.value')->unique()->sort()->values()->all());
    }

    public function test_soft_deleted_jobs_are_excluded_and_service_query_count_stays_bounded(): void
    {
        $department = Department::create(['department_name' => 'Quality']);
        $this->task($department, ['job_topic' => 'Visible']);
        $deleted = $this->task($department, ['job_topic' => 'Deleted']);
        $deleted->delete();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $data = app(AdminReportService::class)->build(Request::create('/reports', 'GET', [
            'period' => 'custom',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $data['totalJobs']);
        $this->assertLessThanOrEqual(4, $queryCount);
    }

    public function test_personal_report_scope_remains_separate_from_the_organization_report(): void
    {
        $department = Department::create(['department_name' => 'Customer Success']);
        $employee = $this->user('user', $department);
        $other = $this->user('user', $department);
        $related = $this->task($department, [
            'job_topic' => 'Created by employee',
            'user_id' => $other->id,
            'created_by' => $employee->id,
            'leader_user_id' => $other->id,
        ]);
        $unrelated = $this->task($department, [
            'job_topic' => 'Unrelated work',
            'user_id' => $other->id,
            'created_by' => $other->id,
            'leader_user_id' => $other->id,
        ]);

        $response = $this->actingAs($employee)->get(route('reports.my', ['year' => 2026]));
        $jobIds = $response->viewData('jobs')->pluck('job_id');

        $response->assertOk();
        $this->assertTrue($jobIds->contains($related->job_id));
        $this->assertFalse($jobIds->contains($unrelated->job_id));
    }

    private function user(string $role, ?Department $department = null, bool $active = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => $active,
        ]);
    }

    private function task(Department $department, array $attributes = []): WorkOrder
    {
        $assignee = $this->user('user', $department);

        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $department->id,
            'job_topic' => 'Report task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_progress' => 50,
            'job_start_at' => '2026-08-01 00:00:00',
            'job_due_at' => '2026-08-30 00:00:00',
            'created_at' => '2026-08-10 00:00:00',
            'updated_at' => '2026-08-10 00:00:00',
        ], $attributes));
    }

    private function customReportUrl(): string
    {
        return route('reports.index', [
            'period' => 'custom',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);
    }
}
