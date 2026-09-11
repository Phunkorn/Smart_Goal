<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\OperationalWorkloadReportService;
use App\Services\WorkLogParticipantService;
use App\Services\WorkLogRoutineMaterializer;
use App\Support\TodayWorkspace;
use App\Support\WorkLogWeekdays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * รายงานภาระงานปฏิบัติการ
 *
 * เทสต์ที่สำคัญที่สุดของไฟล์นี้คือ "ตัวเลขโครงการต้องไม่เปลี่ยน" เพราะเหตุผลทั้งหมด
 * ที่ต้องแยกรายงานออกมาคือไม่ให้ชั่วโมงงานปฏิบัติการไปเจือปน KPI ของโครงการ
 * ถ้าใครเผลอรวมสองโดเมนเข้าด้วยกันในอนาคต เทสต์นั้นต้องแดงทันที
 */
class OperationalWorkloadReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_hours_across_every_department(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itMember = $this->user($it);
        $salesMember = $this->user($sales);
        $admin = $this->user(null, false, 'admin');

        $this->log($itMember, $it, ['duration_minutes' => 120]);
        $this->log($salesMember, $sales, ['duration_minutes' => 60]);

        $this->actingAs($admin)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('ภาระงานปฏิบัติการ')
            // 180 นาที = 3.0 ชม.
            ->assertSee('3.0')
            ->assertSee($itMember->name)
            ->assertSee($salesMember->name);
    }

    public function test_department_head_sees_only_their_own_department(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itMember = $this->user($it);
        $salesMember = $this->user($sales);
        $itHead = $this->user($it, true);

        $this->log($itMember, $it, ['duration_minutes' => 120, 'title' => 'งานของไอที']);
        $this->log($salesMember, $sales, ['duration_minutes' => 300, 'title' => 'งานของเซลส์']);

        $this->actingAs($itHead)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('งานของไอที')
            ->assertDontSee('งานของเซลส์')
            ->assertSee($itMember->name)
            ->assertDontSee($salesMember->name);
    }

    /**
     * viewer ดูรายงานโครงการได้ แต่ต้องไม่เห็นรายงานนี้ เพราะเป็นข้อมูลรายบุคคล
     * ที่ละเอียดกว่าภาพรวมองค์กร (จงใจต่างจาก authorizeAdminReports())
     */
    public function test_viewer_is_denied_and_does_not_see_the_entry_card(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT']), false, 'viewer');

        $this->actingAs($viewer)->get(route('reports.operational'))->assertForbidden();
        $this->actingAs($viewer)->get(route('reports.operationalExportCsv'))->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('ดูภาระงานปฏิบัติการ');
    }

    /**
     * พนักงานเปิดรายงานปฏิบัติงานได้ แต่ต้องเห็นเฉพาะบันทึกของตัวเอง
     *
     * ขอบเขตถูกบังคับที่ ReportController::forcedOwnerId() ซึ่งไม่ได้อ่านค่าจาก
     * request เลย การแก้ URL จึงไม่ทำให้เห็นของเพื่อนร่วมแผนก
     */
    public function test_a_member_sees_only_their_own_rows(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $colleague = $this->user($department);

        $this->log($member, $department, ['title' => 'งานของฉันเอง']);
        $this->log($colleague, $department, ['title' => 'งานของเพื่อนร่วมแผนก']);

        $this->actingAs($member)
            ->get(route('reports.operational', ['department' => $department->id]))
            ->assertOk()
            ->assertSee('งานของฉันเอง')
            ->assertDontSee('งานของเพื่อนร่วมแผนก');
    }

    public function test_viewer_still_cannot_open_the_report(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT']), false, 'viewer');

        $this->actingAs($viewer)->get(route('reports.operational'))->assertForbidden();
    }

    /**
     * ทุกคนที่เปิดรายงานนี้ได้ต้องมีทางไปถึงจากหน้าเลือกรายงาน ไม่ใช่ต้องรู้ URL เอง
     */
    public function test_the_entry_card_appears_for_everyone_who_can_open_it(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $actors = [
            $this->user(null, false, 'admin'),
            $this->user($department, true),
            $this->user($department),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor)
                ->get(route('reports.index'))
                ->assertOk()
                ->assertSee('รายงานปฏิบัติงาน')
                ->assertSee(route('reports.operational'), false);
        }
    }

    /**
     * พนักงานเห็นสองรายงานเท่านั้น — รายงานตัวเอง และรายงานปฏิบัติงาน
     * ต้องไม่มีทางเข้ารายงานภาพรวมองค์กรหรือรายงานของคนอื่น
     */
    public function test_a_member_landing_offers_exactly_two_reports(): void
    {
        $member = $this->user(Department::create(['department_name' => 'IT']));

        $response = $this->actingAs($member)->get(route('reports.index'))->assertOk();

        $response->assertSee('รายงานตัวเอง')
            ->assertSee('รายงานปฏิบัติงาน')
            ->assertSee(route('reports.my'), false)
            ->assertSee(route('reports.operational'), false)
            ->assertDontSee(route('reports.organization'), false)
            ->assertDontSee(route('reports.employees.index'), false);

        $this->assertSame(2, substr_count($response->getContent(), 'report-choice report-choice--'));

        $this->actingAs($member)->get(route('reports.organization'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.employees.index'))->assertForbidden();

        // หัวหน้าแผนกยังได้ชุดเดิมสามใบ ไม่ถูกเพิ่ม "รายงานตัวเอง" ให้ซ้ำกับ
        // รายงานรายบุคคลที่ครอบคลุมตัวเองอยู่แล้ว
        $head = $this->user($member->department, true);

        $headResponse = $this->actingAs($head)->get(route('reports.index'))->assertOk();

        $this->assertSame(3, substr_count($headResponse->getContent(), 'report-choice report-choice--'));
        $headResponse->assertSee(route('reports.operational'), false)
            ->assertDontSee(route('reports.my'), false);
    }

    /**
     * พนักงานได้หน้าที่ตอบคำถามของตัวเอง ไม่ใช่แดชบอร์ดแบบที่หัวหน้าเห็น
     *
     * ทั้งตัวกรองแผนก ตารางรายคน และกราฟเทียบคนในทีม ไม่มีความหมายเมื่อดูของ
     * คนเดียว การแสดงไว้จึงเป็นภาระในการอ่านเปล่า ๆ
     */
    public function test_a_member_gets_a_slimmer_page_than_a_department_head(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);

        $this->log($member, $department, ['title' => 'งานของฉันเอง']);

        $memberPage = $this->actingAs($member)->get(route('reports.operational'))->assertOk();

        $memberPage->assertSee('รายงานปฏิบัติงานของฉัน')
            // ไม่มีตัวกรองแผนก เพราะขอบเขตถูกล็อกไว้ที่ตัวเองแล้ว
            ->assertDontSee('id="reportDepartment"', false)
            // ไม่มีตารางรายคนและกราฟเทียบคน
            ->assertDontSee('data-operational-member-table', false)
            ->assertDontSee('operationalMemberChart', false)
            ->assertDontSee('operationalInterruptChart', false)
            // ตัวเลขที่ไว้บริหารภาระงานของทีมเป็นของหัวหน้า
            ->assertDontSee('เวลาที่ไม่ได้ลงโปรเจกต์')
            ->assertDontSee('จากผู้บันทึก')
            // สิ่งที่ต้องเหลือ: ตารางการตรวจ ตัวเลขของตัวเอง และกราฟเดียว
            ->assertSee('การตรวจงานประจำ')
            ->assertSee('ชั่วโมงงานของฉัน')
            ->assertSee('operationalDailyChart', false);

        // หัวหน้าแผนกยังได้หน้าเต็มเหมือนเดิมทุกส่วน
        $headPage = $this->actingAs($head)->get(route('reports.operational'))->assertOk();

        $headPage->assertSee('id="reportDepartment"', false)
            ->assertSee('data-operational-member-table', false)
            ->assertSee('operationalMemberChart', false)
            ->assertSee('เวลาที่ไม่ได้ลงโปรเจกต์')
            ->assertSee('การตรวจงานประจำ');
    }

    /**
     * ตารางการตรวจต้องบอกได้ทั้ง "วันนี้เหลืออะไร" และ "ที่ผ่านมาตรวจอะไรไปแล้ว"
     */
    public function test_routine_checks_are_listed_with_date_and_status(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $template = WorkLogTemplate::create([
            'user_id' => $member->id,
            'title' => 'เช็คคอมพิวเตอร์ห้องบัญชี',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::EVERYDAY,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
            'is_active' => true,
        ]);

        app(WorkLogRoutineMaterializer::class)->materializeToday($member);

        $log = WorkLog::query()->where('work_log_template_id', $template->id)->firstOrFail();

        $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('การตรวจงานประจำ')
            ->assertSee('เช็คคอมพิวเตอร์ห้องบัญชี')
            ->assertSee('08:30 - 08:50')
            ->assertSee('เกินเวลา')
            // คอลัมน์วันที่คือสิ่งที่ทำให้ตารางใช้เป็นหลักฐานย้อนหลังได้
            ->assertSee('<th scope="col">วันที่</th>', false)
            ->assertSee('report-checklist__today', false);

        $this->actingAs($member)->post(route('daily-logs.complete', $log))->assertRedirect();

        $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('ตรวจแล้ว')
            ->assertDontSee('ยังไม่ตรวจ');
    }

    /**
     * ตารางแบ่งหน้าครั้งละ 10 แถว ด้วยตัวแบ่งหน้าตัวเดียวกับตารางภาระงานรายคน
     *
     * แถวถูก render มาครบทุกแถว แล้วให้ table-pager.js ซ่อน/แสดง จึงตรวจได้จาก
     * จำนวนแถวใน HTML กับการมีอยู่ของปุ่มเปลี่ยนหน้า
     */
    public function test_the_checklist_table_pages_ten_rows_at_a_time(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        // สิบสองรายการของวันนี้ — มากกว่าหนึ่งหน้า
        foreach (range(1, 12) as $index) {
            $this->log($member, $department, [
                'title' => 'งานประจำที่ '.$index,
                'source' => 'template',
            ]);
        }

        $response = $this->actingAs($member)->get(route('reports.operational'))->assertOk();

        $response->assertSee('data-checklist-table', false)
            ->assertSee('data-page-size="10"', false)
            ->assertSee('data-checklist-pager', false)
            ->assertSee('data-checklist-previous', false)
            ->assertSee('data-checklist-next', false);

        $this->assertSame(12, substr_count($response->getContent(), 'data-checklist-row'));
    }

    /**
     * ตารางเป็นรายละเอียดที่เปิดดูเมื่อถูกถาม จึงอยู่ท้ายหน้า ไม่ใช่บนสุด
     */
    public function test_the_checklist_table_closes_the_page(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->log($member, $department, ['title' => 'งานประจำหนึ่ง', 'source' => 'template']);

        $content = $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->getContent();

        $this->assertGreaterThan(
            strpos($content, 'report-kpi-band'),
            strpos($content, 'report-checklist'),
            'ตารางการตรวจต้องอยู่ใต้แถบตัวเลขและกราฟ'
        );
    }

    /**
     * เทสต์กันการปนเปื้อน — เหตุผลตั้งต้นของการแยกรายงาน
     *
     * เปิดรายงานภาพรวมองค์กรสองครั้ง ครั้งแรกไม่มีบันทึกงานประจำวันเลย ครั้งที่สอง
     * มีบันทึกจำนวนมาก ตัวเลข KPI ของโครงการต้องเท่ากันทุกตัว
     */
    public function test_project_report_numbers_do_not_change_when_work_logs_exist(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->task($member, $department, 2);
        $this->task($member, $department, 4);

        $before = $this->actingAs($admin)->get(route('reports.organization'))->assertOk();
        $beforeNumbers = $this->projectKpiSnapshot($before->getContent());

        // เพิ่มบันทึกงานปฏิบัติการจำนวนมาก
        for ($index = 0; $index < 10; $index++) {
            $this->log($member, $department, ['duration_minutes' => 60, 'title' => "งานปฏิบัติการ {$index}"]);
        }

        $after = $this->actingAs($admin)->get(route('reports.organization'))->assertOk();

        $this->assertSame(
            $beforeNumbers,
            $this->projectKpiSnapshot($after->getContent()),
            'ตัวเลข KPI ของรายงานโครงการต้องไม่เปลี่ยนเมื่อมีบันทึกงานประจำวัน'
        );
    }

    public function test_unlinked_time_separates_project_linked_work(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');
        $project = WorkOrderList::create(['user_id' => $member->id, 'name' => 'ระบบใหม่']);

        // 4 ชั่วโมงไม่ผูกโปรเจกต์ + 1 ชั่วโมงผูกโปรเจกต์
        $this->log($member, $department, ['duration_minutes' => 240]);
        $this->log($member, $department, ['duration_minutes' => 60, 'work_order_list_id' => $project->id]);

        $this->actingAs($admin)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('เวลาที่ไม่ได้ลงโปรเจกต์')
            // 240 นาที = 4.0 ชม. คิดเป็น 80% ของ 5 ชั่วโมง
            ->assertSee('4.0')
            ->assertSee('80%');
    }

    public function test_the_kind_filter_narrows_the_report(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->log($member, $department, ['kind' => 'routine', 'title' => 'งานประจำเช้า', 'duration_minutes' => 60]);
        $this->log($member, $department, ['kind' => 'field', 'title' => 'ไปส่งรถ', 'duration_minutes' => 180]);

        $this->actingAs($admin)
            ->get(route('reports.operational', ['kind' => 'field']))
            ->assertOk()
            ->assertSee('ไปส่งรถ')
            ->assertDontSee('งานประจำเช้า');
    }

    public function test_the_category_filter_narrows_the_report(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');
        $category = WorkLogCategory::query()->firstOrFail();
        $other = WorkLogCategory::query()->where('id', '!=', $category->id)->firstOrFail();

        $this->log($member, $department, [
            'title' => 'งานในหมวดที่เลือก',
            'work_log_category_id' => $category->id,
            'duration_minutes' => 60,
        ]);
        $this->log($member, $department, [
            'title' => 'งานในหมวดอื่น',
            'work_log_category_id' => $other->id,
            'duration_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.operational', ['category' => $category->id]))
            ->assertOk()
            ->assertSee('งานในหมวดที่เลือก')
            ->assertDontSee('งานในหมวดอื่น');
    }

    /**
     * หัวหน้าแผนกถูกบังคับขอบเขตไว้ที่แผนกตัวเอง ส่งพารามิเตอร์แผนกอื่นมาต้องไม่ได้ผล
     */
    public function test_a_department_head_cannot_widen_the_scope_with_a_parameter(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $salesMember = $this->user($sales);
        $itHead = $this->user($it, true);

        $this->log($salesMember, $sales, ['title' => 'งานของเซลส์', 'duration_minutes' => 300]);

        $this->actingAs($itHead)
            ->get(route('reports.operational', ['department' => $sales->id]))
            ->assertOk()
            ->assertDontSee('งานของเซลส์');
    }

    public function test_the_report_survives_an_empty_period(): void
    {
        $admin = $this->user(null, false, 'admin');

        $this->actingAs($admin)
            ->get(route('reports.operational', ['period' => 'last_month']))
            ->assertOk()
            ->assertSee('ยังไม่มีบันทึกงานในช่วงเวลานี้');
    }

    public function test_the_most_frequent_titles_are_listed(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        for ($index = 0; $index < 3; $index++) {
            $this->log($member, $department, ['title' => 'ตรวจสอบเครื่องคอม', 'duration_minutes' => 30]);
        }
        $this->log($member, $department, ['title' => 'ติดตั้ง Software', 'duration_minutes' => 45]);

        $this->actingAs($admin)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('งานที่ทำบ่อยที่สุด')
            ->assertSee('ตรวจสอบเครื่องคอม')
            ->assertSee('3 ครั้ง');
    }

    public function test_the_csv_export_streams_with_a_bom_and_thai_headers(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->log($member, $department, [
            'title' => 'ไปส่งรถที่ศูนย์บริการ',
            'kind' => 'field',
            'duration_minutes' => 165,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.operationalExportCsv'))
            ->assertOk();

        $content = $response->streamedContent();

        $this->assertStringStartsWith(chr(0xEF).chr(0xBB).chr(0xBF), $content);
        $this->assertStringContainsString('วันที่', $content);
        $this->assertStringContainsString('หมวดงาน', $content);
        $this->assertStringContainsString('ไปส่งรถที่ศูนย์บริการ', $content);
        $this->assertStringContainsString('งานนอกสถานที่', $content);
        $this->assertStringContainsString('165', $content);
    }

    /**
     * เวลาใน CSV ต้องเป็นเวลาทำการ ไม่ใช่ค่า UTC ที่เก็บในฐานข้อมูล
     */
    public function test_the_csv_shows_bangkok_clock_times(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');
        $day = TodayWorkspace::businessNow()->format('Y-m-d');

        $this->actingAs($member)->post(route('daily-logs.store'), [
            'title' => 'ประชุมเช้า',
            'kind' => 'routine',
            'work_date' => $day,
            'start_time' => '09:00',
            'end_time' => '10:20',
        ])->assertRedirect();

        $content = $this->actingAs($admin)
            ->get(route('reports.operationalExportCsv'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('09:00', $content);
        $this->assertStringContainsString('10:20', $content);
    }

    /**
     * ตัวกรองความสำคัญเป็นของงานโครงการ ต้องไม่โผล่ในรายงานนี้
     * แต่ตัวกรองร่วมต้องยังทำงานเหมือนเดิมบนหน้ารายงานโครงการ
     */
    public function test_the_shared_filter_partial_adapts_without_breaking_the_project_report(): void
    {
        $admin = $this->user(null, false, 'admin');

        $this->actingAs($admin)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('ประเภทงาน')
            ->assertSee('หมวดงาน')
            ->assertDontSee('reportPriority', false)
            ->assertSee(route('reports.operationalExportCsv'), false);

        // หน้ารายงานโครงการต้องยังมีตัวกรองความสำคัญและปุ่ม export ของตัวเอง
        $this->actingAs($admin)
            ->get(route('reports.organization'))
            ->assertOk()
            ->assertSee('reportPriority', false)
            ->assertSee('ความสำคัญ')
            ->assertSee(route('reports.exportCsv'), false);
    }

    /**
     * บรรทัดอ้างอิงบนรายงานรายบุคคล
     *
     * ต้องเป็นข้อมูลอ่านอย่างเดียวที่ไม่แตะตัวเลขผลงานโครงการในหน้าเดียวกัน
     */
    public function test_the_employee_report_links_to_operational_hours_without_changing_its_own_numbers(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->task($member, $department, 4);

        $before = $this->actingAs($admin)->get(route('reports.employee', $member))->assertOk();
        $beforeNumbers = $this->projectKpiSnapshot($before->getContent());

        $this->log($member, $department, ['duration_minutes' => 150]);

        $after = $this->actingAs($admin)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertSee('งานปฏิบัติการของ '.$member->name)
            ->assertSee('2 ชม. 30 น.')
            ->assertSee('ดูรายงานภาระงานปฏิบัติการ');

        parse_str((string) parse_url($after->viewData('operationalUrl'), PHP_URL_QUERY), $operationalQuery);
        $this->assertSame((string) $member->id, (string) ($operationalQuery['owner'] ?? null));

        $this->assertSame(
            $beforeNumbers,
            $this->projectKpiSnapshot($after->getContent()),
            'ตัวเลขผลงานของพนักงานต้องไม่เปลี่ยนเพราะบันทึกงานประจำวัน'
        );
    }

    /**
     * viewer ดูรายงานรายบุคคลได้ แต่ต้องไม่เห็นบรรทัดที่ลิงก์ไปรายงานที่เข้าไม่ได้
     */
    public function test_the_employee_report_hides_the_operational_line_from_viewer(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $viewer = $this->user($department, false, 'viewer');

        $this->log($member, $department, ['duration_minutes' => 150]);

        $this->actingAs($viewer)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertDontSee('ดูรายงานภาระงานปฏิบัติการ');
    }

    /**
     * ตารางย่อในรายงานรายบุคคลไล่เรียงตามวัน และจำกัดจำนวนแถวไว้
     */
    public function test_the_employee_block_lists_recent_days_up_to_the_row_limit(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');
        $limit = OperationalWorkloadReportService::EMPLOYEE_ROW_LIMIT;

        // สร้างมากกว่าเพดานหนึ่งวัน เพื่อพิสูจน์ว่าแถวถูกตัดและวันเก่าสุดหลุดออก
        for ($daysAgo = 0; $daysAgo <= $limit; $daysAgo++) {
            $this->log($member, $department, [
                'title' => 'งานวันที่ '.$daysAgo,
                'duration_minutes' => 30,
                'work_date' => TodayWorkspace::businessNow()->subDays($daysAgo)->format('Y-m-d'),
            ]);
        }

        $response = $this->actingAs($admin)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertSee('บันทึกล่าสุด')
            // วันล่าสุดต้องอยู่ในตาราง
            ->assertSee('งานวันที่ 0')
            // วันที่เก่ากว่าเพดานต้องถูกตัดออก
            ->assertDontSee('งานวันที่ '.$limit);

        // นับเฉพาะแถวในตารางของบล็อกงานปฏิบัติการ
        $table = $response->getContent();
        $section = substr($table, strpos($table, 'employee-operational__table'));
        $this->assertSame($limit, substr_count(substr($section, 0, strpos($section, '</table>')), '<tr>') - 1);
    }

    /**
     * กราฟของบล็อกนี้ใช้ island แยกจากกราฟงานโครงการ เพื่อไม่ให้ข้อมูลสองโดเมนปนกัน
     */
    public function test_the_employee_block_ships_its_own_chart_island(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->log($member, $department, ['duration_minutes' => 60]);

        $this->actingAs($admin)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertSee('id="employee-operational-chart-data"', false)
            ->assertSee('id="employee-report-chart-data"', false)
            ->assertSee('employeeOperationalChart', false);
    }

    public function test_the_employee_block_shows_an_empty_state_without_logs(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->actingAs($admin)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertSee('ยังไม่มีบันทึกงานปฏิบัติการในช่วงนี้')
            // ปุ่มไปรายงานเต็มต้องยังอยู่แม้ยังไม่มีข้อมูล
            ->assertSee('ดูรายงานภาระงานปฏิบัติการ');
    }

    /**
     * ผู้ร่วมงานต้องปรากฏในตารางย่อด้วย ไม่งั้นจะดูเหมือนคนเดียวทำ
     */
    public function test_the_employee_table_names_participants(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $colleague = $this->user($department);
        $colleague->update(['name' => 'สมหญิง รักงาน']);
        $admin = $this->user(null, false, 'admin');

        $log = $this->log($member, $department, ['duration_minutes' => 40]);
        app(WorkLogParticipantService::class)->sync($log, $member, [$colleague->id]);

        $this->actingAs($admin)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertSee('ทำด้วยกันกับ สมหญิง รักงาน');
    }

    /**
     * ดึงเฉพาะตัวเลขในแถบ KPI ของรายงานโครงการมาเทียบ
     *
     * @return array<int, string>
     */
    public function test_routine_summary_counts_late_start_late_completion_skipped_and_unclosed(): void
    {
        $department = Department::create(['department_name' => 'ปฏิบัติการ']);
        $head = $this->user($department, head: true);
        $member = $this->user($department);
        $template = WorkLogTemplate::create([
            'user_id' => $member->id,
            'title' => 'ตรวจระบบเช้า',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::EVERYDAY,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 50,
            'is_active' => true,
        ]);

        // งานประจำมีได้วันละหนึ่งรายการต่อคนต่อแม่แบบ (unique index ของ work_logs)
        // ตัวอย่างจึงเป็นแม่แบบเดียวกันของห้าวันทำการล่าสุดในเดือนนี้
        $today = TodayWorkspace::businessNow();
        $planned = function (int $daysAgo) use ($template, $today): array {
            $day = $today->copy()->subDays($daysAgo);
            $start = $day->copy()->setTime(8, 30);

            return [
                'work_log_template_id' => $template->id,
                'source' => 'routine',
                'work_date' => $day->format('Y-m-d'),
                'planned_start_at' => $start->utc(),
                'planned_end_at' => $start->copy()->addMinutes(50)->utc(),
            ];
        };

        // เสร็จตรงเวลา — ไม่มีเหตุผลใดถูกบันทึกไว้
        $this->log($member, $department, array_merge($planned(0), [
            'title' => 'ตรงเวลา', 'status' => 'done', 'duration_minutes' => 40,
        ]));
        // เริ่มช้า แต่ปิดงานแล้ว
        $this->log($member, $department, array_merge($planned(1), [
            'title' => 'เริ่มช้า', 'status' => 'done', 'duration_minutes' => 60,
            'late_start_reason' => 'ประชุม',
        ]));
        // เสร็จเกินเวลา
        $this->log($member, $department, array_merge($planned(2), [
            'title' => 'เกินเวลา', 'status' => 'done', 'duration_minutes' => 90,
            'late_completion_reason' => 'งานมากกว่าที่ประเมิน',
        ]));
        // ไม่ได้ทำวันนี้
        $this->log($member, $department, array_merge($planned(3), [
            'title' => 'ไม่ได้ทำ', 'status' => 'skipped', 'skip_reason' => 'ลางาน',
        ]));
        // ค้าง — เลยเวลาตามแผนแล้วแต่ยังไม่มีใครตอบว่าเกิดอะไรขึ้น
        $this->log($member, $department, array_merge($planned(4), [
            'title' => 'ค้างไว้', 'status' => 'open',
        ]));
        // งานที่ผู้ใช้บันทึกเอง ต้องไม่ถูกนับรวมในสรุปงานประจำ
        $this->log($member, $department, ['title' => 'งานที่บันทึกเอง', 'status' => 'done', 'duration_minutes' => 30]);

        $response = $this->actingAs($head)->get(route('reports.operational'))->assertOk();
        $summary = $response->viewData('routineSummary');

        $this->assertSame(5, $summary['total']);
        $this->assertSame(1, $summary['on_time']);
        $this->assertSame(1, $summary['late_start']);
        $this->assertSame(1, $summary['late_completion']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['unclosed']);
        // เฉลี่ยจากรายการที่ทำเสร็จเท่านั้น (40 + 60 + 90) / 3
        $this->assertSame(63, $summary['average_minutes']);
        // ปิดรายการแล้ว 4 จาก 5 (เสร็จ 3 + ไม่ได้ทำ 1)
        $this->assertSame(80, $summary['completion_rate']);

        $response->assertSee('อัตราการทำครบ')->assertSee('ยังไม่ปิดรายการ');
    }

    public function test_routine_focus_filter_narrows_the_report_to_one_question(): void
    {
        $department = Department::create(['department_name' => 'ปฏิบัติการ']);
        $head = $this->user($department, head: true);
        $member = $this->user($department);
        $template = WorkLogTemplate::create([
            'user_id' => $member->id,
            'title' => 'ตรวจเอกสาร',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::EVERYDAY,
            'default_start_time' => '09:00',
            'default_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $today = TodayWorkspace::businessNow();
        $planned = fn (int $daysAgo): array => [
            'work_log_template_id' => $template->id,
            'source' => 'routine',
            'work_date' => $today->copy()->subDays($daysAgo)->format('Y-m-d'),
        ];
        $this->log($member, $department, array_merge($planned(0), ['title' => 'ตรงเวลา', 'status' => 'done', 'duration_minutes' => 20]));
        $this->log($member, $department, array_merge($planned(1), [
            'title' => 'เริ่มสาย', 'status' => 'done', 'duration_minutes' => 25, 'late_start_reason' => 'ติดงานอื่น',
        ]));

        $filtered = $this->actingAs($head)
            ->get(route('reports.operational', ['routine_focus' => 'late_start']))
            ->assertOk();

        $this->assertSame(1, $filtered->viewData('totalCount'));
        $this->assertSame(1, $filtered->viewData('routineSummary')['total']);

        // ตัวกรองต้องมีผลกับ CSV ชุดเดียวกับหน้าจอ ไม่ใช่ส่งออกทั้งช่วงเวลา
        $rows = app(OperationalWorkloadReportService::class)->exportRows(
            new Request(['routine_focus' => 'late_start']),
            $department->id
        );

        $this->assertSame(['เริ่มสาย'], $rows->pluck('title')->all());
    }

    private function projectKpiSnapshot(string $html): array
    {
        preg_match_all('/report-kpi__value">([^<]*)</u', $html, $matches);

        return array_map('trim', $matches[1] ?? []);
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
