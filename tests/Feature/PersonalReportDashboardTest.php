<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PersonalReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalReportDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00:00', PersonalReportService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Operations']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_users_can_open_personal_report(): void
    {
        $this->get(route('reports.my'))->assertRedirect(route('login'));
        $this->actingAs($this->user('admin'))->get(route('reports.my'))->assertForbidden();
        $this->actingAs($this->user('viewer'))->get(route('reports.my'))->assertForbidden();
        $this->actingAs($this->user())->get(route('reports.my'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('reports.myExportCsv'))->assertForbidden();
    }

    public function test_scope_includes_assignee_creator_leader_and_accepted_collaborator_only(): void
    {
        $person = $this->user();
        $other = $this->user();
        $assignee = $this->task(['job_topic' => 'Assignee task', 'user_id' => $person->id]);
        $creator = $this->task(['job_topic' => 'Creator task', 'created_by' => $person->id]);
        $leader = $this->task(['job_topic' => 'Leader task', 'leader_user_id' => $person->id]);
        $accepted = $this->task(['job_topic' => 'Accepted collaboration']);
        $accepted->collaborators()->attach($person->id, ['added_by' => $other->id, 'status' => 'accepted']);
        $rejected = $this->task(['job_topic' => 'Rejected collaboration']);
        $rejected->collaborators()->attach($person->id, ['added_by' => $other->id, 'status' => 'rejected']);
        $unrelated = $this->task(['job_topic' => 'Private unrelated task']);

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));
        $ids = $response->viewData('jobs')->pluck('job_id');

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$assignee->job_id, $creator->job_id, $leader->job_id, $accepted->job_id], $ids->all());
        $this->assertFalse($ids->contains($rejected->job_id));
        $this->assertFalse($ids->contains($unrelated->job_id));
    }

    public function test_kpis_due_boundaries_workload_and_task_links_are_actionable(): void
    {
        $person = $this->user();
        $overdue = $this->task(['user_id' => $person->id, 'job_topic' => 'Overdue action', 'job_due_at' => '2026-08-22 12:00:00']);
        $dueSoon = $this->task(['user_id' => $person->id, 'job_topic' => 'Seven day boundary', 'job_due_at' => '2026-08-30 12:00:00']);
        $this->task(['user_id' => $person->id, 'job_topic' => 'Completed', 'job_status' => 4, 'job_due_at' => '2026-08-01 12:00:00']);
        $this->task(['user_id' => $person->id, 'job_topic' => 'September load', 'job_due_at' => '2026-09-10 12:00:00']);

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk()
            ->assertViewHas('totalJobs', 4)
            ->assertViewHas('inProgressJobs', 3)
            ->assertViewHas('dueSoonJobs', 1)
            ->assertViewHas('overdueJobs', 1)
            ->assertSee(route('tasks.show', $overdue->job_id), false)
            ->assertSee(route('tasks.show', $dueSoon->job_id), false);
        $this->assertSame([2, 1, 0], $response->viewData('workloadSummary')->pluck('value')->all());
    }

    public function test_status_priority_search_and_invalid_filters_normalize_safely(): void
    {
        $person = $this->user();
        $statuses = [2, 3, 4, 5, 6];

        foreach (range(1, 5) as $priority) {
            $this->task([
                'user_id' => $person->id,
                'job_topic' => "Matching priority {$priority}",
                'job_priority' => $priority,
                'job_status' => $statuses[$priority - 1],
            ]);
        }

        foreach (range(1, 5) as $priority) {
            $response = $this->actingAs($person)->get(route('reports.my', [
                'period' => 'this_month',
                'priority' => $priority,
                'status' => $statuses[$priority - 1],
                'search' => 'Matching',
            ]));
            $response->assertOk();
            $this->assertSame(1, $response->viewData('totalJobs'));
            $this->assertSame($priority, $response->viewData('filters')['priority']);
            $this->assertSame($statuses[$priority - 1], $response->viewData('filters')['status']);
        }

        $invalid = $this->actingAs($person)->get(route('reports.my', [
            'period' => 'not-real', 'priority' => 99, 'status' => 99, 'year' => 'bad',
        ]));
        $invalid->assertOk();
        $this->assertSame('last_3_months', $invalid->viewData('filters')['period']);
        $this->assertNull($invalid->viewData('filters')['priority']);
        $this->assertNull($invalid->viewData('filters')['status']);

        $retired = $this->actingAs($person)->get(route('reports.my', [
            'period' => 'this_month',
            'status' => 1,
        ]));
        $retired->assertOk();
        $this->assertNull($retired->viewData('filters')['status']);
    }

    public function test_empty_report_renders_all_priority_levels_and_no_nan(): void
    {
        $response = $this->actingAs($this->user())->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk()
            ->assertSee('ไม่พบงานตามตัวกรอง')
            ->assertSee('สำคัญด่วน')
            ->assertSee('ไม่รีบ ไม่มีกำหนด')
            ->assertDontSee('NaN');
        $this->assertSame([0, 0, 0], $response->viewData('chartData')['workload']['values']);
        $this->assertSame([0, 0, 0, 0, 0], $response->viewData('chartData')['priority']['values']);
    }

    public function test_csv_export_keeps_the_same_personal_scope(): void
    {
        $person = $this->user();
        $visible = $this->task(['user_id' => $person->id, 'job_topic' => 'Visible export task']);
        $hidden = $this->task(['job_topic' => 'Hidden export task']);

        $response = $this->actingAs($person)->get(route('reports.myExportCsv', ['year' => 2026]));
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString($visible->job_topic, $content);
        $this->assertStringNotContainsString($hidden->job_topic, $content);
    }

    public function test_late_status_is_selectable_and_labelled_in_the_personal_report(): void
    {
        $person = $this->user();
        $late = $this->task([
            'user_id' => $person->id,
            'job_topic' => 'Late lifecycle task',
            'job_status' => 6,
            'job_due_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($person)->get(route('reports.my', [
            'period' => 'this_month', 'status' => 6,
        ]));

        $response->assertOk();
        $this->assertSame(6, $response->viewData('filters')['status']);
        $this->assertSame(1, $response->viewData('totalJobs'));
        $this->assertSame('late', $response->viewData('taskRows')->firstWhere('id', $late->job_id)['status']['key']);
        $this->assertArrayHasKey(6, $response->viewData('filterOptions')['statuses']);
        $this->assertArrayNotHasKey(1, $response->viewData('filterOptions')['statuses']);
    }

    public function test_late_task_is_exported_with_its_real_status_label(): void
    {
        $person = $this->user();
        $this->task([
            'user_id' => $person->id,
            'job_topic' => 'Late export task',
            'job_status' => 6,
            'job_due_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($person)->get(route('reports.myExportCsv', ['year' => now()->year]));
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString('Late export task', $content);
        $this->assertStringContainsString('ล่าช้า', $content);
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $this->department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function task(array $attributes = []): WorkOrder
    {
        $owner = $this->user();

        return WorkOrder::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $this->department->id,
            'job_topic' => 'Personal report task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => '2026-08-01 09:00:00',
            'job_due_at' => '2026-08-31 17:00:00',
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-08-10 09:00:00',
        ], $attributes));
    }
}
