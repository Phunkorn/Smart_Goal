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
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
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
            // หน้าแรกคือรายชื่อให้เลือก ไม่ใช่ตัวเลขรวมของทั้งองค์กรอีกต่อไป
            ->assertSee('วันนี้ใครทำอะไรไปแล้วบ้าง')
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
            // ชื่อคนคือสิ่งที่หน้าแรกแสดง ส่วนชื่อบันทึกงานย้ายไปอยู่หน้าของคนที่ถูกเลือก
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
     * พนักงานลงมาที่หน้าของตัวเองเลย ส่วนหัวหน้าลงมาที่รายชื่อก่อน
     *
     * พนักงานถูกล็อกขอบเขตไว้ที่ตัวเองโดย ReportController::forcedOwnerId()
     * หน้าของเขาจึงข้ามขั้น "เลือกคน" ไปที่ตารางรายวันทันที
     */
    public function test_a_member_lands_on_their_own_days_while_a_head_lands_on_the_people_list(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);

        $this->routineLog($member, $department, ['title' => 'งานของฉันเอง']);

        $memberPage = $this->actingAs($member)->get(route('reports.operational'))->assertOk();

        $memberPage->assertSee('รายงานปฏิบัติงานของฉัน')
            // ตัวกรองเหลือช่วงวันอย่างเดียวแล้วทั้งสองบทบาท
            ->assertDontSee('id="reportDepartment"', false)
            // ไม่ต้องเลือกคน เพราะขอบเขตถูกล็อกไว้ที่ตัวเองอยู่แล้ว
            ->assertDontSee('data-operational-people-table', false)
            ->assertSee('data-operational-days-table', false)
            ->assertSee('งานประจำรายวัน');

        $headPage = $this->actingAs($head)->get(route('reports.operational'))->assertOk();

        $headPage->assertSee('data-operational-people-table', false)
            ->assertSee('วันนี้ใครทำอะไรไปแล้วบ้าง')
            // ยังไม่เลือกใคร จึงยังไม่มีตารางรายวัน
            ->assertDontSee('data-operational-days-table', false);

        // กดเลือกคนแล้วจึงสลับไปหน้ารายวันของคนนั้น
        $this->actingAs($head)
            ->get(route('reports.operational', ['owner' => $member->id]))
            ->assertOk()
            ->assertSee('data-operational-days-table', false)
            ->assertSee($member->name)
            ->assertDontSee('data-operational-people-table', false);
    }

    /**
     * ตารางรายวันต้องตอบได้ในบรรทัดเดียวว่าวันนั้นทำงานประจำครบไหม
     */
    public function test_the_day_table_counts_done_skipped_and_pending_per_day(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->routineLog($member, $department, ['title' => 'เช็คคอมพิวเตอร์', 'status' => 'done']);
        $this->routineLog($member, $department, ['title' => 'เช็คปริ้นเตอร์', 'status' => 'skipped']);
        $this->routineLog($member, $department, ['title' => 'เช็คเครือข่าย', 'status' => 'open']);

        $response = $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('งานประจำรายวัน')
            ->assertSee('<th scope="col">วัน</th>', false);

        $days = $response->viewData('routineDays');

        $this->assertCount(1, $days, 'ทั้งสามรายการเป็นของวันเดียวกัน จึงต้องยุบเป็นแถวเดียว');
        $this->assertSame(3, $days[0]['total']);
        $this->assertSame(1, $days[0]['done']);
        $this->assertSame(1, $days[0]['skipped']);
        $this->assertSame(1, $days[0]['pending']);
    }

    /**
     * หน้าของคนที่ถูกเลือกต้องบอกได้ว่า "งานประจำคืออะไรบ้าง และทำหรือยัง"
     *
     * ตารางรายวันบอกแค่ตัวเลข ซึ่งตอบได้ว่าครบไหม แต่ตอบไม่ได้ว่าค้างรายการไหน
     * หัวหน้าที่เห็นเลข "ค้าง 2" ต้องรู้ต่อได้ทันทีว่าสองรายการนั้นคืออะไร
     */
    public function test_the_person_page_lists_each_routine_item_and_its_status(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);

        $this->routineLog($member, $department, ['title' => 'เช็คคอมพิวเตอร์ห้องบัญชี', 'status' => 'done']);
        $this->routineLog($member, $department, ['title' => 'เช็คเครื่องสำรองไฟ', 'status' => 'open']);

        $this->actingAs($head)
            ->get(route('reports.operational', ['owner' => $member->id]))
            ->assertOk()
            ->assertSee('การตรวจงานประจำ')
            ->assertSee('เช็คคอมพิวเตอร์ห้องบัญชี')
            ->assertSee('เช็คเครื่องสำรองไฟ')
            // ทั้งตารางเป็นของคนเดียว คอลัมน์ผู้รับผิดชอบจึงไม่ต้องมี
            ->assertDontSee('<th scope="col">ผู้รับผิดชอบ</th>', false);

        /*
         * หน้ารายชื่อก็แสดงชื่อรายการของ "วันนี้" เหมือนกัน ต่างกันที่หน้าคนมีตาราง
         * ย้อนหลังพร้อมคอลัมน์วันที่ ซึ่งเป็นสิ่งที่หน้ารายชื่อไม่มี
         */
        $this->actingAs($head)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertDontSee('data-checklist-table', false);
    }

    /**
     * กราฟใบเดียว และต้องเป็นข้อมูลชุดเดียวกับการ์ดรายคน
     *
     * กราฟที่อ่านคนละชุดกับตารางใต้มันทำให้คนอ่านต้องแปลงหน่วยในหัวเองว่าแท่งนี้
     * คือการ์ดใบไหน จึงต้องยึดว่า labels/ตัวเลขของกราฟมาจาก routineCompliance ตัวเดียวกัน
     */
    public function test_the_chart_mirrors_the_people_board(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $colleague = $this->user($department);
        $head = $this->user($department, true);

        $this->routineLog($member, $department, ['title' => 'งานประจำ ก', 'status' => 'done']);
        $this->routineLog($member, $department, ['title' => 'งานประจำ ข', 'status' => 'open']);
        $this->routineLog($colleague, $department, ['title' => 'งานประจำ ค', 'status' => 'done']);

        $response = $this->actingAs($head)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('operationalTodayMemberChart', false)
            // กราฟชุดเดิมตอบคนละคำถามกับกระดาน จึงต้องไม่เหลือ canvas ไว้
            ->assertDontSee('operationalRoutineMemberChart', false)
            ->assertDontSee('operationalRoutineDailyChart', false)
            ->assertDontSee('operationalCategoryChart', false)
            ->assertDontSee('operationalMemberChart', false);

        $board = $response->viewData('routineCompliance');
        $chart = $response->viewData('chartData')['todayMembers'];

        // ลำดับและชื่อต้องตรงกับการ์ดทีละตัว ไม่ใช่แค่มีครบ
        $this->assertSame($board->pluck('name')->all(), $chart['labels']);
        $this->assertSame($board->pluck('today_done')->all(), $chart['done']);
        $this->assertSame(
            $board->map(fn (array $person): int => $person['today_total'] - $person['today_done'])->all(),
            $chart['pending']
        );

        // ไม่ได้เลือกใคร จึงไม่มีแท่งไหนถูกไฮไลต์
        $this->assertSame(0, array_sum($chart['highlight']));

        // เลือกคนแล้ว กราฟยังเป็นของทั้งแผนกเหมือนเดิม แต่ทำเครื่องหมายคนที่กำลังดูอยู่
        $selected = $this->actingAs($head)
            ->get(route('reports.operational', ['owner' => $member->id]))
            ->assertOk()
            ->assertSee('operationalTodayMemberChart', false)
            ->viewData('chartData')['todayMembers'];

        $this->assertContains($colleague->name, $selected['labels']);
        $this->assertSame(1, array_sum($selected['highlight']));
    }

    /**
     * รายละเอียดของการ์ดต้องถูก render มาพร้อมหน้า ไม่ใช่ไปขอเพิ่มตอนกด
     *
     * กล่องรายละเอียดย้าย DOM จาก template ในการ์ดมาวาง ถ้า Blade ไม่ได้ส่ง template
     * หรือส่งมาแค่รายการที่ตัดแล้ว กล่องจะเปิดมาว่างหรือแสดงไม่ครบโดยไม่มี error ให้เห็น
     */
    public function test_each_card_ships_its_full_detail_for_the_modal(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);

        // หกรายการ มากกว่าสี่บรรทัดที่การ์ดแสดง กล่องจึงต้องมีครบทั้งหก
        foreach (range(1, 6) as $index) {
            $this->routineLog($member, $department, [
                'title' => 'งานประจำที่ '.$index,
                'status' => $index === 1 ? 'done' : 'open',
                'duration_minutes' => 15,
            ]);
        }

        $response = $this->actingAs($head)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertSee('data-people-modal', false)
            ->assertSee('data-people-detail', false)
            ->assertSee('data-people-open', false);

        /*
         * ต้องชี้การ์ดของคนที่ตั้งใจตรวจ ไม่ใช่ template ตัวแรกในหน้า
         *
         * การ์ดเรียงตามชื่อ และชื่อมาจาก factory แบบสุ่ม การ์ดใบแรกจึงเป็นของหัวหน้า
         * (ที่ไม่มีงาน) ได้ ทำให้เทสต์ผ่านหรือล้มตามลำดับชื่อที่สุ่มได้ในรอบนั้น
         */
        $html = $response->getContent();
        $card = strpos($html, 'data-people-name="'.$member->name.'"');
        $this->assertNotFalse($card, 'ต้องมีการ์ดของพนักงานคนนี้');

        $start = strpos($html, 'data-people-detail', $card);
        $this->assertNotFalse($start, 'การ์ดต้องมี template ของรายละเอียด');

        $detail = substr($html, $start, strpos($html, '</template>', $start) - $start);

        foreach (range(1, 6) as $index) {
            $this->assertStringContainsString('งานประจำที่ '.$index, $detail);
        }

        // เวลาที่ใช้แยกตามงานต้องอยู่ในกล่องด้วย ไม่ใช่มีแต่รายการงาน
        $this->assertStringContainsString('ครั้ง', $detail);
    }

    /**
     * เวลาที่ใช้ทำงาน — ทั้งของวันนี้ ของทั้งช่วง และกางดูได้ว่าหมดไปกับงานอะไร
     *
     * จำนวนรายการอย่างเดียวตอบไม่ได้ว่างานหนักแค่ไหน งานห้ารายการสั้น ๆ กับงาน
     * รายการเดียวที่กินทั้งเช้าต้องไม่อ่านว่าเท่ากัน
     */
    public function test_the_people_board_reports_time_spent_and_its_breakdown(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $head = $this->user($department, true);

        $this->routineLog($member, $department, [
            'title' => 'ตรวจเครื่อง',
            'status' => 'done',
            'duration_minutes' => 90,
        ]);
        $this->routineLog($member, $department, [
            'title' => 'ตรวจเครื่อง',
            'status' => 'done',
            'duration_minutes' => 30,
        ]);
        $this->routineLog($member, $department, [
            'title' => 'ส่งรายงาน',
            'status' => 'open',
            'duration_minutes' => 15,
        ]);

        $board = $this->actingAs($head)
            ->get(route('reports.operational'))
            ->assertOk()
            // เวลารวมของช่วงต้องอ่านได้จากหน้าจริง ไม่ใช่มีแต่ใน view data
            ->assertSee(WorkLogDesign::durationLabel(135))
            ->viewData('routineCompliance');

        $person = $board->firstWhere('id', $member->id);

        $this->assertSame(135, $person['period_minutes']);
        $this->assertSame(
            WorkLogDesign::durationLabel(135),
            $person['period_minutes_label']
        );
        $this->assertSame(
            WorkLogDesign::durationLabel(135),
            $person['today_minutes_label']
        );

        // งานชื่อเดียวกันถูกยุบเป็นบรรทัดเดียว พร้อมจำนวนครั้งและเวลารวม
        $this->assertSame(
            [['title' => 'ตรวจเครื่อง', 'times' => 2, 'minutes' => 120], ['title' => 'ส่งรายงาน', 'times' => 1, 'minutes' => 15]],
            collect($person['time_breakdown'])
                ->map(fn (array $entry): array => [
                    'title' => $entry['title'],
                    'times' => $entry['times'],
                    'minutes' => $entry['minutes'],
                ])
                ->all()
        );

        // รายการของวันนี้แต่ละตัวบอกเวลาที่ใช้ของตัวเองด้วย
        $this->assertSame(
            WorkLogDesign::durationLabel(15),
            collect($person['today_items'])->firstWhere('title', 'ส่งรายงาน')['minutes_label']
        );
    }

    /**
     * ต้องนับงานทั้งสองชนิด ไม่ใช่เฉพาะงานประจำที่มาจากแม่แบบ
     *
     * หัวหน้าถามว่า "วันนี้ทำอะไรไปบ้าง" ซึ่งรวมงานนอกสถานที่และงานที่บันทึกเองด้วย
     * ของเดิมนับเฉพาะแม่แบบ คนที่ลงแต่งานนอกสถานที่จึงขึ้นศูนย์ทั้งแถวทั้งที่ทำงานอยู่จริง
     */
    public function test_the_board_counts_field_work_and_manual_logs_too(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $this->routineLog($member, $department, ['title' => 'งานประจำจริง', 'status' => 'done']);
        $this->log($member, $department, ['title' => 'ไปส่งของนอกสถานที่', 'kind' => 'field', 'status' => 'done']);

        $days = $this->actingAs($member)
            ->get(route('reports.operational'))
            ->assertOk()
            ->viewData('routineDays');

        $this->assertSame(2, $days[0]['total'], 'งานนอกสถานที่ต้องถูกนับด้วย');
        $this->assertSame(2, $days[0]['done']);
    }

    /**
     * กระดานหน้าแรกต้องบอกได้ว่า "วันนี้ใครทำอะไรไปแล้วบ้าง" โดยไม่ต้องกดเข้าไปทีละคน
     */
    public function test_the_board_lists_todays_items_per_person_without_drilling_in(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, true);
        $worker = $this->user($department);
        $idle = $this->user($department);

        $this->routineLog($worker, $department, ['title' => 'เช็คคอมพิวเตอร์', 'status' => 'done']);
        $this->log($worker, $department, ['title' => 'ไปส่งเครื่องที่สาขา', 'kind' => 'field', 'status' => 'open']);

        $response = $this->actingAs($head)
            ->get(route('reports.operational'))
            ->assertOk()
            // ชื่อรายการของวันนี้อยู่ในแถวเลย ไม่ได้ซ่อนไว้หลังปุ่ม
            ->assertSee('เช็คคอมพิวเตอร์')
            ->assertSee('ไปส่งเครื่องที่สาขา')
            // คนที่ยังไม่ได้ลงอะไรเลยต้องยังอยู่ในกระดาน เพราะนั่นคือคำตอบที่หัวหน้าต้องการ
            ->assertSee($idle->name)
            ->assertSee('ยังไม่มีงานของวันนี้');

        $board = collect($response->viewData('routineCompliance'));
        $row = $board->firstWhere('id', $worker->id);

        $this->assertSame(2, $row['today_total']);
        $this->assertSame(1, $row['today_done'], 'ทำไปแล้วหนึ่ง เหลือค้างอีกหนึ่ง');
        $this->assertSame(1, $row['today_pending']);
        $this->assertSame(0, $board->firstWhere('id', $idle->id)['today_total']);
    }

    /**
     * ตารางแบ่งหน้าครั้งละ 10 แถว ด้วยตัวแบ่งหน้าตัวเดียวกับรายงานอื่น
     *
     * แถวถูก render มาครบทุกแถว แล้วให้ table-pager.js ซ่อน/แสดง จึงตรวจได้จาก
     * จำนวนแถวใน HTML กับการมีอยู่ของปุ่มเปลี่ยนหน้า
     */
    public function test_both_tables_page_ten_rows_at_a_time(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, true);

        // สิบสองคนในแผนก — มากกว่าหนึ่งหน้า
        foreach (range(1, 12) as $index) {
            $this->user($department);
        }

        $peoplePage = $this->actingAs($head)->get(route('reports.operational'))->assertOk();

        $peoplePage->assertSee('data-operational-people-table', false)
            ->assertSee('data-page-size="10"', false)
            ->assertSee('data-operational-people-previous', false)
            ->assertSee('data-operational-people-next', false);

        // สิบสองคน บวกหัวหน้าที่เป็นพนักงานในแผนกเดียวกันหรือไม่ก็ได้ จึงตรวจว่าอย่างน้อยสิบสอง
        $this->assertGreaterThanOrEqual(
            12,
            substr_count($peoplePage->getContent(), 'data-operational-people-row'),
        );

        // ตารางรายวันใช้ตัวแบ่งหน้าชุดเดียวกัน ไม่มีโค้ดแบ่งหน้าชุดที่สอง
        $member = $this->user($department);
        foreach (range(1, 12) as $index) {
            $this->routineLog($member, $department, [
                'title' => 'งานประจำที่ '.$index,
                'work_date' => TodayWorkspace::businessNow()->subDays($index)->format('Y-m-d'),
            ]);
        }

        $daysPage = $this->actingAs($head)
            ->get(route('reports.operational', ['owner' => $member->id, 'period' => 'this_month']))
            ->assertOk();

        $daysPage->assertSee('data-operational-days-table', false)
            ->assertSee('data-operational-days-previous', false)
            ->assertSee('data-operational-days-next', false);
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

        // การ์ด KPI ถูกถอดออกจากหน้าจอแล้ว แต่การคำนวณยังต้องถูกต้องเหมือนเดิม
        // เพราะเป็นตัวเลขที่รายงานอื่นและไฟล์ CSV ใช้ร่วมกัน
        $response = $this->actingAs($admin)
            ->get(route('reports.operational'))
            ->assertOk();

        // 240 นาที = 4.0 ชม. คิดเป็น 80% ของ 5 ชั่วโมง
        $this->assertSame('4.0', $response->viewData('unlinkedHoursLabel'));
        $this->assertSame(80, $response->viewData('unlinkedShare'));
    }

    public function test_the_kind_filter_narrows_the_report(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->log($member, $department, ['kind' => 'routine', 'title' => 'งานประจำเช้า', 'duration_minutes' => 60]);
        $this->log($member, $department, ['kind' => 'field', 'title' => 'ไปส่งรถ', 'duration_minutes' => 180]);

        // ชื่อบันทึกงานปรากฏบนหน้าของคนที่ถูกเลือกเท่านั้น (ตาราง "งานที่ทำบ่อยที่สุด")
        $this->actingAs($admin)
            ->get(route('reports.operational', ['kind' => 'field', 'owner' => $member->id]))
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
            ->get(route('reports.operational', ['category' => $category->id, 'owner' => $member->id]))
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

        // ไม่มีพนักงานเลยในระบบ รายชื่อจึงว่าง และหน้าต้องบอกเหตุผลแทนที่จะเป็นตารางเปล่า
        $this->actingAs($admin)
            ->get(route('reports.operational', ['period' => 'last_month']))
            ->assertOk()
            ->assertSee('ยังไม่มีพนักงานในขอบเขตนี้');
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

        // ตารางนี้ผูกกับคนคนเดียว จึงอยู่บนหน้าของคนที่ถูกเลือก ไม่ใช่หน้ารายชื่อ
        $this->actingAs($admin)
            ->get(route('reports.operational', ['owner' => $member->id]))
            ->assertOk()
            ->assertSee('งานที่ทำบ่อยที่สุด')
            ->assertSee('ตรวจสอบเครื่องคอม')
            ->assertSee('3 ครั้ง');

        // และต้องไม่โผล่บนหน้ารายชื่อ ซึ่งตอบคนละคำถาม
        $this->actingAs($admin)
            ->get(route('reports.operational'))
            ->assertOk()
            ->assertDontSee('งานที่ทำบ่อยที่สุด');
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
            /*
             * เหลือตัวกรองช่วงวันอย่างเดียว มิติที่เหลือถูกถอดออกทั้งหมด
             *
             * ตรวจด้วย id ของตัวควบคุม ไม่ใช่คำภาษาไทย เพราะคำอย่าง "หมวดงาน"
             * ปรากฏในเมนูข้างและหน้าอื่นด้วย การตรวจด้วยคำจึงจับผิดตัว
             */
            ->assertDontSee('id="reportKind"', false)
            ->assertDontSee('id="reportCategory"', false)
            ->assertDontSee('id="reportOwner"', false)
            ->assertDontSee('id="reportRoutine"', false)
            ->assertDontSee('id="reportProject"', false)
            ->assertDontSee('id="reportRoutineFocus"', false)
            ->assertDontSee('id="reportPriority"', false)
            ->assertDontSee('id="reportDepartment"', false)
            // สิ่งที่ต้องเหลือ: ช่วงวัน และปุ่มดาวน์โหลดของหน้านี้
            ->assertSee('id="reportPeriod"', false)
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
     * รายงานรายบุคคลต้องไม่มีบล็อกงานปฏิบัติการอีกแล้ว
     *
     * บล็อกนั้นถูกถอดออกทั้งก้อนตามที่เจ้าของระบบสั่ง เพราะหน้ารายงานรายบุคคลตอบเรื่อง
     * ผลงานโครงการ ส่วนชั่วโมงงานประจำมีหน้ารายงานภาระงานปฏิบัติการของตัวเองอยู่แล้ว
     * เทสต์นี้กันไม่ให้มันหลุดกลับเข้ามาพร้อมกับตัวเลขที่ต้องดูแลอีกชุด
     */
    public function test_the_employee_report_no_longer_carries_the_operational_block(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);
        $admin = $this->user(null, false, 'admin');

        $this->task($member, $department, 4);
        $this->log($member, $department, ['duration_minutes' => 150]);

        $response = $this->actingAs($admin)
            ->get(route('reports.employee', $member))
            ->assertOk()
            ->assertDontSee('งานปฏิบัติการของ '.$member->name)
            ->assertDontSee('ดูรายงานภาระงานปฏิบัติการ')
            ->assertDontSee('employee-operational', false)
            ->assertDontSee('employeeOperationalChart', false)
            ->assertDontSee('employee-operational-chart-data', false);

        // ตัวเลขผลงานโครงการยังอยู่ครบ การถอดบล็อกไม่ได้แตะข้อมูลฝั่งโครงการ
        $this->assertNotEmpty($response->viewData('chartData'));
    }

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

        // แผงสรุปถูกถอดออกจากหน้าจอแล้ว การคำนวณข้างบนคือสิ่งที่ต้องคงไว้
        $response->assertDontSee('อัตราการทำครบ');
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

    /**
     * งานประจำจริง — บันทึกที่ผูกกับแม่แบบ ไม่ใช่บันทึกที่ผู้ใช้พิมพ์เอง
     *
     * รายงานนี้นับ "งานประจำ" จาก work_log_template_id ตรงกับ routineSummary()
     * บันทึกแบบ manual จึงไม่ถูกนับเป็นตัวหาร และไม่ขึ้นในตารางรายวัน
     */
    private function routineLog(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        $template = WorkLogTemplate::create([
            'user_id' => $owner->id,
            'title' => $overrides['title'] ?? 'งานประจำประจำวัน',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::EVERYDAY,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
            'is_active' => true,
        ]);

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
