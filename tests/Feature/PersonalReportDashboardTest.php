<?php

namespace Tests\Feature;

use App\Http\Controllers\MyTaskController;
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

    /**
     * งานที่ไปร่วมกับคนอื่นเป็นผลงานของพนักงาน จึงต้องปรากฏเท่ากันทั้งหน้าที่เจ้าตัวเปิด
     * และหน้าที่หัวหน้า/แอดมินเปิดดู เดิมรายงานรายบุคคลนับเฉพาะ work_orders.user_id
     * ตัวเลขสองหน้าจึงไม่ตรงกันและผลงานที่ไปร่วมหายไปจากมุมมองของผู้บริหาร
     */
    public function test_joined_work_appears_with_the_same_totals_on_both_personal_and_employee_reports(): void
    {
        $person = $this->user();
        $other = $this->user();
        $owned = $this->task(['job_topic' => 'Owned by person', 'user_id' => $person->id]);
        $joined = $this->task(['job_topic' => 'Joined someone else work', 'job_due_at' => '2026-08-22 12:00:00']);
        $joined->collaborators()->attach($person->id, ['added_by' => $other->id, 'status' => 'accepted']);

        $mine = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));
        $mine->assertOk()->assertSee('ผู้ร่วมงาน');
        $this->assertSame(2, $mine->viewData('totalJobs'));
        $this->assertSame(1, $mine->viewData('ownedJobs'));
        $this->assertSame(1, $mine->viewData('joinedJobs'));

        $byAdmin = $this->actingAs($this->user('admin'))->get(route('reports.employee', [
            'user' => $person,
            'period' => 'custom',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]));

        $byAdmin->assertOk();
        $this->assertSame($mine->viewData('totalJobs'), $byAdmin->viewData('totalJobs'));
        $this->assertSame($mine->viewData('joinedJobs'), $byAdmin->viewData('joinedJobs'));
        $this->assertEqualsCanonicalizing(
            [$owned->job_id, $joined->job_id],
            $byAdmin->viewData('taskRows')->pluck('id')->all()
        );
    }

    /**
     * งานที่ปิดในช่วงต้องนับแม้จะสร้างก่อนหน้า มิฉะนั้นผลงานที่เพิ่งส่งมอบจะหายไปจากรายงาน
     */
    public function test_work_completed_inside_the_period_counts_even_when_created_earlier(): void
    {
        $person = $this->user();
        $completed = $this->task([
            'job_topic' => 'Delivered this month',
            'user_id' => $person->id,
            'job_status' => 4,
            'job_completed_at' => '2026-08-12 09:00:00',
            'created_at' => '2026-05-01 09:00:00',
            'updated_at' => '2026-08-12 09:00:00',
        ]);

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk();
        $this->assertTrue($response->viewData('jobs')->pluck('job_id')->contains($completed->job_id));
    }

    /**
     * ป้ายบทบาทอย่างเดียวตรวจไม่ได้ว่ามีการมอบหมายงานกันจริง ตารางจึงต้องบอกชื่อคน
     * ทั้งผู้รับผิดชอบ ผู้มอบหมาย และผู้ร่วมงาน ให้พนักงานเห็นได้เองโดยไม่ต้องพึ่งหัวหน้า
     */
    public function test_personal_contribution_table_names_the_assigner_leader_and_collaborators(): void
    {
        $head = $this->user();
        $staff = $this->user();
        $joiner = $this->user();
        $job = $this->task([
            'job_topic' => 'ติดตั้งระบบ CRM',
            'user_id' => $staff->id,
            'created_by' => $head->id,
            'assigned_by' => $head->id,
            'leader_user_id' => $staff->id,
        ]);
        $job->collaborators()->attach($joiner->id, ['added_by' => $staff->id, 'status' => 'accepted']);

        $staffPage = $this->actingAs($staff)->get(route('reports.my', ['period' => 'this_month']));
        $staffPage->assertOk()
            ->assertSee('ผลงานและทีมที่ร่วมงาน')
            ->assertSee('เจ้าของงาน')
            ->assertSee($staff->name)
            ->assertSee($joiner->name);

        $team = $staffPage->viewData('taskRows')->firstWhere('topic', 'ติดตั้งระบบ CRM')['team'];
        $this->assertSame($staff->name, $team['assignee']['name']);
        $this->assertTrue($team['assignee']['is_me']);
        $this->assertSame($head->name, $team['assigner']['name']);
        $this->assertTrue($team['is_delegated']);
        $this->assertSame([$joiner->name], array_column($team['collaborators'], 'name'));

        // ฝั่งผู้ไปร่วมงานต้องเห็นว่าไปร่วมงานกับใคร และใครเป็นคนมอบหมายงานใบนั้น
        $joinerPage = $this->actingAs($joiner)->get(route('reports.my', ['period' => 'this_month']));
        $joinerTeam = $joinerPage->assertOk()->viewData('taskRows')->firstWhere('topic', 'ติดตั้งระบบ CRM')['team'];
        $this->assertSame('collaborator', $joinerTeam['my_role']['key']);
        $this->assertSame($staff->name, $joinerTeam['assignee']['name']);
        $this->assertFalse($joinerTeam['assignee']['is_me']);
        $this->assertSame($head->name, $joinerTeam['assigner']['name']);
    }

    /**
     * งานที่สร้างเองไม่ได้เกิดจากการมอบหมาย จึงต้องไม่แสดงบรรทัด "มอบหมายโดย" ให้เข้าใจผิด
     */
    public function test_self_created_work_is_not_reported_as_delegated(): void
    {
        $person = $this->user();
        $this->task([
            'job_topic' => 'งานที่ตั้งเอง',
            'user_id' => $person->id,
            'created_by' => $person->id,
            'assigned_by' => $person->id,
            'leader_user_id' => $person->id,
        ]);

        $team = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']))
            ->assertOk()
            ->viewData('taskRows')
            ->firstWhere('topic', 'งานที่ตั้งเอง')['team'];

        $this->assertFalse($team['is_delegated']);
        $this->assertNull($team['assigner']);
    }

    /**
     * ตัวกรองมีอยู่ฝั่งเซิร์ฟเวอร์มานานแล้วแต่หน้าไม่เคยมี UI ให้ใช้
     * ต้องเป็น select ของเบราว์เซอร์และส่งเป็น GET เพื่อให้แชร์ลิงก์และกดย้อนกลับได้
     */
    public function test_personal_report_exposes_period_status_and_priority_dropdowns(): void
    {
        $person = $this->user();

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk()
            ->assertSee('personal-report__filters', false)
            ->assertSee('<select id="personalReportPeriod" name="period">', false)
            ->assertSee('<select id="personalReportStatus" name="status">', false)
            ->assertSee('<select id="personalReportPriority" name="priority">', false)
            ->assertSee('ทุกสถานะ')
            ->assertSee('เสร็จสิ้น')
            ->assertSee('ล่าช้า');
    }

    /**
     * เลือก "ล่าช้า" ต้องได้งานที่ตารางแสดงว่าล่าช้า รวมงานที่ยังเป็นกำลังทำแต่เลยกำหนดแล้ว
     * ไม่ใช่เฉพาะงานที่คอลัมน์ job_status เป็น 6 พอดี
     */
    public function test_late_filter_matches_the_status_shown_in_the_table(): void
    {
        $person = $this->user();
        $flaggedLate = $this->task(['user_id' => $person->id, 'job_topic' => 'ตั้งสถานะล่าช้าไว้', 'job_status' => 6]);
        $overdueDoing = $this->task(['user_id' => $person->id, 'job_topic' => 'กำลังทำแต่เลยกำหนด', 'job_status' => 2, 'job_due_at' => '2026-08-10 17:00:00']);
        $this->task(['user_id' => $person->id, 'job_topic' => 'ยังไม่ถึงกำหนด', 'job_status' => 2]);
        $done = $this->task(['user_id' => $person->id, 'job_topic' => 'ปิดงานแล้ว', 'job_status' => 4, 'job_completed_at' => '2026-08-12 09:00:00']);

        $late = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month', 'status' => 6]));
        $late->assertOk();
        $this->assertEqualsCanonicalizing(
            [$flaggedLate->job_id, $overdueDoing->job_id],
            $late->viewData('jobs')->pluck('job_id')->all()
        );

        $completed = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month', 'status' => 4]));
        $completed->assertOk();
        $this->assertSame([$done->job_id], $completed->viewData('jobs')->pluck('job_id')->all());
    }

    public function test_report_tables_number_their_rows(): void
    {
        $person = $this->user();
        $this->task(['user_id' => $person->id, 'job_topic' => 'งานหนึ่ง']);
        $this->task(['user_id' => $person->id, 'job_topic' => 'งานสอง']);

        $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']))
            ->assertOk()
            ->assertSee('ลำดับ')
            ->assertSee('personal-report__index', false);
    }

    /**
     * งานย่อยถูกเก็บเป็น work_orders อีกใบ ถ้ารายงานไล่แถวตรง ๆ งานใบเดียวจะถูกนับหลายรอบ
     * และงานย่อยจะโผล่เคียงข้างงานแม่เหมือนเป็นงานคนละใบ
     */
    public function test_subtasks_are_folded_under_their_parent_instead_of_counted_separately(): void
    {
        $person = $this->user();
        $parent = $this->task(['user_id' => $person->id, 'job_topic' => 'ติดตั้ง CRM']);
        $this->task(['user_id' => $person->id, 'job_topic' => 'สำรวจความต้องการ', 'parent_job_id' => $parent->job_id]);
        $this->task(['user_id' => $person->id, 'job_topic' => 'ติดตั้งเซิร์ฟเวอร์', 'parent_job_id' => $parent->job_id]);
        $standalone = $this->task(['user_id' => $person->id, 'job_topic' => 'ทำรายงาน']);

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('totalJobs'));
        $this->assertEqualsCanonicalizing(
            [$parent->job_id, $standalone->job_id],
            $response->viewData('taskRows')->pluck('id')->all()
        );

        $parentRow = $response->viewData('taskRows')->firstWhere('id', $parent->job_id);
        $this->assertEqualsCanonicalizing(['สำรวจความต้องการ', 'ติดตั้งเซิร์ฟเวอร์'], $parentRow['subtasks']);
        $this->assertSame([], $response->viewData('taskRows')->firstWhere('id', $standalone->job_id)['subtasks']);
        // งานย่อยอยู่ในคอลัมน์ของตัวเอง ไม่ได้ต่อท้ายชื่องานอีกต่อไป
        $response->assertSee('<th scope="col">หัวข้อโปรเจกต์</th><th scope="col">ชื่องาน</th><th scope="col">งานย่อย</th>', false)
            ->assertSee('2 รายการ')
            // ชื่องานย่อยไม่ถูกพิมพ์ลงในตารางแล้ว แต่ฝากไว้กับปุ่มเพื่อเปิดดูใน modal
            ->assertSee('data-subtask-open', false)
            ->assertDontSee('สำรวจความต้องการ, ติดตั้งเซิร์ฟเวอร์');
    }

    /**
     * ถูกเชิญมาร่วมเฉพาะงานย่อยของโปรเจกต์คนอื่น ผลงานต้องไม่หายไปเพราะงานแม่ไม่ใช่ของเรา
     */
    public function test_a_subtask_only_contribution_is_promoted_to_its_own_row(): void
    {
        $person = $this->user();
        $owner = $this->user();
        $parent = $this->task(['user_id' => $owner->id, 'created_by' => $owner->id, 'leader_user_id' => $owner->id, 'job_topic' => 'โปรเจกต์ของคนอื่น']);
        $child = $this->task([
            'user_id' => $owner->id, 'created_by' => $owner->id, 'leader_user_id' => $owner->id,
            'job_topic' => 'ช่วยทำสไลด์', 'parent_job_id' => $parent->job_id,
        ]);
        $child->collaborators()->attach($person->id, ['added_by' => $owner->id, 'status' => 'accepted']);

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk();
        $this->assertSame([$child->job_id], $response->viewData('taskRows')->pluck('id')->all());
        $this->assertSame(1, $response->viewData('totalJobs'));
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
            ->assertSeeInOrder(['personalPriorityChart', 'personal-report__table', 'Overdue action', 'Seven day boundary'], false)
            ->assertSee(route('tasks.show', $overdue->job_id), false)
            ->assertSee(route('tasks.show', $dueSoon->job_id), false);

        /*
         * กดงานจากรายงานแล้วต้องได้งานใบนั้นเปิดขึ้นมาจริง
         *
         * ของเดิม redirect ไปหน้างานของฉันเปล่า ๆ มุมมองจึงถูกตัดสินด้วยค่าที่จำไว้ใน session
         * พนักงานที่ค้างมุมมอง "ประชุม" ไว้จึงถูกพาไปหน้าประชุมโดยไม่มีงานใบนั้นให้ดูเลย
         */
        $this->actingAs($person)
            ->withSession([MyTaskController::WORKSPACE_VIEW_SESSION_KEY => 'meeting'])
            ->get(route('tasks.show', $overdue->job_id))
            ->assertRedirect(route('mytasks.index', ['open_task' => $overdue->job_id]));

        $this->actingAs($person)
            ->withSession([MyTaskController::WORKSPACE_VIEW_SESSION_KEY => 'meeting'])
            ->get(route('mytasks.index', ['open_task' => $overdue->job_id]))
            ->assertOk()
            ->assertSee('data-view="board"', false)
            // มุมมองที่ผู้ใช้จำไว้ต้องไม่ถูกเขียนทับจากการกดลิงก์เปิดงานครั้งเดียว
            ->assertSessionHas(MyTaskController::WORKSPACE_VIEW_SESSION_KEY, 'meeting');
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

    public function test_personal_report_shows_two_charts_before_the_attention_table(): void
    {
        $response = $this->actingAs($this->user())->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk()
            ->assertSee('สิ่งที่ต้องรีบ')
            ->assertSee('ไม่มีงานที่ต้องรีบจัดการ')
            ->assertDontSee('NaN');

        $this->assertSame(2, substr_count($response->getContent(), 'data-report-chart'));
        $response->assertSeeInOrder(['personalWorkloadChart', 'personalPriorityChart', 'personal-attention-title'], false);
        $response->assertDontSee('personal-report__upcoming', false);
        $response->assertDontSee('งานที่กำลังจะถึง');

        $this->assertSame([0, 0, 0], $response->viewData('chartData')['workload']['values']);
    }

    public function test_attention_table_renders_all_rows_and_pages_ten_at_a_time(): void
    {
        $person = $this->user();

        foreach (range(1, 12) as $index) {
            $this->task([
                'user_id' => $person->id,
                'job_topic' => "Attention task {$index}",
                'job_priority' => 3,
            ]);
        }

        $response = $this->actingAs($person)->get(route('reports.my', ['period' => 'this_month']));

        $response->assertOk()
            ->assertSee('data-page-size="10"', false)
            ->assertSee('data-personal-attention-pager', false)
            ->assertSee('ย้อนกลับ')
            ->assertSee('ถัดไป')
            ->assertSee('Attention task 1')
            ->assertSee('Attention task 12');

        $this->assertCount(12, $response->viewData('attentionJobs'));
        $this->assertSame(12, substr_count($response->getContent(), 'data-personal-attention-row'));
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
