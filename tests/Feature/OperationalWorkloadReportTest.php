<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Support\OperationalReportScope;
use App\Support\TodayWorkspace;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * รายงานปฏิบัติงานประจำเดือน — รายบุคคล
 *
 * สองกลุ่มเทสต์ที่สำคัญที่สุดของไฟล์นี้
 *
 * 1. สิทธิ์ — พนักงานเห็นเฉพาะของตัวเอง หัวหน้าเห็นเฉพาะคนในแผนกตัวเอง ทุก route
 *    ทั้งหน้า Overview หน้า detail และ CSV ต้องบังคับที่ server ไม่ใช่ซ่อนที่หน้าจอ
 * 2. "ตัวเลขโครงการต้องไม่เปลี่ยน" — เหตุผลที่ต้องแยกรายงานนี้ออกมาตั้งแต่แรก
 */
class OperationalWorkloadReportTest extends TestCase
{
    use RefreshDatabase;

    /** route ทั้งหมดของรายงานนี้ ใช้ตรวจสิทธิ์ให้ครบทุกทาง */
    private const ROUTES = [
        'reports.operational',
        'reports.operational.daily',
        'reports.operational.frequent',
        'reports.operational.delays',
        'reports.operational.daily.csv',
        'reports.operational.frequent.csv',
        'reports.operational.delays.csv',
    ];

    public function test_viewer_is_denied_on_every_operational_route(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT']), false, 'viewer');

        foreach (self::ROUTES as $route) {
            $this->actingAs($viewer)->get(route($route))->assertForbidden();
        }

        $this->actingAs($viewer)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee(route('reports.operational'), false);
    }

    /**
     * ทุกคนที่เปิดรายงานนี้ได้ต้องมีทางไปถึงจากหน้าเลือกรายงาน ไม่ใช่ต้องรู้ URL เอง
     */
    public function test_the_entry_card_appears_for_everyone_who_can_open_it(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        foreach ([$this->user(null, false, 'admin'), $this->user($department, true), $this->user($department)] as $actor) {
            $this->actingAs($actor)
                ->get(route('reports.index'))
                ->assertOk()
                ->assertSee('รายงานปฏิบัติงาน')
                ->assertSee(route('reports.operational'), false);
        }
    }

    /**
     * พนักงานเห็นสองรายงานเท่านั้น — รายงานตัวเอง และรายงานปฏิบัติงาน
     */
    public function test_a_member_landing_offers_exactly_two_reports(): void
    {
        $member = $this->user(Department::create(['department_name' => 'IT']));

        $response = $this->actingAs($member)->get(route('reports.index'))->assertOk();

        $response->assertSee('รายงานโปรเจกต์')
            ->assertSee('รายงานปฏิบัติงาน')
            ->assertSee(route('reports.projects'), false)
            ->assertSee(route('reports.operational'), false)
            ->assertDontSee(route('reports.organization'), false)
            ->assertDontSee(route('reports.employees.index'), false);

        $this->assertSame(2, substr_count($response->getContent(), 'report-choice report-choice--'));
        // รายงานภาพรวมองค์กรถูกยกเลิก ลิงก์เดิมพาไปรายงานโปรเจกต์ ซึ่งพนักงานเห็นเฉพาะงานของตัวเอง
        $this->actingAs($member)->get('/reports/organization')->assertRedirect('/reports/projects');
        $this->actingAs($member)->get(route('reports.projects'))->assertOk()->assertViewHas('isPersonalReport', true);

        $head = $this->user($member->department, true);
        $headResponse = $this->actingAs($head)->get(route('reports.index'))->assertOk();

        // หัวหน้าแผนก: รายงานโปรเจกต์ (มีภาพรวมแผนก) และรายงานปฏิบัติงาน
        $this->assertSame(2, substr_count($headResponse->getContent(), 'report-choice report-choice--'));
        $headResponse->assertDontSee('/my-reports', false);
    }

    /**
     * พนักงานลงที่รายงานของตัวเอง ส่วนหัวหน้าแผนกและ admin ลงที่ภาพรวมทีมก่อน
     * แล้วเลือกคนจากช่องพนักงาน (มีตัวเลือก "ภาพรวมทีม" กลับมาได้)
     */
    public function test_members_land_on_their_own_report_while_heads_and_admins_land_on_the_team_overview(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);
        $admin = $this->user(null, false, 'admin');

        foreach ([$member, $head, $admin] as $actor) {
            $this->actingAs($actor)->get(route('reports.operational'))
                ->assertOk()
                ->assertSee('รายงานปฏิบัติงานประจำเดือน')
                ->assertSee('id="operationalMonth"', false)
                ->assertSee('กันยายน 2569')
                // ตัวกรองช่วงเวลาและกระดานการ์ดชุดเดิมถูกแทนที่แล้ว
                ->assertDontSee('data-operational-people-table', false)
                ->assertDontSee('id="reportPeriod"', false);
        }

