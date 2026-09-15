<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderUpdate;
use App\Models\WorkOrderUpdateAttachment;
use App\Services\ProjectReportService;
use App\Support\ReportMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * รายงานโปรเจกต์ประจำเดือน — ภาพรวมและรายบุคคลในหน้าเดียว
 *
 * เทสต์สำคัญของไฟล์นี้คือขอบเขต: หัวหน้าต้องไม่เห็นคนนอกแผนกด้วยการแก้ URL
 * พนักงานทั่วไปต้องไม่ได้สิทธิ์เพิ่ม และ CSV ต้องใช้ขอบเขตกับแถวชุดเดียวกับหน้าจอ
 */
class ProjectReportFlowTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $sales;

    private User $head;

    private User $itMember;

    private User $salesMember;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', ReportMetrics::BUSINESS_TIMEZONE));

        $this->it = Department::create(['department_name' => 'IT']);
        $this->sales = Department::create(['department_name' => 'Sales']);
        $this->head = $this->user($this->it, ['name' => 'หัวหน้าไอที', 'is_department_head' => true]);
        $this->itMember = $this->user($this->it, ['name' => 'พนักงานไอที']);
        $this->salesMember = $this->user($this->sales, ['name' => 'พนักงานเซลส์']);
        $this->admin = $this->user(null, ['role' => 'admin', 'name' => 'ผู้ดูแลระบบ']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_project_card_opens_the_project_report_directly(): void
    {
        $viewer = $this->user(null, ['role' => 'viewer']);

        foreach ([$this->admin, $viewer, $this->head] as $actor) {
            $this->actingAs($actor)->get(route('reports.index'))
                ->assertOk()
                ->assertSee('รายงานโปรเจกต์')
                ->assertSee(route('reports.projects'), false)
                ->assertDontSee('ดูรายงานรายบุคคล')
                // การ์ดภาพรวมองค์กรถูกยกเลิกแล้ว
                ->assertDontSee('ดูภาพรวมองค์กร')
                ->assertDontSee('report-choice--organization', false)
                ->assertDontSee(route('reports.employees.index'), false);

            // ลิงก์เดิมของรายงานภาพรวมองค์กรพาไปรายงานโปรเจกต์
            $this->actingAs($actor)->get('/reports/organization')->assertRedirect('/reports/projects');
        }

        $this->actingAs($this->admin)->get('/reports/organization/export.csv')->assertRedirect('/reports/projects/export.csv');

        // พนักงานทั่วไปได้การ์ดเดียวกันที่พาไปรายงานโปรเจกต์หน้าเดียวกัน (ขอบเขตเฉพาะตัวเอง)
        $this->actingAs($this->itMember)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('รายงานโปรเจกต์')
            ->assertSee(route('reports.projects'), false)
            ->assertDontSee('/my-reports', false);
    }

    public function test_a_head_lands_on_their_department_overview_with_a_scoped_employee_dropdown(): void
    {
        $this->task($this->itMember, 'งานของพนักงานไอที');
        $this->task($this->salesMember, 'งานของเซลส์');

        $response = $this->actingAs($this->head)->get(route('reports.projects'))
            ->assertOk()
            ->assertViewIs('reports.projects.index')
            ->assertSee('รายงานโปรเจกต์ประจำเดือน')
            ->assertSee('ภาพรวมแผนก IT')
            ->assertSee('id="projectReportOwner"', false)
            ->assertSee($this->itMember->name)
            ->assertSee('งานของพนักงานไอที')
            ->assertDontSee($this->salesMember->name)
            ->assertDontSee('งานของเซลส์');

        $this->assertTrue($response->viewData('isTeamView'));
        $this->assertEqualsCanonicalizing(
            [$this->head->id, $this->itMember->id],
            $response->viewData('owners')->pluck('id')->all()
        );
    }

    public function test_a_head_selects_a_member_or_themselves_on_the_same_page(): void
    {
        $this->task($this->itMember, 'งานของพนักงานไอที');
        $this->task($this->head, 'งานของหัวหน้าเอง');

        $member = $this->actingAs($this->head)->get(route('reports.projects', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertViewIs('reports.projects.index')
            ->assertSee('งานของพนักงานไอที')
            ->assertDontSee('งานของหัวหน้าเอง')
            ->assertSee('<option value="'.$this->itMember->id.'" selected>', false);
        $this->assertTrue($member->viewData('owner')->is($this->itMember));

        $self = $this->actingAs($this->head)->get(route('reports.projects', ['owner' => $this->head->id]))
            ->assertOk()
            ->assertSee('งานของหัวหน้าเอง')
            ->assertSee('แสดงงานที่คุณรับผิดชอบ');
        $this->assertTrue($self->viewData('owner')->is($this->head));
    }

    public function test_a_head_cannot_open_another_department_by_editing_the_url(): void
    {
        $this->task($this->salesMember, 'งานลับของเซลส์');

        foreach ([
            ['owner' => $this->salesMember->id],
            ['owner' => $this->salesMember->id, 'department' => $this->sales->id],
        ] as $query) {
            $response = $this->actingAs($this->head)->get(route('reports.projects', $query))
                ->assertOk()
                ->assertDontSee('งานลับของเซลส์')
                ->assertDontSee($this->salesMember->name);

            $this->assertTrue($response->viewData('isTeamView'));

            $csv = $this->actingAs($this->head)->get(route('reports.projects.csv', $query));
            $csv->assertOk();
            $this->assertStringNotContainsString('งานลับของเซลส์', $csv->streamedContent());
        }

        // เส้นทางเดิมยังปฏิเสธที่ server เหมือนเดิม
        $this->actingAs($this->head)->get(route('reports.employee', $this->salesMember))->assertForbidden();
        $this->actingAs($this->head)->get(route('reports.employeeExportCsv', $this->salesMember))->assertForbidden();
    }

    public function test_admin_picks_any_active_employee_and_invalid_owners_fall_back_to_the_overview(): void
    {
        $viewer = $this->user($this->sales, ['role' => 'viewer', 'name' => 'ผู้ชมเซลส์']);
        $inactive = $this->user($this->sales, ['is_active' => false, 'name' => 'พนักงานลาออก']);

        $all = $this->actingAs($this->admin)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('ภาพรวมทุกแผนก')
            ->assertDontSee('ผู้ชมเซลส์')
            ->assertDontSee('พนักงานลาออก');
        $this->assertEqualsCanonicalizing(
            [$this->head->id, $this->itMember->id, $this->salesMember->id],
            $all->viewData('owners')->pluck('id')->all()
        );

        $picked = $this->actingAs($this->admin)->get(route('reports.projects', ['owner' => $this->salesMember->id]))->assertOk();
        $this->assertTrue($picked->viewData('owner')->is($this->salesMember));

        foreach ([$this->admin->id, $viewer->id, $inactive->id, 999999] as $owner) {
            $response = $this->actingAs($this->admin)->get(route('reports.projects', ['owner' => $owner]))->assertOk();
            $this->assertTrue($response->viewData('isTeamView'));
        }
    }

    public function test_viewer_reads_the_project_report_read_only(): void
    {
        $viewer = $this->user(null, ['role' => 'viewer']);
        $this->task($this->itMember, 'งานที่ผู้ชมอ่านได้');

        $this->actingAs($viewer)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('ดูข้อมูลเท่านั้น');
        $this->actingAs($viewer)->get(route('reports.projects', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertSee('งานที่ผู้ชมอ่านได้');
        $this->actingAs($viewer)->get(route('reports.projects.csv', ['owner' => $this->itMember->id]))->assertOk();
    }

    /**
     * พนักงานทั่วไปใช้รายงานโปรเจกต์หน้าเดียวกับหัวหน้า (โหมดรายบุคคล สองกราฟ) แต่ขอบเขตเป็นตัวเองเสมอ
     * แก้ ?owner= หรือ ?department= ใน URL ไม่มีผลทั้งหน้าหลัก หน้ารายละเอียด และ CSV
     */
    public function test_a_plain_member_uses_the_same_report_but_only_ever_sees_their_own_work(): void
    {
        $colleague = $this->user($this->it, ['name' => 'เพื่อนร่วมแผนก']);
        $this->task($this->itMember, 'งานของฉันเอง');
        $this->task($colleague, 'งานของเพื่อน');

        foreach ([[], ['owner' => $colleague->id, 'department' => $this->sales->id]] as $query) {
            $response = $this->actingAs($this->itMember)->get(route('reports.projects', [...$query, 'month' => '2026-08']))
                ->assertOk()
                ->assertViewIs('reports.projects.index')
                ->assertViewHas('isPersonalReport', true)
                ->assertViewHas('isTeamView', false)
                ->assertViewHas('owner', fn (User $owner) => $owner->is($this->itMember))
                ->assertSee('งานของฉันเอง')
                ->assertDontSee('งานของเพื่อน')
                ->assertDontSee('id="projectReportOwner"', false)
                ->assertDontSee('ภาพรวมแผนก');
            $this->assertSame([$this->itMember->id], $response->viewData('owners')->pluck('id')->all());
        }

        $this->actingAs($this->itMember)->get(route('reports.projects.details', ['owner' => $colleague->id, 'month' => '2026-08']))
            ->assertOk()
            ->assertSee('งานของฉันเอง')
            ->assertDontSee('งานของเพื่อน');

        $csv = $this->actingAs($this->itMember)->get(route('reports.projects.csv', ['owner' => $colleague->id, 'month' => '2026-08']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('งานของฉันเอง', $csv);
        $this->assertStringNotContainsString('งานของเพื่อน', $csv);

        // ลิงก์รายงานของฉันเดิมพาไปหน้าใหม่
        $this->actingAs($this->itMember)->get('/my-reports')->assertRedirect('/reports/projects');
        $this->actingAs($this->itMember)->get('/my-reports/export.csv')->assertRedirect('/reports/projects/export.csv');

        $this->actingAs($this->itMember)->get(route('reports.employees.index'))->assertForbidden();
        $this->actingAs($this->itMember)->get(route('reports.employee', $colleague))->assertForbidden();
    }

    /**
     * รายงานโปรเจกต์นับเฉพาะงานที่คนนั้นเป็น "ผู้รับผิดชอบ" (รวมงานย่อยที่ถูกมอบให้)
     *
     * งานที่เป็นแค่ผู้ร่วมงาน (ไปจอยโปรเจกต์/งานของคนอื่น) ผู้สร้าง หรือหัวหน้างานของคนอื่น ไม่ขึ้นในรายงาน
     * งานเหล่านั้นยังเปิดดูได้แบบอ่านอย่างเดียวจากบอร์ด แต่ไม่ใช่ผลงานในรายงานของคนนั้น
     * และใช้นิยามเดียวกันทั้งหน้าที่พนักงานเปิดเองและหน้าที่หัวหน้า/admin เปิดดู
     */
    /**
     * รายงานของคนหนึ่งมีทั้งงานที่รับผิดชอบและงานที่ไปร่วมทำ (ผู้ร่วมงานที่ตอบรับแล้ว)
     * ผู้สร้างหรือหัวหน้างานที่ไม่ได้ร่วมทำไม่นับ ทุกมุมมอง (ตัวเอง หัวหน้า admin) เห็นชุดเดียวกัน
     */
    public function test_a_members_report_lists_assigned_and_joined_work_only(): void
    {
        $person = $this->itMember;
        $other = $this->user($this->it, ['name' => 'เจ้าของงานอื่น']);
        $assignee = $this->task($person, 'Assignee task', ['created_by' => $other->id, 'leader_user_id' => $other->id]);
        $othersParent = $this->task($other, 'Parent of someone else');
        $mySubtask = $this->task($person, 'Subtask assigned to me', ['parent_job_id' => $othersParent->job_id, 'created_by' => $other->id]);
        $creator = $this->task($other, 'Creator task', ['created_by' => $person->id, 'leader_user_id' => $other->id]);
        $leader = $this->task($other, 'Leader task', ['leader_user_id' => $person->id, 'created_by' => $other->id]);
        $accepted = $this->task($other, 'Accepted collaboration');
        $accepted->collaborators()->attach($person->id, ['added_by' => $other->id, 'status' => 'accepted']);
        $pendingInvite = $this->task($other, 'Pending invitation');
        $pendingInvite->collaborators()->attach($person->id, ['added_by' => $other->id, 'status' => 'pending']);
        $this->task($person, 'Pending assignment', ['approval_status' => 'pending']);
        $this->task($other, 'Private unrelated task');

        foreach ([$person, $this->head, $this->admin] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('reports.projects', ['owner' => $person->id, 'month' => '2026-08']))
                ->assertOk();
            $roles = $response->viewData('taskRows')->pluck('role.key', 'id')->all();

            $this->assertEqualsCanonicalizing([$assignee->job_id, $mySubtask->job_id, $accepted->job_id], array_keys($roles), 'มุมมองของ '.$viewer->name);
            $this->assertSame('owned', $roles[$assignee->job_id]);
            $this->assertSame('owned', $roles[$mySubtask->job_id]);
            $this->assertSame('joined', $roles[$accepted->job_id]);
            $kpis = collect($response->viewData('kpis'))->pluck('value', 'key');
            $this->assertSame(2, $kpis['owned']);
            $this->assertSame(1, $kpis['joined']);
        }

        // ภาพรวมแผนกนับด้วยนิยามเดียวกัน — ทุกงานอยู่ใต้ผู้รับผิดชอบของมันครั้งเดียว
        // (งานย่อยของฉันถูกยุบใต้งานแม่ของเพื่อนร่วมแผนก เพราะงานแม่ก็อยู่ในขอบเขตแผนก)
        // งานที่ร่วมทำกันภายในแผนกเป็น "รับผิดชอบ" ของเจ้าของงาน จึงไม่มีแถว "ร่วมทำ" ซ้ำ
        $overview = $this->actingAs($this->head)->get(route('reports.projects', ['month' => '2026-08']))->viewData('taskRows');
        $unrelated = WorkOrder::query()->where('job_topic', 'Private unrelated task')->value('job_id');
        $this->assertEqualsCanonicalizing(
            [$assignee->job_id, $othersParent->job_id, $creator->job_id, $leader->job_id, $accepted->job_id, $pendingInvite->job_id, $unrelated],
            $overview->pluck('id')->all()
        );
        $this->assertSame(['owned'], $overview->pluck('role.key')->unique()->values()->all());
    }

    public function test_old_employee_routes_redirect_into_the_project_report(): void
    {
        $inactive = $this->user($this->it, ['is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('reports.employees.index', ['department' => $this->sales->id, 'search' => 'ใครก็ได้']))
            ->assertRedirect(route('reports.projects', ['department' => $this->sales->id]));

        // หัวหน้าถูกบังคับแผนกอยู่แล้ว ค่าแผนกที่ส่งมาจึงไม่ถูกส่งต่อ
        $this->actingAs($this->head)
            ->get(route('reports.employees.index', ['department' => $this->sales->id]))
            ->assertRedirect(route('reports.projects'));

        $this->actingAs($this->admin)
            ->get(route('reports.employee', ['user' => $this->itMember, 'period' => 'this_year']))
            ->assertRedirect(route('reports.projects', ['period' => 'this_year', 'owner' => $this->itMember->id]));

        $this->actingAs($this->head)
            ->get(route('reports.employee', $this->head))
            ->assertRedirect(route('reports.projects', ['owner' => $this->head->id]));

        $this->actingAs($this->admin)
            ->get(route('reports.employeeExportCsv', ['user' => $this->itMember, 'month' => '2026-07']))
            ->assertRedirect(route('reports.projects.csv', ['owner' => $this->itMember->id, 'month' => '2026-07']));

        $this->actingAs($this->admin)->get(route('reports.employee', $inactive))->assertNotFound();
        $this->actingAs($this->admin)->get(route('reports.employeeExportCsv', $inactive))->assertNotFound();
    }

    public function test_the_page_follows_the_monthly_layout_with_seven_kpis_filters_and_a_task_table(): void
    {
        $html = $this->actingAs($this->admin)->get(route('reports.projects', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertSee('id="projectReportMonth"', false)
            ->assertSee('Export CSV')
            ->assertSee('ค้นหา')
            ->assertSee('รายการงานโปรเจกต์ประจำเดือน')
            ->assertSee('เรียงตาม :')
            ->getContent();

        $this->assertSame(
            ['projects', 'owned', 'joined', 'subtasks', 'cross', 'active', 'completed'],
            array_column($this->viewOf($this->itMember)->viewData('kpis'), 'key')
        );

        $positions = array_map(fn (string $needle) => strpos($html, $needle), [
            'project-report__header', 'project-report__kpis', 'project-report__filter-card', 'project-report__table-card',
        ]);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        foreach (['วันที่', 'หัวข้อโปรเจกต์', 'ชื่องาน', 'งานย่อย', 'ผู้รับผิดชอบ', 'ผู้เข้าร่วม', 'บทบาท', 'ขอบเขตงาน', 'สถานะ'] as $column) {
            $this->assertStringContainsString('<th scope="col">'.$column.'</th>', $html);
        }
        // แถวมีทั้งงานที่รับผิดชอบและงานที่ร่วมทำ จึงมีคอลัมน์และตัวกรองบทบาท
        $this->assertStringContainsString('id="projectReportRole"', $html);
    }

    public function test_kpis_and_rows_describe_scope_subtasks_and_status(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->itMember->id, 'name' => 'ระบบ CRM']);
        $owned = $this->task($this->itMember, 'ติดตั้ง CRM', ['work_order_list_id' => $project->id]);
        $this->task($this->itMember, 'สำรวจความต้องการ', ['parent_job_id' => $owned->job_id]);
        $this->task($this->itMember, 'ปิดงานแล้ว', ['job_status' => 4, 'job_completed_at' => '2026-08-15 09:00:00']);
        $this->task($this->itMember, 'เลยกำหนด', ['job_due_at' => '2026-08-20 17:00:00']);
        // งานในแผนกเซลส์ที่มอบให้คนไอทีรับผิดชอบ = งานข้ามแผนกของคนไอที
        $this->task($this->itMember, 'งานของเซลส์ที่มอบให้ไอที', ['created_by' => $this->salesMember->id, 'department_id' => $this->sales->id]);
        // งานที่ไปช่วย (ผู้ร่วมงาน) ขึ้นเป็นแถว "ร่วมทำ" แต่ไม่ถูกนับใน KPI เดิมทั้งหกใบ
        $joined = $this->task($this->salesMember, 'งานของเซลส์ที่ไอทีไปช่วย');
        $this->collaborate($joined, $this->itMember);

        $response = $this->viewOf($this->itMember);
        $kpis = collect($response->viewData('kpis'))->pluck('value', 'key');

        $this->assertSame(
            ['projects' => 1, 'owned' => 4, 'joined' => 1, 'subtasks' => 1, 'cross' => 1, 'active' => 3, 'completed' => 1],
            $kpis->all()
        );

        $rows = $response->viewData('taskRows')->keyBy('topic');
        $this->assertSame('ระบบ CRM', $rows['ติดตั้ง CRM']['project']['name']);
        $this->assertSame(['สำรวจความต้องการ'], $rows['ติดตั้ง CRM']['subtasks']);
        $this->assertSame('owned', $rows['ติดตั้ง CRM']['role']['key']);
        $this->assertSame('internal', $rows['ติดตั้ง CRM']['scope']['key']);
        $this->assertSame('cross', $rows['งานของเซลส์ที่มอบให้ไอที']['scope']['key']);
        $this->assertSame('Sales', $rows['งานของเซลส์ที่มอบให้ไอที']['department']['name']);
        $this->assertSame('joined', $rows['งานของเซลส์ที่ไอทีไปช่วย']['role']['key']);
        $this->assertSame('ร่วมทำ', $rows['งานของเซลส์ที่ไอทีไปช่วย']['role']['label']);
        $this->assertSame('cross', $rows['งานของเซลส์ที่ไอทีไปช่วย']['scope']['key']);
        $this->assertSame('Sales', $rows['งานของเซลส์ที่ไอทีไปช่วย']['scope']['department']['name']);
        $this->assertSame('late', $rows['เลยกำหนด']['status']['key']);
        $this->assertSame('done', $rows['ปิดงานแล้ว']['status']['key']);

        $response->assertSee('project-report__status--late', false)
            ->assertSee('ในจำนวนนี้ล่าช้า 1 งาน')
            ->assertSee('ข้ามแผนก');
    }

    public function test_the_team_overview_marks_cross_department_work_and_joined_work_of_its_members(): void
    {
        $joined = $this->task($this->salesMember, 'งานเซลส์ที่ลูกทีมไปร่วม');
        $this->collaborate($joined, $this->itMember);
        $this->task($this->itMember, 'งานเซลส์ที่ลูกทีมรับผิดชอบ', ['created_by' => $this->salesMember->id, 'department_id' => $this->sales->id]);
        $this->task($this->itMember, 'งานในแผนก');

        $rows = $this->actingAs($this->head)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('id="projectReportRole"', false)
            ->viewData('taskRows')
            ->keyBy('topic');

        // งานของแผนกอื่นที่ลูกทีมไปร่วม — ขึ้นครั้งเดียว บอกชื่อลูกทีมที่ร่วมทำ และมองจากมุมของลูกทีมคนนั้น
        $this->assertSame('joined', $rows['งานเซลส์ที่ลูกทีมไปร่วม']['role']['key']);
        $this->assertSame('ร่วมทำโดย พนักงานไอที', $rows['งานเซลส์ที่ลูกทีมไปร่วม']['role']['detail']);
        $this->assertSame('cross', $rows['งานเซลส์ที่ลูกทีมไปร่วม']['scope']['key']);
        $this->assertSame('owned', $rows['งานในแผนก']['role']['key']);
        $this->assertSame('cross', $rows['งานเซลส์ที่ลูกทีมรับผิดชอบ']['scope']['key']);
        $this->assertSame('internal', $rows['งานในแผนก']['scope']['key']);
    }

    public function test_the_month_selects_created_or_completed_work_and_ignores_invalid_months(): void
    {
        $this->task($this->itMember, 'งานเดือนสิงหาคม');
        $this->task($this->itMember, 'งานเดือนกรกฎาคม', ['created_at' => '2026-07-05 09:00:00']);
        $this->task($this->itMember, 'สร้างมิถุนายนปิดกรกฎาคม', [
            'created_at' => '2026-06-05 09:00:00', 'job_status' => 4, 'job_completed_at' => '2026-07-31 16:59:00',
        ]);

        $july = $this->viewOf($this->itMember, ['month' => '2026-07']);
        $this->assertSame('2026-07', $july->viewData('monthKey'));
        $this->assertEqualsCanonicalizing(
            ['งานเดือนกรกฎาคม', 'สร้างมิถุนายนปิดกรกฎาคม'],
            $july->viewData('taskRows')->pluck('topic')->all()
        );
        $this->assertSame(1, collect($july->viewData('kpis'))->firstWhere('key', 'completed')['value']);

        foreach (['2026-09', '2025-12', 'nonsense'] as $month) {
            $response = $this->viewOf($this->itMember, ['month' => $month]);
            $this->assertSame('2026-08', $response->viewData('monthKey'));
            $this->assertSame(['งานเดือนสิงหาคม'], $response->viewData('taskRows')->pluck('topic')->all());
        }
    }

    public function test_filters_narrow_the_table_but_not_the_kpis_and_invalid_values_are_ignored(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->itMember->id, 'name' => 'เว็บไซต์ใหม่']);
        $this->task($this->itMember, 'ออกแบบหน้าแรก', ['work_order_list_id' => $project->id]);
        $this->task($this->itMember, 'สรุปงบประมาณ', ['job_status' => 3]);
        $this->task($this->itMember, 'ช่วยเซลส์ทำใบเสนอราคา', ['created_by' => $this->salesMember->id, 'department_id' => $this->sales->id]);

        $topics = fn (array $query) => $this->viewOf($this->itMember, $query)->viewData('taskRows')->pluck('topic')->all();

        $this->assertSame(['ออกแบบหน้าแรก'], $topics(['project' => (string) $project->id]));
        $this->assertEqualsCanonicalizing(['สรุปงบประมาณ', 'ช่วยเซลส์ทำใบเสนอราคา'], $topics(['project' => 'none']));
        $this->assertSame(['สรุปงบประมาณ'], $topics(['status' => 'review']));
        // ขอบเขตงาน: ในแผนก / งานข้ามแผนกทั้งหมด / ข้ามแผนก · ชื่อแผนก — บอกทั้งว่าข้ามแผนกไหม และข้ามไปแผนกไหน
        $this->assertSame(
            ['internal' => 'ในแผนก', 'cross' => 'งานข้ามแผนกทั้งหมด', 'cross:'.$this->sales->id => 'ข้ามแผนก · Sales'],
            $this->viewOf($this->itMember)->viewData('filterOptions')['scopes']
        );
        $this->assertEqualsCanonicalizing(['ออกแบบหน้าแรก', 'สรุปงบประมาณ'], $topics(['scope' => 'internal']));
        $this->assertSame(['ช่วยเซลส์ทำใบเสนอราคา'], $topics(['scope' => 'cross']));
        $this->assertSame(['ช่วยเซลส์ทำใบเสนอราคา'], $topics(['scope' => 'cross:'.$this->sales->id]));
        $this->assertSame(['ออกแบบหน้าแรก'], $topics(['q' => 'เว็บไซต์']));

        $filtered = $this->viewOf($this->itMember, ['status' => 'review']);
        $this->assertTrue($filtered->viewData('hasActiveFilters'));
        $this->assertSame(3, $filtered->viewData('totalJobs'));

        $ignored = $this->viewOf($this->itMember, ['status' => 'bogus', 'role' => 'boss', 'project' => '999', 'department' => '999', 'scope' => 'cross:999', 'sort' => 'drop']);
        $this->assertFalse($ignored->viewData('hasActiveFilters'));
        $this->assertCount(3, $ignored->viewData('taskRows'));
        $this->assertSame('date_desc', $ignored->viewData('filters')['sort']);
    }

    public function test_the_main_table_previews_ten_rows_and_links_to_every_row_on_the_details_page(): void
    {
        foreach (range(1, 22) as $day) {
            $this->task($this->itMember, sprintf('งานวันที่ %02d', $day), [
                'created_at' => sprintf('2026-08-%02d 09:00:00', $day),
            ]);
        }

        $query = ['owner' => $this->itMember->id, 'month' => '2026-08'];
        $main = $this->viewOf($this->itMember, $query);
        $html = $main->getContent();

        $this->assertSame(10, substr_count($html, 'data-project-row='));
        $this->assertStringContainsString('งานวันที่ 22', $html);
        $this->assertStringNotContainsString('งานวันที่ 12', $html);
        $main->assertSee('แสดง 10 จาก 22 รายการ')
            ->assertSee('ดูรายละเอียดทั้งหมด')
            ->assertSee(route('reports.projects.details', $query))
            // ปุ่มดูรายละเอียดรายแถวถูกแทนด้วยปุ่มเดียวท้ายตาราง
            ->assertDontSee('project-report__button--small', false);

        $details = $this->actingAs($this->admin)->get(route('reports.projects.details', [...$query, 'sort' => 'date_asc', 'page' => 2]))
            ->assertOk()
            ->assertViewIs('reports.projects.details')
            ->assertSee('แสดง 21 - 22 จาก 22 รายการ');
        $this->assertSame(['งานวันที่ 21', 'งานวันที่ 22'], $details->viewData('pageRows')->pluck('topic')->all());

        // หน้าที่เกินจำนวนจริงถูกดึงกลับมาหน้าสุดท้าย
        $this->assertSame(2, $this->actingAs($this->admin)
            ->get(route('reports.projects.details', [...$query, 'page' => 99]))
            ->viewData('pageRows')->currentPage());
    }

    public function test_participants_open_a_name_list_and_task_names_are_plain_text(): void
    {
        $job = $this->task($this->itMember, 'งานทำเป็นทีม');
        $this->collaborate($job, $this->head);
        $this->collaborate($job, $this->salesMember);

        $html = $this->viewOf($this->itMember)
            ->assertSee('2 คน')
            ->assertSee('data-participants-trigger', false)
            ->assertSee('ผู้เข้าร่วม 2 คน')
            ->assertDontSee('project-report__avatars', false)
            // ชื่องานไม่มีลิงก์ไปหน้างาน ทั้งหน้าหลักและหน้ารายละเอียด
            ->assertDontSee(route('tasks.show', $job->job_id))
            ->getContent();

        $panel = substr($html, strpos($html, 'data-participants-panel'));
        $this->assertStringContainsString($this->head->name, $panel);
        $this->assertStringContainsString($this->salesMember->name, $panel);

        $this->actingAs($this->admin)->get(route('reports.projects.details', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertDontSee(route('tasks.show', $job->job_id));
    }

    public function test_the_details_page_scope_follows_the_same_permission_contract(): void
    {
        $this->task($this->salesMember, 'งานลับของเซลส์');

        // คนนอกแผนกกลายเป็นภาพรวมแผนก ไม่มีข้อมูลของคนนั้นหลุดออกมา
        $response = $this->actingAs($this->head)->get(route('reports.projects.details', ['owner' => $this->salesMember->id]))
            ->assertOk()
            ->assertDontSee('งานลับของเซลส์');
        $this->assertTrue($response->viewData('isTeamView'));

        // พนักงานทั่วไปเปิดหน้ารายละเอียดได้ แต่เป็นงานของตัวเอง
        $this->actingAs($this->itMember)->get(route('reports.projects.details'))
            ->assertOk()
            ->assertViewHas('isPersonalReport', true);
    }

    public function test_the_department_overview_shows_four_charts_and_a_person_shows_two(): void
    {
        $this->task($this->itMember, 'งานของพนักงานไอที');

        $team = $this->actingAs($this->head)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('id="projectTrendChart"', false)
            ->assertSee('id="projectStatusChart"', false)
            ->assertSee('งานล่าช้าที่ต้องติดตาม')
            ->assertSee('id="projectBreakdownChart"', false)
            // ภาพรวมแผนกกดดูรายละเอียดทั้งหมดได้
            ->assertSee('ดูรายละเอียดทั้งหมด');
        $this->assertNotNull($team->viewData('chartData')['late']);

        $this->actingAs($this->head)->get(route('reports.projects.details'))
            ->assertOk()
            ->assertViewIs('reports.projects.details')
            ->assertSee('งานของพนักงานไอที');

        $person = $this->actingAs($this->head)->get(route('reports.projects', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertSee('id="projectTrendChart"', false)
            ->assertSee('id="projectStatusChart"', false)
            ->assertDontSee('งานล่าช้าที่ต้องติดตาม')
            ->assertDontSee('id="projectBreakdownChart"', false)
            ->assertSee('ดูรายละเอียดทั้งหมด');
        $this->assertNull($person->viewData('chartData')['late']);
        $this->assertNull($person->viewData('chartData')['breakdown']);
    }

    public function test_the_csv_holds_the_same_filtered_rows_and_order_as_the_table(): void
    {
        $this->task($this->itMember, 'งานแรก', ['created_at' => '2026-08-02 09:00:00']);
        $this->task($this->itMember, '=งานสูตร', ['created_at' => '2026-08-03 09:00:00', 'job_status' => 3]);
        $this->task($this->itMember, 'งานรอตรวจ', ['created_at' => '2026-08-04 09:00:00', 'job_status' => 3]);
        $this->task($this->itMember, 'งานเดือนก่อน', ['created_at' => '2026-07-04 09:00:00', 'job_status' => 3]);

        $query = ['owner' => $this->itMember->id, 'month' => '2026-08', 'status' => 'review'];
        $page = $this->actingAs($this->admin)->get(route('reports.projects', $query))
            ->assertSee(route('reports.projects.csv', $query));
        $response = $this->actingAs($this->admin)->get(route('reports.projects.csv', $query));
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString(
            'smart-goals-projects-'.$this->itMember->id.'-2026-08.csv',
            (string) $response->headers->get('content-disposition')
        );

        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($content, 3)))));
        $this->assertSame(['ลำดับ', 'เลขงาน', 'วันที่', 'หัวข้อโปรเจกต์', 'ชื่องาน', 'งานย่อย', 'ผู้รับผิดชอบ', 'ผู้เข้าร่วม', 'บทบาท', 'หัวหน้าโปรเจกต์', 'มอบหมายโดย', 'ขอบเขตงาน', 'แผนกที่เกี่ยวข้อง', 'สถานะ', 'กำหนดส่ง', 'วันที่เสร็จ'], $lines[0]);
        $this->assertSame(
            $page->viewData('taskRows')->pluck('topic')->map(fn (string $topic) => str_starts_with($topic, '=') ? "'".$topic : $topic)->all(),
            array_column(array_slice($lines, 1), 4)
        );
        $this->assertSame(["'=งานสูตร", 'งานรอตรวจ'], array_reverse(array_column(array_slice($lines, 1), 4)));
    }

    /*
     * หน้ารายละเอียดใช้ตรวจผลงานจากหลักฐานจริง: ไฟล์แนบของงานและไฟล์ของงานย่อย (ไม่รวมไฟล์ในคอมเมนต์)
     * แถวย่อเป็นป้ายจำนวน/ไอคอนนับไฟล์ รายละเอียดเต็มอยู่ใน template ของกล่อง
     * ลิงก์ต้องออกเฉพาะงานที่ผู้ดูเปิดไฟล์ได้จริงตาม WorkOrderPolicy (นโยบายเดียวกับ MediaController)
     */
    public function test_the_details_page_lists_task_and_subtask_files_the_viewer_may_open(): void
    {
        $parent = $this->task($this->itMember, 'ติดตั้งระบบกล้อง', [
            'job_start_at' => '2026-08-05 02:00:00',
            'job_status' => 4,
            'job_completed_at' => '2026-08-12 09:30:00',
        ]);
        $taskFile = $this->attach($parent, 'ใบส่งมอบงาน.pdf', 'application/pdf');
        $commentFile = $this->commentAttach($parent, 'รูปหน้างาน.jpg');
        $child = $this->task($this->itMember, 'เดินสายสัญญาณ', ['parent_job_id' => $parent->job_id]);
        $childFile = $this->attach($child, 'ผังสาย.png', 'image/png');

        // งานในแผนกเซลส์ที่ลูกทีมรับผิดชอบ และมีงานย่อยที่คนเซลส์ทำ — หัวหน้าไอทีต้องเห็นไฟล์ครบ
        $joined = $this->task($this->itMember, 'งานเซลส์ที่ไอทีไปช่วย', [
            'created_by' => $this->salesMember->id,
            'leader_user_id' => $this->salesMember->id,
            'department_id' => $this->sales->id,
        ]);
        $salesChild = $this->task($this->salesMember, 'งานย่อยของเซลส์', ['parent_job_id' => $joined->job_id]);
        $this->attach($salesChild, 'เอกสารภายในเซลส์.pdf', 'application/pdf');

        $response = $this->actingAs($this->head)
            ->get(route('reports.projects.details', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertSee('ไฟล์แนบงาน')
            ->assertSee('ไฟล์แนบงานย่อย')
            ->assertSee('<th scope="col">ผู้รับผิดชอบ</th>', false)
            ->assertSee('<th scope="col">บทบาท</th>', false)
            ->assertSee('<th scope="col">ขอบเขตงาน</th>', false)
            ->assertSee('project-report__scope--cross', false)
            ->assertSee('project-report__scope--internal', false)
            ->assertSee('data-evidence-open="'.$parent->job_id.'"', false)
            // ช่องงานย่อยใช้เซลล์ชุดเดียวกับตารางหน้าหลัก จำนวนงานย่อยที่เสร็จยังอยู่ใน subtask_summary และกล่องหลักฐาน
            ->assertSee('data-subtask-open', false)
            ->assertSee('1 รายการ')
            ->assertSee('ใบส่งมอบงาน.pdf')
            ->assertSee(route('media.task-attachments.show', $taskFile))
            ->assertSee(route('media.task-attachments.show', $childFile))
            // ไฟล์ในคอมเมนต์ไม่ใช่หลักฐานของรายงานนี้
            ->assertDontSee('รูปหน้างาน.jpg')
            ->assertDontSee(route('media.comment-attachments.show', $commentFile))
            ->assertSee('5 ส.ค. 2569 · 09:00 น.')
            ->assertSee('12 ส.ค. 2569 · 16:30 น.')
            ->assertDontSee('ไม่มีสิทธิ์ดูไฟล์')
            ->assertSee('เอกสารภายในเซลส์.pdf');

        $rows = $response->viewData('pageRows')->keyBy('topic');
        $camera = $rows['ติดตั้งระบบกล้อง'];
        $this->assertSame(['documents' => 1, 'images' => 0], $camera['evidence']['counts']);
        $this->assertSame(['documents' => 0, 'images' => 1, 'locked' => 0], $camera['child_counts']);
        $this->assertSame(['total' => 1, 'done' => 0], $camera['subtask_summary']);
        $this->assertFalse($rows['งานเซลส์ที่ไอทีไปช่วย']['children'][0]['evidence']['locked']);
        $this->assertSame(0, $rows['งานเซลส์ที่ไอทีไปช่วย']['child_counts']['locked']);
        // เห็นได้ แต่ยังแก้งานของแผนกอื่นไม่ได้
        $this->assertTrue(Gate::forUser($this->head)->allows('view', $salesChild));
        $this->assertFalse(Gate::forUser($this->head)->allows('work', $salesChild));

        // คนที่ไม่มีสิทธิ์ดูงานใบนั้นจริง ๆ ยังไม่เห็นแม้ชื่อไฟล์
        $locked = app(ProjectReportService::class)->withEvidence($response->viewData('pageRows')->getCollection(), $this->salesMember)->keyBy('topic');
        $this->assertTrue($locked['ติดตั้งระบบกล้อง']['evidence']['locked']);
        $this->assertSame([], $locked['ติดตั้งระบบกล้อง']['evidence']['files']);

        // ชื่องานย่อยและชื่อไฟล์ไม่ถูกกางลงในแถว — แถวสูงบรรทัดเดียวเท่ากันทุกแถว
        $html = $response->getContent();
        $tableEnd = strpos($html, '</table>');
        // ชื่อไฟล์อยู่ใน template ของกล่องหลักฐานเท่านั้น ไม่มีแม้ใน attribute
        $this->assertStringNotContainsString('ใบส่งมอบงาน.pdf', substr($html, 0, $tableEnd));
        // ชื่องานย่อยฝากมากับปุ่มของเซลล์ร่วมเป็น JSON (data-subtask-names) แบบเดียวกับตารางหน้าหลัก
        // จึงต้องไม่ปรากฏเป็นข้อความที่มองเห็นในแถว
        $this->assertStringNotContainsString('เดินสายสัญญาณ', strip_tags(substr($html, 0, $tableEnd)));
        $this->assertStringContainsString('data-subtask-names=', substr($html, 0, $tableEnd));

        // ลิงก์ที่หน้าออกให้ต้องเปิดได้จริงด้วยสิทธิ์เดียวกัน
        $this->assertTrue(Gate::forUser($this->head)->allows('view', $child));

        // viewer อ่านไฟล์แนบงานได้ แต่ไม่เห็นไฟล์ในคอมเมนต์ (viewComments ปฏิเสธ viewer)
        $viewer = $this->user(null, ['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('reports.projects.details', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->assertSee(route('media.task-attachments.show', $taskFile))
            ->assertDontSee(route('media.comment-attachments.show', $commentFile));
    }

    public function test_the_trend_chart_counts_each_month_of_the_year_with_the_monthly_report_rules(): void
    {
        // กรกฎาคม: สร้าง 2 งาน (ใบหนึ่งมีงานย่อยที่ต้องถูกยุบ) ปิด 1 งาน
        $july = $this->task($this->itMember, 'งานกรกฎาคม', ['created_at' => '2026-07-03 09:00:00']);
        $this->task($this->itMember, 'งานย่อยกรกฎาคม', ['created_at' => '2026-07-04 09:00:00', 'parent_job_id' => $july->job_id]);
        $this->task($this->itMember, 'ปิดในกรกฎาคม', ['created_at' => '2026-07-05 09:00:00', 'job_status' => 4, 'job_completed_at' => '2026-07-20 09:00:00']);
        // สร้างมิถุนายน ปิดสิงหาคม: นับเป็นงานได้รับของมิถุนายน และงานเสร็จของสิงหาคม
        $this->task($this->itMember, 'ข้ามเดือน', ['created_at' => '2026-06-10 09:00:00', 'job_status' => 4, 'job_completed_at' => '2026-08-12 09:00:00']);
        $this->task($this->itMember, 'งานสิงหาคม', ['created_at' => '2026-08-10 09:00:00']);
        // งานของคนอื่นและงานที่ยังไม่อนุมัติไม่นับ
        $this->task($this->salesMember, 'งานของเซลส์', ['created_at' => '2026-07-03 09:00:00']);
        $this->task($this->itMember, 'รออนุมัติ', ['created_at' => '2026-07-03 09:00:00', 'approval_status' => 'pending']);

        $response = $this->viewOf($this->itMember)
            ->assertSee('แนวโน้มงานรายเดือน ปี 2569')
            ->assertSee('id="projectTrendChart"', false)
            ->assertSee('id="projectStatusChart"', false)
            // กราฟทั้งหน้าเป็นแท่ง ไม่มีเส้นและโดนัทแล้ว
            ->assertDontSee('data-chart-kind="line"', false)
            ->assertDontSee('data-chart-kind="doughnut"', false);
        $trend = $response->viewData('chartData')['trend'];

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'], $trend['keys']);
        $this->assertSame([0, 0, 0, 0, 0, 1, 2, 1], $trend['created']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 1, 1], $trend['completed']);
        $this->assertSame(7, $trend['selected']);

        // เดือนที่เลือกต้องตรงกับ KPI "เสร็จสิ้นแล้ว" ของเดือนเดียวกัน
        $this->assertSame(
            $trend['completed'][7],
            collect($response->viewData('kpis'))->firstWhere('key', 'completed')['value']
        );

        // เลือกเดือนย้อนหลัง แกนสิ้นสุดที่เดือนนั้น
        $july = $this->viewOf($this->itMember, ['month' => '2026-07'])->viewData('chartData')['trend'];
        $this->assertCount(7, $july['keys']);
        $this->assertSame([0, 0, 0, 0, 0, 1, 2], $july['created']);
    }

    public function test_the_status_chart_lists_every_status_with_counts_and_shares(): void
    {
        foreach (range(1, 7) as $index) {
            $this->task($this->itMember, "งาน {$index}", [
                'job_status' => $index <= 2 ? 4 : 2,
                'job_completed_at' => $index <= 2 ? '2026-08-12 09:00:00' : null,
            ]);
        }

        $response = $this->viewOf($this->itMember);
        $status = $response->viewData('chartData')['status'];

        $this->assertSame(['done', 'doing', 'review', 'paused', 'late'], $status['keys']);
        $this->assertSame([2, 5, 0, 0, 0], $status['values']);
        $this->assertSame(7, $status['total']);
        // ตัวเลขชุดเดียวกับกราฟอยู่ในตารางสำหรับโปรแกรมอ่านหน้าจอ
        $response->assertSee('71%')->assertSee('29%');
    }

    public function test_the_late_card_lists_the_five_longest_overdue_tasks_without_progress_bars_or_links(): void
    {
        foreach (range(1, 6) as $day) {
            $this->task($this->itMember, "ล่าช้าวันที่ {$day}", ['job_due_at' => sprintf('2026-08-%02d 10:00:00', $day)]);
        }
        $this->task($this->itMember, 'ยังไม่ถึงกำหนด');
        $this->task($this->itMember, 'ปิดแล้วแม้เลยกำหนด', [
            'job_due_at' => '2026-08-01 10:00:00', 'job_status' => 4, 'job_completed_at' => '2026-08-15 09:00:00',
        ]);

        // การ์ดนี้อยู่ในภาพรวมแผนกเท่านั้น
        $response = $this->actingAs($this->head)->get(route('reports.projects'))->assertOk();
        $late = $response->viewData('chartData')['late'];

        $this->assertSame(6, $late['total']);
        $this->assertSame(
            ['ล่าช้าวันที่ 1', 'ล่าช้าวันที่ 2', 'ล่าช้าวันที่ 3', 'ล่าช้าวันที่ 4', 'ล่าช้าวันที่ 5'],
            array_column($late['items'], 'topic')
        );
        // กำหนดส่ง 1 ส.ค. 17:00 น. (กรุงเทพ) ถึงวันนี้ 22 ส.ค. = เลย 21 วัน
        $this->assertSame([21, 20, 19, 18, 17], array_column($late['items'], 'days'));

        $first = WorkOrder::query()->where('job_topic', 'ล่าช้าวันที่ 1')->value('job_id');
        $html = $response->assertSee('เลยกำหนดนานที่สุด 5 รายการ จากงานล่าช้าทั้งหมด 6 งาน')
            ->assertSee('เลย 21 วัน')
            ->assertSee('กำหนด 1 ส.ค. 2569')
            ->assertDontSee('project-report__late-meter', false)
            ->assertDontSee(route('tasks.show', $first))
            ->getContent();

        $listStart = strpos($html, 'project-report__late-list');
        $this->assertNotFalse($listStart);
        $listEnd = strpos($html, '</ol>', $listStart);
        $this->assertNotFalse($listEnd);
        $card = substr($html, $listStart, $listEnd - $listStart);
        $this->assertStringContainsString('ล่าช้าวันที่ 5', $card);
        $this->assertStringNotContainsString('ล่าช้าวันที่ 6', $card);
        $this->assertStringNotContainsString('<a ', $card);

        $empty = $this->actingAs($this->head)->get(route('reports.projects', ['month' => '2026-07']))->assertOk();
        $this->assertSame([], $empty->viewData('chartData')['late']['items']);
        $empty->assertSee('ไม่มีงานล่าช้าในเดือนกรกฎาคม 2569');
    }

    public function test_the_department_fourth_chart_counts_work_each_member_closed_this_month(): void
    {
        $this->task($this->itMember, 'ปิดสิงหาคม 1', ['job_status' => 4, 'job_completed_at' => '2026-08-05 09:00:00']);
        // สร้างก่อนหน้าแต่ปิดในเดือนนี้ ยังนับเป็นผลงานของเดือนนี้
        $this->task($this->itMember, 'ปิดสิงหาคม 2', ['created_at' => '2026-07-01 09:00:00', 'job_status' => 4, 'job_completed_at' => '2026-08-15 09:00:00']);
        $this->task($this->itMember, 'ยังทำอยู่');
        $this->task($this->head, 'หัวหน้าปิดงาน', ['job_status' => 4, 'job_completed_at' => '2026-08-15 09:00:00']);
        // ปิดตอน 17:00:01 UTC ของ 31 ส.ค. คือ 1 ก.ย. ตามเวลากรุงเทพ ไม่ใช่ผลงานของสิงหาคม
        $this->task($this->head, 'หัวหน้าปิดข้ามเดือน', ['job_status' => 4, 'job_completed_at' => '2026-08-31 17:00:01']);
        // คนนอกแผนกไม่อยู่ในภาพรวมของหัวหน้าไอที
        $this->task($this->salesMember, 'เซลส์ปิดงาน', ['job_status' => 4, 'job_completed_at' => '2026-08-10 09:00:00']);

        $response = $this->actingAs($this->head)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('งานที่ปิดได้รายคน')
            ->assertDontSee('งานค้างรายคน')
            ->assertDontSee('งานแยกตามโปรเจกต์');
        $breakdown = $response->viewData('chartData')['breakdown'];

        $response->assertSee('data-chart-kind="doughnut"', false)
            ->assertSee('class="project-report__donut-legend"', false)
            ->assertSee('67%')
            ->assertSee('33%');
        $this->assertSame([$this->itMember->name, $this->head->name], $breakdown['labels']);
        $this->assertSame([2, 1], $breakdown['values']);
        $this->assertSame(3, $breakdown['total']);
        $this->assertSame(['#1d4ed8', '#ea580c'], $breakdown['colors']);
        // ผลรวมทุกแท่งเท่ากับ KPI "เสร็จสิ้นแล้ว" ของภาพรวมแผนก
        $this->assertSame(
            array_sum($breakdown['values']),
            collect($response->viewData('kpis'))->firstWhere('key', 'completed')['value']
        );
    }

    public function test_the_member_doughnut_shows_the_top_five_and_folds_the_rest_into_others(): void
    {
        foreach (range(1, 6) as $number) {
            $person = $this->user($this->it, ['name' => 'คนที่ '.$number]);
            // คนที่ 1 ปิดได้ 6 งาน คนที่ 6 ปิดได้ 1 งาน
            foreach (range(1, 7 - $number) as $index) {
                $this->task($person, "งานปิด {$number}-{$index}", ['job_status' => 4, 'job_completed_at' => '2026-08-12 09:00:00']);
            }
        }

        $breakdown = $this->actingAs($this->head)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('อื่น ๆ (1 คน)')
            ->viewData('chartData')['breakdown'];

        $this->assertSame(['คนที่ 1', 'คนที่ 2', 'คนที่ 3', 'คนที่ 4', 'คนที่ 5', 'อื่น ๆ (1 คน)'], $breakdown['labels']);
        $this->assertSame([6, 5, 4, 3, 2, 1], $breakdown['values']);
        $this->assertSame(21, $breakdown['total']);
        $this->assertSame([...ProjectReportService::MEMBER_COLORS, ProjectReportService::OTHERS_COLOR], $breakdown['colors']);
    }

    /*
     * ลูกทีมเป็นแค่ผู้สร้างงานที่มอบหมายให้แผนกอื่น — งานนี้ขึ้นในภาพรวมแผนก หัวหน้าจึงต้องเปิดดูได้
     */
    public function test_a_head_can_open_work_their_member_created_or_leads_in_another_department(): void
    {
        $created = $this->task($this->salesMember, 'ไอทีมอบหมายให้เซลส์', ['created_by' => $this->itMember->id]);
        $led = $this->task($this->salesMember, 'ไอทีเป็นหัวหน้างานของเซลส์', ['leader_user_id' => $this->itMember->id]);
        $unrelated = $this->task($this->salesMember, 'งานเซลส์ล้วน');

        $this->assertTrue(Gate::forUser($this->head)->allows('view', $created));
        $this->assertTrue(Gate::forUser($this->head)->allows('view', $led));
        $this->assertFalse(Gate::forUser($this->head)->allows('view', $unrelated));
        $this->assertFalse(Gate::forUser($this->head)->allows('work', $created));
    }

    private function attach(WorkOrder $job, string $name, string $type): JobImage
    {
        return JobImage::create([
            'job_id' => $job->job_id,
            'file_path' => 'job-attachments/'.$job->job_id.'/'.md5($name),
            'original_name' => $name,
            'file_type' => $type,
            'uploaded_by' => $job->user_id,
        ]);
    }

    private function commentAttach(WorkOrder $job, string $name): WorkOrderUpdateAttachment
    {
        $comment = WorkOrderUpdate::create([
            'work_order_id' => $job->job_id,
            'user_id' => $job->user_id,
            'note' => 'แนบรูปหน้างาน',
            'is_comment' => true,
        ]);

        return WorkOrderUpdateAttachment::create([
            'work_order_update_id' => $comment->id,
            'file_path' => 'comment-attachments/'.$job->job_id.'/'.md5($name),
            'original_name' => $name,
            'file_type' => 'image/jpeg',
            'byte_size' => 10,
            'uploaded_by' => $job->user_id,
        ]);
    }

    /**
     * admin เลือกภาพรวมทีละแผนกได้ — หัวหน้าแผนกยังถูกล็อกแผนกตัวเองเหมือนเดิม
     *
     * เดิม admin ได้แค่ "ภาพรวมทุกแผนก" จึงดูภาพรวมของแผนกเดียวไม่ได้เลย
     */
    public function test_admin_can_open_a_single_department_overview(): void
    {
        $this->task($this->itMember, 'งานของพนักงานไอที');
        $this->task($this->salesMember, 'งานของเซลส์');

        $all = $this->actingAs($this->admin)->get(route('reports.projects'))
            ->assertOk()
            ->assertSee('ภาพรวมทุกแผนก')
            ->assertSee('value="department-'.$this->it->id.'"', false)
            ->assertSee('ภาพรวมแผนก IT')
            ->assertSee('ภาพรวมแผนก Sales')
            ->assertSee('งานของเซลส์');
        $this->assertNull($all->viewData('overviewDepartmentId'));

        $it = $this->actingAs($this->admin)->get(route('reports.projects', ['owner' => 'department-'.$this->it->id]))
            ->assertOk()
            ->assertSee('value="department-'.$this->it->id.'" selected>ภาพรวมแผนก IT', false)
            ->assertSee('งานของพนักงานไอที')
            ->assertDontSee('งานของเซลส์');
        $this->assertTrue($it->viewData('isTeamView'));
        $this->assertSame($this->it->id, $it->viewData('overviewDepartmentId'));
        $this->assertSame('แผนก IT', $it->viewData('scopeName'));
        // ดร็อปดาวน์ยังมีทุกคน admin กระโดดไปดูใครก็ได้จากภาพรวมแผนก
        $this->assertEqualsCanonicalizing(
            [$this->head->id, $this->itMember->id, $this->salesMember->id],
            $it->viewData('owners')->pluck('id')->all()
        );
        // ลิงก์ต่อจากหน้านี้ (CSV แบ่งหน้า เรียงลำดับ) พกแผนกไปด้วย ไม่หลุดกลับเป็นภาพรวมทุกแผนก
        $this->assertSame($this->it->id, $it->viewData('query')['department']);

        $csv = $this->actingAs($this->admin)->get(route('reports.projects.csv', $it->viewData('query')))->assertOk()->streamedContent();
        $this->assertStringContainsString('งานของพนักงานไอที', $csv);
        $this->assertStringNotContainsString('งานของเซลส์', $csv);

        // แผนกที่ไม่มีอยู่ถอยกลับเป็นภาพรวมทุกแผนก
        $missing = $this->actingAs($this->admin)->get(route('reports.projects', ['department' => 999999]))->assertOk();
        $this->assertNull($missing->viewData('overviewDepartmentId'));

        // หัวหน้าแผนกส่งแผนกอื่นมาไม่มีผล และไม่มีตัวเลือกภาพรวมแผนกอื่นให้เห็นเลย
        $head = $this->actingAs($this->head)->get(route('reports.projects', ['owner' => 'department-'.$this->sales->id]))
            ->assertOk()
            ->assertDontSee('งานของเซลส์')
            ->assertDontSee('value="department-', false);
        $this->assertSame('แผนก IT', $head->viewData('scopeName'));
    }

    /**
     * ช่วงวันที่ที่กำหนดเองขับทั้งแถว KPI กราฟ และลิงก์ต่อ ๆ ไป
     *
     * กราฟแสดงเฉพาะเดือนที่ช่วงครอบคลุมและนับเฉพาะวันที่อยู่ในช่วง ผลรวมจึงเท่ากับ KPI ของช่วงเดียวกัน
     * ค่าที่ไม่ถูกต้องทุกแบบถอยกลับเป็นรายเดือน
     */
    public function test_a_custom_date_range_drives_the_rows_kpis_trend_and_links(): void
    {
        $this->task($this->itMember, 'ต้นเดือนกรกฎาคม', ['created_at' => '2026-07-02 09:00:00']);
        $this->task($this->itMember, 'กลางเดือนกรกฎาคม', ['created_at' => '2026-07-20 09:00:00']);
        $this->task($this->itMember, 'ปิดต้นสิงหาคม', [
            'created_at' => '2026-06-01 09:00:00', 'job_status' => 4, 'job_completed_at' => '2026-08-03 10:00:00',
        ]);
        $this->task($this->itMember, 'ปลายเดือนสิงหาคม', ['created_at' => '2026-08-20 09:00:00']);

        $response = $this->viewOf($this->itMember, ['from' => '2026-07-15', 'to' => '2026-08-05'])
            ->assertSee('ช่วง 15 ก.ค. – 5 ส.ค. 2569')
            ->assertSee('value="custom" selected', false);

        $this->assertTrue($response->viewData('isCustomPeriod'));
        $this->assertSame('custom', $response->viewData('monthKey'));
        $this->assertEqualsCanonicalizing(
            ['กลางเดือนกรกฎาคม', 'ปิดต้นสิงหาคม'],
            $response->viewData('taskRows')->pluck('topic')->all()
        );
        $this->assertSame(1, collect($response->viewData('kpis'))->firstWhere('key', 'completed')['value']);

        $trend = $response->viewData('chartData')['trend'];
        $this->assertSame(['2026-07', '2026-08'], $trend['keys']);
        $this->assertSame([1, 0], $trend['created']);
        $this->assertSame([0, 1], $trend['completed']);
        $this->assertTrue($trend['all_selected']);

        // ลิงก์ต่อไปพกช่วงวันที่ ไม่ใช่เดือน
        $this->assertSame(
            ['owner' => $this->itMember->id, 'from' => '2026-07-15', 'to' => '2026-08-05'],
            $response->viewData('query')
        );
        $csv = $this->actingAs($this->admin)->get(route('reports.projects.csv', $response->viewData('query')))->assertOk();
        $this->assertStringContainsString(
            'smart-goals-projects-'.$this->itMember->id.'-20260715-20260805.csv',
            (string) $csv->headers->get('content-disposition')
        );

        foreach ([
            ['from' => '2026-08-05', 'to' => '2026-07-15'],   // กลับด้าน
            ['from' => '2026-02-30', 'to' => '2026-03-02'],   // วันที่ไม่มีจริง
            ['from' => '2025-01-01', 'to' => '2026-08-01'],   // ยาวเกิน 1 ปี
            ['from' => 'yesterday', 'to' => '2026-08-01'],
            ['from' => '2026-07-15'],
        ] as $invalid) {
            $fallback = $this->viewOf($this->itMember, $invalid);
            $this->assertFalse($fallback->viewData('isCustomPeriod'));
            $this->assertSame('2026-08', $fallback->viewData('monthKey'));
        }
    }

    /**
     * กด "ดูรายละเอียดทั้งหมด" แล้วต้องเจอตารางเดียวกับที่เพิ่งอ่าน — คอลัมน์ชุดเดียวกันในลำดับเดียวกัน
     * แล้วจึงต่อท้ายด้วยคอลัมน์หลักฐานของหน้ารายละเอียด
     */
    public function test_the_details_page_starts_with_exactly_the_main_table_columns(): void
    {
        $this->task($this->itMember, 'งานเดียวกันทั้งสองหน้า');
        $headers = function (string $html): array {
            preg_match_all('/<th scope="col"[^>]*>(.*?)<\/th>/su', $html, $matches);

            return array_map(fn (string $cell): string => trim(strip_tags($cell)), $matches[1]);
        };

        $main = $headers($this->viewOf($this->itMember)->getContent());
        $details = $headers($this->actingAs($this->admin)
            ->get(route('reports.projects.details', ['owner' => $this->itMember->id]))
            ->assertOk()
            ->getContent());

        // ทั้งสองหน้าเป็นตารางเดียวกันทุกคอลัมน์ รวมหลักฐาน และสถานะอยู่ท้ายสุดหลังคอลัมน์ "เสร็จ"
        $this->assertSame(['#', 'วันที่', 'หัวข้อโปรเจกต์', 'ชื่องาน', 'งานย่อย', 'ผู้รับผิดชอบ', 'ผู้เข้าร่วม', 'บทบาท', 'ขอบเขตงาน', 'ไฟล์แนบงาน', 'ไฟล์แนบงานย่อย', 'เริ่ม', 'เสร็จ', 'สถานะ'], $main);
        $this->assertSame($main, $details);
    }

    /**
     * งานที่ร่วมทำตามสิทธิ์ของแต่ละบทบาท — พนักงานเห็นของตัวเอง หัวหน้าเห็นของลูกทีม (รวมงานแผนกอื่นที่ลูกทีมไปร่วม)
     * admin/viewer เห็นของทุกคน และคนนอกขอบเขตแก้ URL มาดูไม่ได้
     */
    public function test_joined_work_follows_each_roles_visibility(): void
    {
        $salesTask = $this->task($this->salesMember, 'งานเซลส์ที่ไอทีไปร่วม');
        $this->collaborate($salesTask, $this->itMember);
        $salesOnly = $this->task($this->salesMember, 'งานเซลส์ที่เซลส์อีกคนร่วม');
        $this->collaborate($salesOnly, $this->user($this->sales, ['name' => 'เซลส์อีกคน']));
        $this->attach($salesTask, 'หลักฐานงานเซลส์.pdf', 'application/pdf');
        $viewer = $this->user(null, ['role' => 'viewer', 'name' => 'ผู้ดูรายงาน']);

        // พนักงาน: รายงานของตัวเองมีงานที่ไปร่วม และแก้ owner เป็นคนอื่นก็ยังได้ของตัวเอง
        $own = $this->actingAs($this->itMember)->get(route('reports.projects', ['owner' => $this->salesMember->id]))->assertOk();
        $this->assertSame(['งานเซลส์ที่ไอทีไปร่วม'], $own->viewData('taskRows')->pluck('topic')->all());

        // หัวหน้าแผนก: ภาพรวมแผนกและรายคนของลูกทีมเห็นงานที่ลูกทีมไปร่วม แต่ไม่เห็นงานร่วมของคนแผนกอื่น
        $headOverview = $this->actingAs($this->head)->get(route('reports.projects'))->assertOk()->viewData('taskRows')->pluck('topic')->all();
        $this->assertContains('งานเซลส์ที่ไอทีไปร่วม', $headOverview);
        $this->assertNotContains('งานเซลส์ที่เซลส์อีกคนร่วม', $headOverview);

        // หัวหน้าเปิดไฟล์หลักฐานของงานแผนกอื่นที่ลูกทีมไปร่วมได้ (WorkOrderPolicy::view) ไฟล์จึงไม่ถูกล็อก
        $details = $this->actingAs($this->head)->get(route('reports.projects.details', ['owner' => $this->itMember->id]))->assertOk();
        $row = $details->viewData('pageRows')->getCollection()->firstWhere('topic', 'งานเซลส์ที่ไอทีไปร่วม');
        $this->assertFalse($row['evidence']['locked']);
        $this->assertSame(1, $row['evidence']['counts']['documents']);

        // admin และ viewer: ภาพรวมทุกแผนกมีทั้งสองงาน งานเซลส์แต่ละใบขึ้นครั้งเดียวเป็นงานที่รับผิดชอบของเซลส์
        foreach ([$this->admin, $viewer] as $reader) {
            $rows = $this->actingAs($reader)->get(route('reports.projects'))->assertOk()->viewData('taskRows');
            $this->assertSame(1, $rows->where('topic', 'งานเซลส์ที่ไอทีไปร่วม')->count());
            $this->assertSame('owned', $rows->firstWhere('topic', 'งานเซลส์ที่ไอทีไปร่วม')['role']['key']);
            $this->assertSame('owned', $rows->firstWhere('topic', 'งานเซลส์ที่เซลส์อีกคนร่วม')['role']['key']);

            $it = $this->actingAs($reader)->get(route('reports.projects', ['owner' => 'department-'.$this->it->id]))->assertOk()->viewData('taskRows');
            $this->assertSame('joined', $it->firstWhere('topic', 'งานเซลส์ที่ไอทีไปร่วม')['role']['key']);
        }
    }

    /**
     * ตัวกรองบทบาทแคบเฉพาะตาราง CSV และลิงก์ต่อ ๆ ไป — KPI ไม่เปลี่ยน ค่าที่ไม่รู้จักถูกทิ้ง
     */
    public function test_the_role_filter_narrows_the_table_but_not_the_kpis(): void
    {
        $this->task($this->itMember, 'งานที่รับผิดชอบเอง');
        $joined = $this->task($this->salesMember, 'งานที่ไปร่วม');
        $this->collaborate($joined, $this->itMember);

        $all = $this->viewOf($this->itMember);
        $this->assertSame(['งานที่รับผิดชอบเอง', 'งานที่ไปร่วม'], $all->viewData('taskRows')->sortBy('id')->pluck('topic')->values()->all());
        $this->assertSame(['owned' => 'ที่รับผิดชอบ', 'joined' => 'ที่ร่วมทำ'], $all->viewData('filterOptions')['roles']);

        $onlyJoined = $this->viewOf($this->itMember, ['role' => 'joined']);
        $this->assertSame(['งานที่ไปร่วม'], $onlyJoined->viewData('taskRows')->pluck('topic')->all());
        $this->assertSame('joined', $onlyJoined->viewData('query')['role']);
        $this->assertTrue($onlyJoined->viewData('hasActiveFilters'));
        $this->assertSame($all->viewData('kpis'), $onlyJoined->viewData('kpis'));

        $csv = $this->actingAs($this->admin)->get(route('reports.projects.csv', $onlyJoined->viewData('query')))->assertOk()->streamedContent();
        $this->assertStringContainsString('งานที่ไปร่วม', $csv);
        $this->assertStringContainsString('ร่วมทำ', $csv);
        $this->assertStringNotContainsString('งานที่รับผิดชอบเอง', $csv);

        $this->assertSame(['งานที่รับผิดชอบเอง'], $this->viewOf($this->itMember, ['role' => 'owned'])->viewData('taskRows')->pluck('topic')->all());
        $this->assertCount(2, $this->viewOf($this->itMember, ['role' => 'leader'])->viewData('taskRows'));
    }

    /**
     * งานย่อยกับงานที่ร่วมทำ: ไม่มีแถวซ้ำกับงานที่รับผิดชอบ ถูกเชิญแค่งานย่อยได้แถวของงานย่อยนั้น
     * และงานของแผนกอื่นแสดงเฉพาะงานย่อยที่คนนั้นมีส่วนร่วม (กติกาเดียวกับบอร์ด)
     */
    public function test_joined_subtasks_are_not_duplicated_and_other_departments_subtasks_stay_hidden(): void
    {
        $mine = $this->task($this->itMember, 'งานแม่ของฉัน');
        $childOfMine = $this->task($this->head, 'งานย่อยที่ฉันร่วมใต้งานของฉัน', ['parent_job_id' => $mine->job_id]);
        $this->collaborate($childOfMine, $this->itMember);

        $salesParent = $this->task($this->salesMember, 'งานแม่ของเซลส์');
        $invitedChild = $this->task($this->salesMember, 'งานย่อยเซลส์ที่ถูกเชิญ', ['parent_job_id' => $salesParent->job_id]);
        $this->collaborate($invitedChild, $this->itMember);

        $joinedParent = $this->task($this->salesMember, 'งานเซลส์ที่ร่วมทั้งใบ');
        $this->collaborate($joinedParent, $this->itMember);
        $visibleChild = $this->task($this->salesMember, 'งานย่อยที่ฉันร่วมด้วย', ['parent_job_id' => $joinedParent->job_id]);
        $this->collaborate($visibleChild, $this->itMember);
        $this->task($this->salesMember, 'งานย่อยลับของเซลส์', ['parent_job_id' => $joinedParent->job_id]);

        $rows = $this->viewOf($this->itMember)->viewData('taskRows')->keyBy('topic');

        $this->assertEqualsCanonicalizing(['งานแม่ของฉัน', 'งานย่อยเซลส์ที่ถูกเชิญ', 'งานเซลส์ที่ร่วมทั้งใบ'], $rows->keys()->all());
        $this->assertSame('owned', $rows['งานแม่ของฉัน']['role']['key']);
        $this->assertSame(['งานย่อยที่ฉันร่วมใต้งานของฉัน'], $rows['งานแม่ของฉัน']['subtasks']);
        $this->assertSame('joined', $rows['งานย่อยเซลส์ที่ถูกเชิญ']['role']['key']);
        $this->assertSame(['งานย่อยที่ฉันร่วมด้วย'], $rows['งานเซลส์ที่ร่วมทั้งใบ']['subtasks']);
        $this->assertSame(2, collect($this->viewOf($this->itMember)->viewData('kpis'))->firstWhere('key', 'joined')['value']);
    }

    private function viewOf(User $owner, array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('reports.projects', ['owner' => $owner->id, ...$query]))
            ->assertOk();
    }

    private function user(?Department $department, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function task(User $assignee, string $topic, array $attributes = []): WorkOrder
    {
        $values = array_merge([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => '2026-08-01 09:00:00',
            'job_due_at' => '2026-08-31 17:00:00',
            'created_at' => '2026-08-10 09:00:00',
        ], $attributes);
        $createdAt = $values['created_at'];
        unset($values['created_at']);

        $job = WorkOrder::create($values);
        $job->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $job->refresh();
    }

    private function collaborate(WorkOrder $job, User $member): void
    {
        DB::table('work_order_collaborators')->insert([
            'work_order_id' => $job->job_id,
            'user_id' => $member->id,
            'added_by' => $job->user_id,
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