        $memberPage = $this->actingAs($member)->get(route('reports.operational'));
        $this->assertTrue($this->scopeOf($memberPage)->owner->is($member));
        $memberPage->assertDontSee('id="operationalOwner"', false)
            ->assertDontSee('data-operational-team-table', false);

        foreach ([$head, $admin] as $actor) {
            $page = $this->actingAs($actor)->get(route('reports.operational'));

            $this->assertTrue($this->scopeOf($page)->isTeamView());
            $page->assertSee('id="operationalOwner"', false)
                ->assertSee('<option value="" selected>ภาพรวมทีม</option>', false)
                ->assertSee('data-operational-team-table', false)
                // ภาพรวมทีมไม่มี CSV — ไฟล์ทั้งสามเป็นข้อมูลรายบุคคล
                ->assertDontSee('data-csv-menu', false);
        }

        $this->assertFalse(Route::has('reports.operationalExportCsv'), 'CSV รวมทุกอย่างของเดิมต้องถูกแทนด้วยสามไฟล์');
    }

    /**
     * วันนี้ใครทำงานประจำแล้วบ้าง — คนที่ยังไม่เปิดระบบวันนี้ต้องขึ้นว่า "ยังไม่เริ่ม"
     * ไม่ใช่ "ไม่มีงานประจำ" และการเปิดรายงานต้องไม่สร้างรายการงานให้คนอื่น
     */
    public function test_the_team_overview_shows_todays_routine_status_and_the_month_summary(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $head = $this->user($it, true);
        $finished = $this->user($it);
        $notStarted = $this->user($it);
        $noRoutine = $this->user($it);
        $salesMember = $this->user($sales);

        $this->routineLog($finished, $it, ['title' => 'ตรวจ Server เช้า', 'status' => 'done', 'duration_minutes' => 30]);
        $this->log($finished, $it, ['title' => 'งานสัปดาห์ก่อน', 'work_date' => '2026-09-08', 'duration_minutes' => 90]);
        $this->template($notStarted, 'ตรวจ UPS เช้า');
        $this->routineLog($salesMember, $sales, ['title' => 'งานของเซลส์']);

        $response = $this->actingAs($head)->get(route('reports.operational'))->assertOk();

        $response->assertSee('วันนี้ใครทำงานประจำแล้วบ้าง')
            ->assertSee($finished->name)
            ->assertSee($notStarted->name)
            ->assertDontSee($salesMember->name)
            ->assertSee(route('reports.operational', ['month' => '2026-09', 'owner' => $finished->id]));

        $people = $response->viewData('people')->keyBy('id');
        $this->assertSame('complete', $people[$finished->id]['today']['key']);
        $this->assertSame('1/1', $people[$finished->id]['today']['closed'].'/'.$people[$finished->id]['today']['total']);
        $this->assertSame('2 ชม.', $people[$finished->id]['hours_text']);
        $this->assertSame('waiting', $people[$notStarted->id]['today']['key']);
        $this->assertSame('none', $people[$noRoutine->id]['today']['key']);
        $this->assertFalse($people->has($salesMember->id));

        // คนที่ต้องตามก่อนอยู่บนสุด
        $this->assertSame($notStarted->id, $response->viewData('people')->first()['id']);
        $this->assertSame(0, WorkLog::query()->where('user_id', $notStarted->id)->count(), 'รายงานต้องอ่านอย่างเดียว');

        $this->assertSame('2 ชม.', $response->viewData('kpis')['hours']['value']);

        // กล่อง "ดูงาน" — รายการของวันนี้พร้อมประเภทงานและหมวดงาน รวมงานประจำที่ยังไม่เริ่ม
        $finishedItems = $people[$finished->id]['today_items'];
        $this->assertSame(['ตรวจ Server เช้า'], $finishedItems->pluck('title')->all());
        $this->assertSame('งานประจำ', $finishedItems->first()['kind_label']);
        $this->assertSame('เสร็จแล้ว', $finishedItems->first()['status_label']);

        $waitingItems = $people[$notStarted->id]['today_items'];
        $this->assertSame(['ตรวจ UPS เช้า'], $waitingItems->pluck('title')->all());
        $this->assertSame('ยังไม่เริ่ม', $waitingItems->first()['status_label']);

        $response->assertSee('data-team-work-open="'.$finished->id.'"', false)
            ->assertSee('data-team-work-detail="'.$notStarted->id.'"', false)
            ->assertSee('data-team-work-modal', false);
    }

    public function test_the_admin_team_overview_covers_every_department_but_no_admins(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $itMember = $this->user(Department::create(['department_name' => 'IT']));
        $salesMember = $this->user(Department::create(['department_name' => 'Sales']));
        $admin = $this->user(null, false, 'admin');
        $otherAdmin = $this->user(null, false, 'admin');

        $response = $this->actingAs($admin)->get(route('reports.operational'))->assertOk();
        $ids = $response->viewData('people')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$itMember->id, $salesMember->id], $ids);
        $this->assertNotContains($otherAdmin->id, $ids);
        $this->assertSame('ทุกแผนก', $response->viewData('teamLabel'));
    }

    public function test_a_past_month_team_overview_has_no_today_columns(): void
    {
        $this->travelToBangkok('2026-10-05 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, true);
        $member = $this->user($department);
        $this->log($member, $department, ['title' => 'งานกันยายน', 'work_date' => '2026-09-10', 'duration_minutes' => 60]);

        $response = $this->actingAs($head)->get(route('reports.operational', ['month' => '2026-09']))->assertOk();

        $this->assertFalse($response->viewData('isCurrentMonth'));
        $this->assertNull($response->viewData('people')->firstWhere('id', $member->id)['today']);
        $response->assertSee('ภาพรวมรายคนของกันยายน 2569')
            ->assertDontSee('สถานะวันนี้')
            // เดือนย้อนหลังไม่มีกล่องของวันนี้ ปุ่มดูงานพาไปหน้าสรุปรายวันของคนนั้น
            ->assertDontSee('data-team-work-modal', false)
            ->assertSee(route('reports.operational.daily', ['month' => '2026-09', 'owner' => $member->id]));
    }

    /**
     * หัวหน้าที่เลือกดูรายงานของตัวเองต้องอยู่ที่รายงานของตัวเองต่อ ไม่หลุดกลับไปภาพรวมทีม
     */
    public function test_a_head_viewing_their_own_report_keeps_it_across_links(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, true);
        $this->user($department);

        $query = ['month' => '2026-09', 'owner' => $head->id];
        $response = $this->actingAs($head)->get(route('reports.operational', $query))->assertOk();

        $this->assertFalse($this->scopeOf($response)->isTeamView());
        $this->assertTrue($this->scopeOf($response)->isOwnReport);
        $response->assertSee(route('reports.operational.daily', $query))
            ->assertSee(route('reports.operational.daily.csv', $query));

        $this->actingAs($head)
            ->get(route('reports.operational.daily', $query))
            ->assertSee(route('reports.operational', $query));
    }

    /**
     * แก้ owner หรือ department ใน URL แล้วต้องไม่เห็นของเพื่อนร่วมแผนก — ทุก route
     */
    public function test_a_member_cannot_open_a_colleague_report_by_editing_the_url(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $colleague = $this->user($department);

        $this->log($member, $department, ['title' => 'งานของฉันเอง', 'duration_minutes' => 30]);
        $this->log($colleague, $department, ['title' => 'งานของเพื่อนร่วมแผนก', 'duration_minutes' => 30]);

        $query = ['owner' => $colleague->id, 'department' => $department->id];

        foreach (self::ROUTES as $route) {
            $response = $this->actingAs($member)->get(route($route, $query))->assertOk();
            $content = str_ends_with($route, '.csv') ? $response->streamedContent() : $response->getContent();

            $this->assertStringNotContainsString('งานของเพื่อนร่วมแผนก', $content, $route);
        }

        $this->actingAs($member)->get(route('reports.operational.daily', $query))->assertSee('งานของฉันเอง');
    }

    public function test_a_department_head_only_opens_members_of_their_own_department(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itMember = $this->user($it);
        $salesMember = $this->user($sales);
        $itHead = $this->user($it, true);

        $this->log($itMember, $it, ['title' => 'งานของไอที']);
        $this->log($salesMember, $sales, ['title' => 'งานของเซลส์']);

        $allowed = $this->actingAs($itHead)->get(route('reports.operational.daily', ['owner' => $itMember->id]))->assertOk();
        $this->assertTrue($this->scopeOf($allowed)->owner->is($itMember));
        $allowed->assertSee('งานของไอที');

        foreach (self::ROUTES as $route) {
            $response = $this->actingAs($itHead)
                ->get(route($route, ['owner' => $salesMember->id, 'department' => $sales->id]))
                ->assertOk();
            $content = str_ends_with($route, '.csv') ? $response->streamedContent() : $response->getContent();

            $this->assertStringNotContainsString('งานของเซลส์', $content, $route);
        }

        // คนนอกแผนกไม่อยู่ในรายการให้เลือก หน้า detail ตกกลับเป็นรายงานของตัวเอง
        $fallback = $this->actingAs($itHead)->get(route('reports.operational.daily', ['owner' => $salesMember->id]))->assertOk();
        $this->assertTrue($this->scopeOf($fallback)->owner->is($itHead));
        $this->assertFalse($this->scopeOf($fallback)->owners->contains('id', $salesMember->id));

        // ส่วนหน้า Overview ตกกลับเป็นภาพรวมทีมของแผนกตัวเอง
        $team = $this->actingAs($itHead)->get(route('reports.operational', ['owner' => $salesMember->id]))->assertOk();
        $this->assertTrue($this->scopeOf($team)->isTeamView());
        $team->assertDontSee($salesMember->name);
    }

    public function test_admin_opens_any_employee_and_their_own_report_but_not_another_admin(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $sales = Department::create(['department_name' => 'Sales']);
        $salesMember = $this->user($sales);
        $admin = $this->user(null, false, 'admin');
        $otherAdmin = $this->user(null, false, 'admin');

        $this->log($salesMember, $sales, ['title' => 'งานของเซลส์']);

        $this->actingAs($admin)
            ->get(route('reports.operational.daily', ['owner' => $salesMember->id]))
            ->assertOk()
            ->assertSee('งานของเซลส์');

        $response = $this->actingAs($admin)->get(route('reports.operational.daily', ['owner' => $otherAdmin->id]))->assertOk();
        $this->assertTrue($this->scopeOf($response)->owner->is($admin));

        $this->actingAs($admin)
            ->get(route('reports.operational', ['owner' => $admin->id]))
            ->assertOk()
            ->assertViewHas('scope', fn (OperationalReportScope $scope): bool => $scope->owner?->is($admin) === true);
    }

    /**
     * เดือนเดียวคุมทุกส่วน — KPI ตาราง detail และ CSV ต้องตัดขอบเดือนเดียวกัน
     */
    public function test_the_month_filter_scopes_every_section(): void
    {
        $this->travelToBangkok('2026-10-05 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'งานสิ้นเดือนสิงหาคม', 'work_date' => '2026-08-31', 'duration_minutes' => 60]);
        $this->log($member, $department, ['title' => 'งานต้นเดือนกันยายน', 'work_date' => '2026-09-01', 'duration_minutes' => 60]);
        $this->log($member, $department, ['title' => 'งานสิ้นเดือนกันยายน', 'work_date' => '2026-09-30', 'duration_minutes' => 90]);
        $this->log($member, $department, ['title' => 'งานต้นเดือนตุลาคม', 'work_date' => '2026-10-01', 'duration_minutes' => 30]);

        $september = ['month' => '2026-09'];

        $overview = $this->actingAs($member)->get(route('reports.operational', $september))->assertOk();
        $this->assertSame('2.5 ชม.', $overview->viewData('kpis')['hours']['value']);
        $this->assertSame(30, count($overview->viewData('chartData')['daily']['labels']));

        $daily = $this->actingAs($member)->get(route('reports.operational.daily', $september))->assertOk();
        $this->assertSame(
            ['งานต้นเดือนกันยายน', 'งานสิ้นเดือนกันยายน'],
            $daily->viewData('rows')->pluck('title')->all()
        );

        $frequent = $this->actingAs($member)->get(route('reports.operational.frequent', $september))->assertOk();
        $this->assertEqualsCanonicalizing(
            ['งานต้นเดือนกันยายน', 'งานสิ้นเดือนกันยายน'],
            $frequent->viewData('rows')->pluck('title')->all()
        );

        $csv = $this->actingAs($member)->get(route('reports.operational.daily.csv', $september))->assertOk()->streamedContent();
        $this->assertStringContainsString('งานสิ้นเดือนกันยายน', $csv);
        $this->assertStringNotContainsString('งานสิ้นเดือนสิงหาคม', $csv);
        $this->assertStringNotContainsString('งานต้นเดือนตุลาคม', $csv);

        $august = $this->actingAs($member)->get(route('reports.operational.daily', ['month' => '2026-08']))->assertOk();
        $this->assertSame(['งานสิ้นเดือนสิงหาคม'], $august->viewData('rows')->pluck('title')->all());
    }

    public function test_an_invalid_or_future_month_falls_back_to_the_current_month(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $member = $this->user(Department::create(['department_name' => 'IT']));

        foreach (['2099-01', '2026-13', 'abc', '2026-9', '', '2025-12'] as $month) {
            $response = $this->actingAs($member)->get(route('reports.operational', ['month' => $month]))->assertOk();

            $this->assertSame('2026-09', $this->scopeOf($response)->monthKey(), "month={$month}");
        }
    }

    /**
     * ตัวเลือกเดือนเริ่มที่เดือนปัจจุบัน และย้อนได้ถึงมกราคมของปีเดียวกันเท่านั้น
     */
    public function test_month_options_start_at_the_current_month_within_the_current_year(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $member = $this->user(Department::create(['department_name' => 'IT']));

        $response = $this->actingAs($member)->get(route('reports.operational'))->assertOk();

        $this->assertSame(
            ['2026-09', '2026-08', '2026-07', '2026-06', '2026-05', '2026-04', '2026-03', '2026-02', '2026-01'],
            array_keys($this->scopeOf($response)->monthOptions)
        );
        $response->assertDontSee('2568');
    }

    public function test_kpis_compare_with_the_previous_month(): void
    {
        $this->travelToBangkok('2026-10-05 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'งานสิงหาคม', 'work_date' => '2026-08-10', 'duration_minutes' => 120]);

        $this->log($member, $department, ['title' => 'งานประจำกันยายน', 'work_date' => '2026-09-10', 'duration_minutes' => 60]);
        $this->log($member, $department, ['title' => 'งานนอกสถานที่กันยายน', 'kind' => 'field', 'work_date' => '2026-09-11', 'duration_minutes' => 120]);
        // งานประจำจากแม่แบบของวันที่ผ่านไปแล้วซึ่งยังไม่ปิด — นับเป็นงานค้าง
        $this->routineLog($member, $department, ['title' => 'ตรวจเครื่องค้างไว้', 'work_date' => '2026-09-12', 'status' => 'open']);

        $kpis = $this->actingAs($member)
            ->get(route('reports.operational', ['month' => '2026-09']))
            ->assertOk()
            ->viewData('kpis');

        $this->assertSame('3 ชม.', $kpis['hours']['value']);
        $this->assertSame(['direction' => 'up', 'text' => '50%', 'tone' => 'good'], $kpis['hours']['trend']);

        $this->assertSame('2 ครั้ง', $kpis['routine']['value']);
        $this->assertSame('1 ครั้ง', $kpis['field']['value']);
        // เดือนก่อนไม่มีงานนอกสถานที่ ไม่มีฐานให้เทียบ
        $this->assertNull($kpis['field']['trend']);

        // ปิดแล้ว 2 จาก 3 = 67% เดือนก่อน 100% → ลดลง 33 จุด ซึ่งเป็นทิศทางที่แย่ลง
        $this->assertSame('67%', $kpis['close_rate']['value']);
        $this->assertSame(['direction' => 'down', 'text' => '33%', 'tone' => 'bad'], $kpis['close_rate']['trend']);

        $this->assertSame('0%', $kpis['on_time']['value']);
        $this->assertNull($kpis['on_time']['trend']);

        $this->assertSame('1 รายการ', $kpis['pending']['value']);
    }

    public function test_the_overview_week_is_the_current_monday_to_saturday_in_the_current_month(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'เสาร์สัปดาห์ก่อน', 'work_date' => '2026-09-12']);
        $this->log($member, $department, ['title' => 'จันทร์สัปดาห์นี้', 'work_date' => '2026-09-14']);
        $this->log($member, $department, ['title' => 'เสาร์สัปดาห์นี้', 'work_date' => '2026-09-19']);
        $this->log($member, $department, ['title' => 'อาทิตย์สัปดาห์นี้', 'work_date' => '2026-09-20']);

        $response = $this->actingAs($member)->get(route('reports.operational'))->assertOk();

        $this->assertSame('2026-09-14', $response->viewData('week')['start']);
        $this->assertSame('2026-09-19', $response->viewData('week')['end']);
        $this->assertSame(['จันทร์สัปดาห์นี้', 'เสาร์สัปดาห์นี้'], $response->viewData('weekRows')->pluck('title')->all());

        // ตารางเป็นระดับรายการ พร้อมวันที่ภาษาไทยและวันในสัปดาห์
        $response->assertSee('14 ก.ย. 2569 (จ.)')
            ->assertSee(route('reports.operational.daily', ['month' => '2026-09']), false);
    }

    public function test_a_past_month_uses_the_latest_week_that_has_data(): void
    {
        $this->travelToBangkok('2026-10-05 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'ต้นเดือน', 'work_date' => '2026-09-02']);
        $this->log($member, $department, ['title' => 'พฤหัสสัปดาห์สุดท้าย', 'work_date' => '2026-09-24']);
        $this->log($member, $department, ['title' => 'อาทิตย์ปลายเดือน', 'work_date' => '2026-09-27']);

        $response = $this->actingAs($member)->get(route('reports.operational', ['month' => '2026-09']))->assertOk();

        $this->assertSame('2026-09-21', $response->viewData('week')['start']);
        $this->assertSame('2026-09-26', $response->viewData('week')['end']);
        $this->assertSame(['พฤหัสสัปดาห์สุดท้าย'], $response->viewData('weekRows')->pluck('title')->all());

        // ข้อมูลวันอาทิตย์ยังอยู่ครบในรายงานฉบับเต็ม
        $this->actingAs($member)
            ->get(route('reports.operational.daily', ['month' => '2026-09']))
            ->assertSee('อาทิตย์ปลายเดือน');
    }

    public function test_the_overview_week_is_clipped_to_the_selected_month(): void
    {
        $this->travelToBangkok('2026-10-01 10:00');
        $member = $this->user(Department::create(['department_name' => 'IT']));

        $week = $this->actingAs($member)->get(route('reports.operational'))->assertOk()->viewData('week');

        $this->assertSame('2026-10-01', $week['start']);
        $this->assertSame('2026-10-03', $week['end']);
    }

    /**
     * การ์ดบน Overview แสดง 5 อันดับ ส่วนหน้า "ดูทั้งหมด" แสดงครบ และลิงก์พกเดือน/คนเดิมไปด้วย
     */
    public function test_top_five_cards_link_to_complete_reports_for_the_same_person_and_month(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);

        foreach (range(1, 6) as $index) {
            foreach (range(1, $index) as $repeat) {
                $this->log($member, $department, ['title' => 'งานลำดับ '.$index, 'duration_minutes' => 10]);
            }
        }

        $query = ['month' => '2026-09', 'owner' => $member->id];
        $overview = $this->actingAs($head)->get(route('reports.operational', $query))->assertOk();

        $this->assertCount(5, $overview->viewData('frequentWork'));
        $this->assertSame(6, $overview->viewData('frequentTotal'));
        $this->assertSame('งานลำดับ 6', $overview->viewData('frequentWork')->first()['title']);
        $overview->assertSee(route('reports.operational.frequent', $query))
            ->assertSee(route('reports.operational.delays', $query))
            ->assertSee(route('reports.operational.daily', $query))
            ->assertSee(route('reports.operational.frequent.csv', $query));

        $detail = $this->actingAs($head)->get(route('reports.operational.frequent', $query))->assertOk();
        $this->assertCount(6, $detail->viewData('rows'));
        $this->assertSame(range(1, 6), $detail->viewData('rows')->pluck('rank')->all());
    }

    /**
     * เหตุผลมาจากข้อมูลจริงทุกแหล่ง และทุกเหตุการณ์ย้อนกลับไปหางานกับวันที่ได้
     */
    public function test_delay_reasons_come_from_every_source_and_trace_back_to_the_task(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->routineLog($member, $department, [
            'title' => 'ตรวจ Server', 'work_date' => '2026-09-14', 'status' => 'done', 'duration_minutes' => 60,
            'late_start_reason' => 'ติดงานอื่น', 'late_completion_reason' => 'รอข้อมูลหรือการตอบกลับ',
        ]);
        $this->routineLog($member, $department, [
            'title' => 'ตรวจ UPS', 'work_date' => '2026-09-15', 'status' => 'skipped', 'skip_reason' => 'ลางาน',
        ]);
        $this->routineLog($member, $department, [
            'title' => 'สำรองข้อมูล', 'work_date' => '2026-09-10', 'status' => 'open',
        ]);
        $this->routineLog($member, $department, [
            'title' => 'ตรวจกล้องวงจรปิด', 'work_date' => '2026-09-11', 'status' => 'done', 'duration_minutes' => 20,
            'late_start_reason' => 'ติดงานอื่น',
        ]);

        $response = $this->actingAs($member)->get(route('reports.operational.delays'))->assertOk();
        $events = $response->viewData('events');

        $this->assertCount(5, $events);
        // กติกาปัจจุบัน: งานประจำของวันที่ผ่านไปแล้วที่ไม่ได้กดเริ่มคือ "ไม่ได้เริ่ม" (ปิดรอบ 17:00)
        // แยกจาก "ไม่ได้ทำ" และจาก "เกินกำหนด" ของวันนี้ที่ยังไม่ปิดรอบ
        $this->assertEqualsCanonicalizing(
            ['เริ่มช้า', 'เสร็จเกินเวลา', 'ไม่ได้ทำ', 'ไม่ได้เริ่ม', 'เริ่มช้า'],
            $events->pluck('type_label')->all()
        );

        $notStarted = $events->firstWhere('type', 'not_started');
        $this->assertSame('สำรองข้อมูล', $notStarted['title']);
        $this->assertSame('2026-09-10', $notStarted['date']);
        $this->assertSame('ยังไม่ระบุเหตุผล', $notStarted['reason']);

        $groups = $response->viewData('groups');
        $this->assertSame(['reason' => 'ติดงานอื่น', 'count' => 2], collect($groups->first())->only(['reason', 'count'])->all());

        $response->assertSee('ตรวจ UPS')->assertSee('ลางาน')->assertSee('10 ก.ย. 2569 (พฤ.)');

        $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('ติดงานอื่น');
    }

    /**
     * CSV ต้องตรงกับหน้า detail ของรายงานนั้นทุกแถว และแต่ละไฟล์มีข้อมูลประเภทเดียว
     */
    public function test_each_csv_matches_its_detail_page_and_stays_separate(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'เช็คคอมพิวเตอร์ Callcenter', 'work_date' => '2026-09-14', 'duration_minutes' => 60, 'details' => 'เครื่องใช้งานปกติ']);
        $this->log($member, $department, ['title' => 'เช็คคอมพิวเตอร์ Callcenter', 'work_date' => '2026-09-15', 'duration_minutes' => 60]);
        $this->routineLog($member, $department, ['title' => 'ตรวจ UPS', 'work_date' => '2026-09-15', 'status' => 'skipped', 'skip_reason' => 'ลางาน']);

        $query = ['month' => '2026-09'];

        /*
         * เปิดหน้าหนึ่งครั้งก่อนเทียบ — การ render layout สร้างงานประจำของ "วันนี้" จากแม่แบบ
         * (WorkLogRoutineMaterializer) ข้อมูลจึงเปลี่ยนระหว่างคำขอแรกกับคำขอถัดไป
         * การเทียบหน้าจอกับ CSV ต้องทำบนข้อมูลชุดเดียวกัน ไม่ใช่ข้ามช่วงที่แถวใหม่ถูกสร้าง
         */
        $this->actingAs($member)->get(route('reports.operational', $query))->assertOk();

        $dailyRows = $this->actingAs($member)->get(route('reports.operational.daily', $query))->viewData('rows');
        $dailyCsv = $this->csvRows($this->actingAs($member)->get(route('reports.operational.daily.csv', $query)));
        $this->assertSame(['วันที่', 'รายการงาน', 'ประเภทงาน', 'หมวดงาน', 'สถานะ', 'ชั่วโมง', 'หมายเหตุ'], $dailyCsv[0]);
        $this->assertSame(
            $dailyRows->map(fn (array $row): array => [
                $row['date_label'], $row['title'], $row['kind_label'], $row['category'],
                $row['status_label'], $row['hours'], $row['note'],
            ])->all(),
            array_slice($dailyCsv, 1)
        );
        $this->assertContains('เครื่องใช้งานปกติ', array_column(array_slice($dailyCsv, 1), 6));

        $frequentRows = $this->actingAs($member)->get(route('reports.operational.frequent', $query))->viewData('rows');
        $frequentCsv = $this->csvRows($this->actingAs($member)->get(route('reports.operational.frequent.csv', $query)));
        $this->assertSame(['อันดับ', 'ลักษณะงาน', 'จำนวนครั้ง', 'เวลาที่ใช้'], $frequentCsv[0]);
        $this->assertSame(
            $frequentRows->map(fn (array $row): array => [(string) $row['rank'], $row['title'], (string) $row['count'], $row['hours_text']])->all(),
            array_slice($frequentCsv, 1)
        );

        $delayEvents = $this->actingAs($member)->get(route('reports.operational.delays', $query))->viewData('events');
        $delayCsv = $this->csvRows($this->actingAs($member)->get(route('reports.operational.delays.csv', $query)));
        $this->assertSame(['วันที่', 'รายการงาน', 'ประเภทงาน', 'หมวดงาน', 'ประเภทเหตุผล', 'เหตุผล', 'สถานะ'], $delayCsv[0]);
        $this->assertCount($delayEvents->count(), array_slice($delayCsv, 1));
        $this->assertSame(['ตรวจ UPS', 'ลางาน'], [$delayCsv[1][1], $delayCsv[1][5]]);

        $response = $this->actingAs($member)->get(route('reports.operational.frequent.csv', $query));
        $this->assertStringContainsString('smart-goals-operational-frequent-2026-09.csv', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_csv_neutralises_values_that_spreadsheets_would_run_as_formulas(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => '=HYPERLINK("http://example.test")']);

        $content = $this->actingAs($member)->get(route('reports.operational.daily.csv'))->assertOk()->streamedContent();

        $this->assertStringStartsWith(chr(0xEF).chr(0xBB).chr(0xBF), $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_status_is_plain_colored_text_without_an_icon(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'ตรวจเสร็จ', 'status' => 'done', 'work_date' => '2026-09-15']);
        $this->routineLog($member, $department, ['title' => 'ค้างจากวันก่อน', 'status' => 'open', 'work_date' => '2026-09-14']);

        $this->actingAs($member)
            ->get(route('reports.operational.daily'))
            ->assertOk()
            ->assertSee('<span class="operational-status is-green">เสร็จแล้ว</span>', false)
            // งานประจำของวันก่อนที่ไม่ได้กดเริ่ม = ปิดรอบ 17:00 เป็น "ไม่ได้เริ่ม" (เดิมแสดงรวมว่า "ต้องระบุเหตุผล")
            ->assertSee('<span class="operational-status is-red">ไม่ได้เริ่ม</span>', false);
    }

    public function test_an_empty_month_renders_empty_states_instead_of_blank_tables(): void
    {
        $this->travelToBangkok('2026-09-16 10:00');
        $member = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('ยังไม่มีงานที่บันทึกในเดือนนี้')
            ->assertSee('ไม่มีงานที่ล่าช้าหรือเกินกำหนดในเดือนนี้')
            ->assertSee('ยังไม่มีรายการงานในสัปดาห์นี้');
    }

    /**
     * เทสต์กันการปนเปื้อน — เหตุผลตั้งต้นของการแยกรายงาน
     */
    public function test_project_report_numbers_do_not_change_when_work_logs_exist(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->task($member, $department, 2);
        $this->task($member, $department, 4);

        // ภาพรวมของ admin อยู่ในรายงานโปรเจกต์ (รายงานภาพรวมองค์กรถูกยกเลิกแล้ว)
        $before = $this->actingAs($admin)->get(route('reports.projects'))->assertOk();
        $beforeNumbers = [$before->viewData('kpis'), $before->viewData('chartData')];

        for ($index = 0; $index < 10; $index++) {
            $this->log($member, $department, ['duration_minutes' => 60, 'title' => "งานปฏิบัติการ {$index}"]);
        }

        $after = $this->actingAs($admin)->get(route('reports.projects'))->assertOk();

        $this->assertSame(2, $after->viewData('totalJobs'));
        $this->assertSame(
            $beforeNumbers,
            [$after->viewData('kpis'), $after->viewData('chartData')],
            'ตัวเลข KPI และกราฟของรายงานโปรเจกต์ต้องไม่เปลี่ยนเมื่อมีบันทึกงานประจำวัน'
        );
    }

    /**
     * รายงานรายบุคคลของโครงการต้องไม่มีบล็อกงานปฏิบัติการ
     */
    public function test_the_employee_report_no_longer_carries_the_operational_block(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->task($member, $department, 4);
        $this->log($member, $department, ['duration_minutes' => 150]);

        $response = $this->actingAs($admin)
            ->get(route('reports.projects', ['owner' => $member->id]))
            ->assertOk()
            ->assertDontSee('ดูรายงานภาระงานปฏิบัติการ')
            ->assertDontSee('employee-operational', false)
            ->assertDontSee('employeeOperationalChart', false);

        $this->assertNotEmpty($response->viewData('kpis'));
    }

    private function scopeOf(TestResponse $response): OperationalReportScope
    {
        return $response->viewData('scope');
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $content = $response->assertOk()->streamedContent();
        $content = str_starts_with($content, chr(0xEF).chr(0xBB).chr(0xBF)) ? substr($content, 3) : $content;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function travelToBangkok(string $moment): void
    {
        $this->travelTo(CarbonImmutable::parse($moment, TodayWorkspace::BUSINESS_TIMEZONE));
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

    /**
     * แม่แบบงานประจำที่ถึงกำหนดทุกวัน
     */
    private function template(User $owner, string $title): WorkLogTemplate
    {
        return WorkLogTemplate::create([
            'user_id' => $owner->id,
            'title' => $title,
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::EVERYDAY,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
            'is_active' => true,
        ]);
    }

    /**
     * งานประจำจริง — บันทึกที่ผูกกับแม่แบบ ไม่ใช่บันทึกที่ผู้ใช้พิมพ์เอง
     */
    private function routineLog(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        $template = $this->template($owner, $overrides['title'] ?? 'งานประจำประจำวัน');

        return $this->log($owner, $department, array_merge([
            'source' => 'template',
            'work_log_template_id' => $template->id,
        ], $overrides));
    }

    private function log(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department?->id,
            'kind' => 'routine',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'งานปฏิบัติการ',
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ], $overrides));
    }

    private function task(User $assignee, Department $department, int $status): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $department->id,
            'job_topic' => 'งานโครงการ '.uniqid(),
            'job_priority' => 2,
            'job_status' => $status,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDays(3),
            'job_due_at' => now()->addDays(3),
            'job_completed_at' => $status === 4 ? now() : null,
        ]);
    }
}
