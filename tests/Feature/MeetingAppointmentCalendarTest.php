<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\MeetingAppointmentCalendarQuery;
use App\Services\MeetingQueryService;
use App\Support\MeetingCalendarScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ปฏิทินประชุมและนัดหมาย กับการแยกตัวออกจากปฏิทินโปรเจกต์
 *
 * ไฟล์นี้แทน MyTasksCalendarMeetingsTest เดิม ซึ่งยืนยันพฤติกรรมตรงกันข้าม
 * (ประชุมถูกฝังและ fetch เข้าปฏิทินโปรเจกต์) พฤติกรรมนั้นถูกยกเลิกแล้ว
 * ข้อกำหนดใหม่คือการประชุมและนัดหมายต้องไม่อยู่ในแหล่งข้อมูลของปฏิทินโปรเจกต์เลย
 */
class MeetingAppointmentCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'IT']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /* ---------- ปฏิทินโปรเจกต์ต้องสะอาด ---------- */

    public function test_project_calendar_carries_no_meeting_and_no_appointment_data_source(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');
        $task = $this->projectTask($member, 'งานในโปรเจกต์');

        $html = $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'calendar']))
            ->assertOk()
            // งานของโปรเจกต์ต้องยังอยู่ครบ
            ->assertSee($task->job_topic)
            ->assertSee('data-calendar', false)
            ->getContent();

        // ไม่มีแหล่งข้อมูลประชุม/นัดหมายในหน้าเลย ไม่ใช่แค่ซ่อนด้วย CSS
        $this->assertStringNotContainsString('data-calendar-meetings', $html);
        $this->assertStringNotContainsString('data-meetings-endpoint', $html);
        $this->assertStringNotContainsString('data-calendar-day-meeting-list', $html);
        $this->assertStringNotContainsString('ประชุมทีม', $html);
        $this->assertStringNotContainsString('นัดหมอ', $html);
    }

    public function test_the_project_calendar_meeting_endpoint_no_longer_exists(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('mytasks.calendar.meetings'),
            'endpoint ที่เคยเติมประชุมเข้าปฏิทินโปรเจกต์ต้องถูกถอดออกจริง'
        );

        $this->actingAs($this->user())
            ->get('/my-tasks/calendar/meetings?start=2026-10-01&end=2026-10-31')
            ->assertNotFound();
    }

    public function test_admin_member_workspace_calendar_also_drops_the_meeting_feed(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $this->meeting($member, 'ประชุมของสมาชิก');

        $html = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department->id, $member->id, 'view' => 'calendar']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-calendar-meetings', $html);
        $this->assertStringNotContainsString('data-meetings-endpoint', $html);
        $this->assertStringNotContainsString('ประชุมของสมาชิก', $html);
    }

    /* ---------- ปฏิทินประชุมและนัดหมาย ---------- */

    public function test_the_meeting_calendar_shows_both_meetings_and_appointments(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        $events = $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10']))
            ->assertOk()
            ->assertSee('ประชุมทีม')
            ->assertSee('นัดหมอ')
            ->viewData('meetingCalendarData')['calendarEvents'];

        $this->assertEqualsCanonicalizing(
            [MeetingAppointmentCalendarQuery::TYPE_MEETING, MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT],
            $events->pluck('type')->all()
        );
    }

    public function test_each_calendar_event_names_its_type_in_words_not_only_in_colour(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        $events = $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10']))
            ->assertOk()
            ->viewData('meetingCalendarData')['calendarEvents'];

        $this->assertSame('ประชุม', $events->firstWhere('type', 'meeting')['typeLabel']);
        $this->assertSame('นัดหมาย', $events->firstWhere('type', 'appointment')['typeLabel']);
        $this->assertNotEmpty($events->firstWhere('type', 'meeting')['icon']);
        $this->assertNotEmpty($events->firstWhere('type', 'appointment')['icon']);
    }

    public function test_the_type_filter_narrows_the_calendar_to_one_domain(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        foreach ([
            MeetingAppointmentCalendarQuery::TYPE_ALL => ['meeting', 'appointment'],
            MeetingAppointmentCalendarQuery::TYPE_MEETING => ['meeting'],
            MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT => ['appointment'],
        ] as $type => $expectedTypes) {
            $data = $this->actingAs($member)
                ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10', 'type' => $type]))
                ->assertOk()
                ->viewData('meetingCalendarData');

            $this->assertSame($type, $data['calendarType']);
            $this->assertEqualsCanonicalizing($expectedTypes, $data['calendarEvents']->pluck('type')->unique()->all());
        }
    }

    public function test_an_unknown_type_filter_falls_back_to_showing_everything(): void
    {
        $member = $this->user();

        $this->assertSame(
            MeetingAppointmentCalendarQuery::TYPE_ALL,
            $this->actingAs($member)
                ->get(route('mytasks.index', ['view' => 'meeting', 'type' => 'ประชุมปลอม']))
                ->assertOk()
                ->viewData('meetingCalendarData')['calendarType']
        );
    }

    public function test_the_calendar_month_label_uses_the_buddhist_year(): void
    {
        $member = $this->user();

        $data = $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10']))
            ->assertOk()
            ->assertSee('ตุลาคม 2569')
            ->viewData('meetingCalendarData');

        $this->assertSame('ตุลาคม 2569', $data['calendarMonthLabel']);
        $this->assertSame('2026-09', $data['calendarPreviousMonth']);
        $this->assertSame('2026-11', $data['calendarNextMonth']);
    }

    public function test_an_unreadable_month_falls_back_to_the_current_bangkok_month(): void
    {
        $member = $this->user();

        foreach (['2569-13', 'ไม่ใช่เดือน', '2026-99'] as $badMonth) {
            $this->assertSame(
                '2026-10',
                $this->actingAs($member)
                    ->get(route('mytasks.index', ['view' => 'meeting', 'month' => $badMonth]))
                    ->assertOk()
                    ->viewData('meetingCalendarData')['calendarMonthValue']
            );
        }
    }

    public function test_the_calendar_events_endpoint_answers_without_a_full_page_reload(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        $payload = $this->actingAs($member)
            ->getJson(route('meeting-calendar.events', [
                'start' => '2026-10-01',
                'end' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('appointment', $payload['type']);
        $this->assertCount(1, $payload['events']);
        $this->assertSame('นัดหมอ', $payload['events'][0]['title']);
        $this->assertSame(route('appointments.show', Appointment::sole()), $payload['events'][0]['detailUrl']);
    }

    public function test_the_calendar_endpoint_rejects_a_bad_or_unbounded_range(): void
    {
        $member = $this->user();

        $this->actingAs($member)
            ->getJson(route('meeting-calendar.events', ['start' => '2026-10-31', 'end' => '2026-10-01']))
            ->assertStatus(422);

        $this->actingAs($member)
            ->getJson(route('meeting-calendar.events', ['start' => '2020-01-01', 'end' => '2026-12-31']))
            ->assertStatus(422);
    }

    public function test_nobody_sees_another_persons_appointments_on_the_calendar(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $admin = $this->user('admin');
        $this->appointment($owner, 'นัดส่วนตัว');

        $strangerEvents = $this->actingAs($stranger)
            ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10']))
            ->assertOk()
            ->viewData('meetingCalendarData')['calendarEvents'];

        $this->assertSame([], $strangerEvents->where('type', 'appointment')->pluck('title')->all());

        /*
         * admin ไม่มีมุมมองประชุมในหน้างานของฉัน (ดู MyTasksMeetingViewTest) จึงตรวจผ่าน
         * endpoint ของปฏิทินแทน — นัดหมายเป็นบันทึกส่วนตัว ไม่มีบทบาทใดเห็นของคนอื่น
         * แม้แต่ admin ซึ่งต่างจากการประชุมที่ admin เห็นได้ทั้งองค์กร
         */
        $this->assertSame([], $this->actingAs($admin)
            ->getJson(route('meeting-calendar.events', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()
            ->json('events'));
    }

    /* ---------- การ์ดเลือกประเภทใน "+ เพิ่ม" ---------- */

    public function test_the_add_button_offers_both_types_instead_of_opening_the_meeting_form(): void
    {
        $member = $this->user();

        $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting']))
            ->assertOk()
            ->assertSee('data-meeting-modal-trigger="calendarCreateTypeModal"', false)
            ->assertSee('data-calendar-create-kind="meeting"', false)
            ->assertSee('data-calendar-create-kind="appointment"', false)
            ->assertSee('สร้างประชุมและเชิญผู้เข้าร่วม')
            ->assertSee('บันทึกวัน เวลา และรายละเอียดนัดหมาย')
            // ฟอร์มของทั้งสองประเภทพร้อมอยู่ในหน้าแล้ว
            ->assertSee('id="createMeetingModal"', false)
            ->assertSee('id="createAppointmentModal"', false);
    }

    /* ---------- Export ---------- */

    public function test_the_export_document_contains_only_the_selected_items(): void
    {
        $member = $this->user();
        $keptMeeting = $this->meeting($member, 'ประชุมที่เลือกไว้');
        $this->meeting($member, 'ประชุมที่ไม่ได้เลือก');
        $keptAppointment = $this->appointment($member, 'นัดหมายที่เลือกไว้');
        $this->appointment($member, 'นัดหมายที่ไม่ได้เลือก', '2026-10-16');

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_ALL,
                'selected' => [
                    'meeting:'.$keptMeeting->id,
                    'appointment:'.$keptAppointment->id,
                ],
            ]))
            ->assertOk()
            ->assertSee('ประชุมที่เลือกไว้')
            ->assertSee('นัดหมายที่เลือกไว้')
            ->assertDontSee('ประชุมที่ไม่ได้เลือก')
            ->assertDontSee('นัดหมายที่ไม่ได้เลือก');
    }

    public function test_an_export_without_a_selection_still_covers_the_whole_date_range(): void
    {
        // ปุ่ม Export เดิมไม่ส่ง selected[] มาเลย URL ที่ผู้ใช้ bookmark ไว้ต้องยังใช้ได้
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_ALL,
            ]))
            ->assertOk()
            ->assertSee('ประชุมทีม')
            ->assertSee('นัดหมอ');
    }

    public function test_selecting_an_item_the_viewer_cannot_see_adds_nothing_to_the_document(): void
    {
        /*
         * การเลือกเป็นการ "ตัดออก" จากชุดที่จำกัดสิทธิ์มาแล้วเท่านั้น
         * ส่งคีย์ของรายการที่ไม่มีสิทธิ์เห็นมาจึงไม่มีอะไรให้จับคู่
         * (ดู CalendarPdfExportService::selectedEvents)
         */
        $outsideDepartment = Department::create(['department_name' => 'Finance']);
        $stranger = User::factory()->create([
            'role' => 'user',
            'department_id' => $outsideDepartment->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $privateMeeting = $this->meeting($stranger, 'ประชุมของแผนกอื่น');

        $member = $this->user();

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_ALL,
                'selected' => ['meeting:'.$privateMeeting->id],
            ]))
            ->assertOk()
            ->assertDontSee('ประชุมของแผนกอื่น');
    }

    public function test_a_malformed_selection_key_is_rejected(): void
    {
        $member = $this->user();

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'selected' => ['meeting:1 or 1=1'],
            ]))
            ->assertSessionHasErrors('selected.0');
    }

    public function test_meeting_only_export_uses_the_meeting_heading(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            ->assertSee('ปฏิทินการประชุม')
            ->assertSee('ประชุมทีม')
            ->assertDontSee('นัดหมอ')
            ->assertDontSee('ปฏิทินนัดหมาย');
    }

    public function test_appointment_only_export_never_uses_the_meeting_heading(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');
        $this->appointment($member, 'นัดหมอ');

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT,
            ]))
            ->assertOk()
            ->assertSee('ปฏิทินนัดหมาย')
            // ตารางที่คอลัมน์หัวข้อมาก่อน และรายละเอียดเกาะอยู่ใต้หัวข้อในช่องเดียวกัน
            ->assertSee('นัดหมอ')
            ->assertSee('calendar-export__title', false)
            ->assertSee('รายละเอียดนัดหมาย')
            ->assertSee('หัวข้อ')
            ->assertSee('สถานที่')
            ->assertSee('เวลา')
            ->assertDontSee('ประชุมทีม')
            ->assertDontSee('ปฏิทินการประชุม');
    }

    public function test_the_export_leads_with_each_title_then_its_details_grouped_by_day(): void
    {
        $member = $this->user();
        // สองรายการในวันเดียวกัน หัวข้อวันต้องขึ้นครั้งเดียว ไม่ใช่ซ้ำทุกรายการ
        $this->meeting($member, 'ประชุมเช้า', '2026-10-15 09:00');
        $this->meeting($member, 'ประชุมบ่าย', '2026-10-15 13:00');

        $html = $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_ALL,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'วันพฤหัสบดีที่ 15 ตุลาคม 2569'),
            'วันเดียวกันต้องมีหัวข้อวันเดียว ไม่ใช่ค่าที่ซ้ำทุกบรรทัดแบบคอลัมน์ในตาราง'
        );

        // "หัวข้อ" ต้องเป็นคอลัมน์แรกของตาราง ไม่ใช่คอลัมน์ที่ถูกดันไปกลางแถว
        $this->assertLessThan(
            strpos($html, 'เวลา'),
            strpos($html, 'หัวข้อ'),
            'คอลัมน์หัวข้อต้องมาก่อนคอลัมน์รายละเอียดประกอบ'
        );

        // เรียงตามเวลาภายในวันเดียวกัน
        $this->assertLessThan(
            strpos($html, 'ประชุมบ่าย'),
            strpos($html, 'ประชุมเช้า'),
        );

        // คอลัมน์ "วันที่" ที่ซ้ำค่าเดิมทุกแถวถูกแทนด้วยแถวหัวข้อวันแล้ว
        $this->assertStringContainsString('calendar-export__day-row', $html);
    }

    public function test_the_printed_export_uses_no_brand_colour(): void
    {
        /*
         * เอกสารนี้ถูกพิมพ์จริงและเครื่องพิมพ์สำนักงานส่วนใหญ่เป็นขาวดำ
         * สีแบรนด์จะกลายเป็นเทากลาง ๆ ที่แยกจากตัวอักษรปกติไม่ออก
         * ลำดับชั้นต้องมาจากน้ำหนักตัวอักษร เส้นตาราง และพื้นเทาเท่านั้น
         */
        $css = file_get_contents(resource_path('css/pages/meeting-calendar-export.css'));

        // สีน้ำเงินของระบบ ไม่ว่าจะเขียนด้วยตัวพิมพ์ใหญ่หรือเล็ก
        $this->assertStringNotContainsStringIgnoringCase('#1e4fd6', $css);
        $this->assertStringNotContainsStringIgnoringCase('#16389e', $css);
        $this->assertStringNotContainsStringIgnoringCase('#e7edfc', $css);

        // หัวตารางต้องซ้ำทุกหน้ากระดาษ ไม่งั้นหน้าที่สองขึ้นไปอ่านไม่รู้ว่าคอลัมน์ไหนคืออะไร
        $this->assertStringContainsString('display: table-header-group', $css);

        /*
         * รายงานรายนัดต้องขึ้นหน้าใหม่จริง และต้องประกาศทั้งสองพร็อพ เพราะเบราว์เซอร์
         * กับเครื่องพิมพ์ที่ใช้งานจริงยังอ่านคนละตัว
         */
        $this->assertStringContainsString('break-before: page', $css);
        $this->assertStringContainsString('page-break-before: always', $css);
    }

    /**
     * เคสนี้แทนเคสเดิม test_the_combined_export_labels_the_type_of_every_row_and_sorts_by_date
     *
     * กฎเดิมคือ "เรียงตามวันที่จากน้อยไปมากทั้งฉบับ" พร้อมคอลัมน์บอกประเภทรายแถว
     * ตอนนี้เอกสารถูกแยกเป็นส่วนของประชุมและส่วนของนัดหมาย เพราะสองอย่างนี้
     * ถูกใช้ต่างกันหลังพิมพ์ (ดู CalendarPdfExportService::groupByType)
     *
     * ผลที่ตามมาโดยเจตนา: ลำดับตามวันเป็นจริง "ภายในแต่ละส่วน" ไม่ใช่ทั้งฉบับ
     * และคอลัมน์ประเภทหายไปเพราะทั้งส่วนเป็นประเภทเดียวกันแล้ว
     */
    public function test_the_combined_export_splits_meetings_from_appointments_and_sorts_within_each_part(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมวันหลัง', '2026-10-20 10:00');
        $this->meeting($member, 'ประชุมวันแรก', '2026-10-02 10:00');
        $this->appointment($member, 'นัดวันหลัง', '2026-10-21', '08:30');
        $this->appointment($member, 'นัดวันแรก', '2026-10-05', '08:30');

        $html = $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_ALL,
            ]))
            ->assertOk()
            ->assertSee('ปฏิทินประชุมและนัดหมาย')
            // รายการถูกจัดกลุ่มตามวัน หัวข้อวันจึงเป็นวันแบบเต็ม ไม่ใช่คอลัมน์วันที่แบบย่อ
            ->assertSee('วันจันทร์ที่ 5 ตุลาคม 2569')
            ->assertSee('วันอังคารที่ 20 ตุลาคม 2569')
            ->getContent();

        // เอกสารรวมต้องมีสองส่วน และประชุมมาก่อนนัดหมายเสมอ ไม่ขึ้นกับลำดับข้อมูล
        $this->assertSame(2, substr_count($html, 'calendar-export__part"'));
        $this->assertLessThan(
            strpos($html, 'นัดวันแรก'),
            strpos($html, 'ประชุมวันหลัง'),
            'ส่วนของประชุมต้องมาก่อนส่วนของนัดหมายทั้งส่วน แม้วันที่จะหลังกว่า'
        );

        // ภายในแต่ละส่วนยังเรียงตามวันจากน้อยไปมาก
        $this->assertLessThan(
            strpos($html, 'ประชุมวันหลัง'),
            strpos($html, 'ประชุมวันแรก'),
        );
        $this->assertLessThan(
            strpos($html, 'นัดวันหลัง'),
            strpos($html, 'นัดวันแรก'),
        );
    }

    public function test_a_single_type_export_has_one_part_and_no_redundant_part_heading(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมทีม');

        $html = $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'calendar-export__part"'));
        // หัวเรื่องของเอกสารบอกประเภทอยู่แล้ว ไม่ต้องมีหัวข้อส่วนซ้ำ
        $this->assertStringNotContainsString('calendar-export__part-title', $html);
    }

    public function test_the_export_requires_a_valid_date_range(): void
    {
        $member = $this->user();

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', ['type' => 'all']))
            ->assertSessionHasErrors(['from_date', 'to_date']);

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', ['from_date' => '2026-10-31', 'to_date' => '2026-10-01']))
            ->assertSessionHasErrors('to_date');
    }

    public function test_the_export_only_contains_rows_inside_the_requested_range(): void
    {
        $member = $this->user();
        $this->appointment($member, 'นัดในช่วง', '2026-10-10');
        $this->appointment($member, 'นัดนอกช่วง', '2026-11-10');

        $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => 'all',
            ]))
            ->assertOk()
            ->assertSee('นัดในช่วง')
            ->assertDontSee('นัดนอกช่วง');
    }

    /**
     * เคสนี้แทนเคสเดิม test_the_export_summary_counts_attendees_without_listing_every_rsvp
     *
     * กฎเดิมคือ "เอกสารสรุปไม่แจกแจงคำตอบรายคน" ซึ่งยังจริงอยู่ แต่เหตุผลเปลี่ยน:
     * ตอนนี้เอกสารมีรายงานรายรายการที่บอก "ใครถูกเชิญ" ครบทุกชื่อ ส่วนใครมาจริง
     * รู้จากลายเซ็นในช่องสุดท้าย คำตอบรับ/ไม่ว่างจึงไม่จำเป็นบนกระดาษเลย
     * และการไม่ดึงมาตั้งแต่ต้นทำให้ไม่มีทางหลุด (ดู CalendarAttendeeRoster)
     *
     * ผู้สร้างก็ไม่เห็นสถานะในเอกสาร — คำตอบรายคนดูได้ที่หน้ารายละเอียดตามเดิม
     */
    public function test_the_export_itemises_attendee_names_and_never_prints_rsvp_status(): void
    {
        $creator = $this->user();
        $attendee = $this->user();
        $meeting = $this->meeting($creator, 'ประชุมทีม');
        $meeting->attendees()->attach($attendee);

        $this->actingAs($attendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดงาน Support'])
            ->assertRedirect();

        // แม้ผู้เปิดเอกสารเป็นผู้จัดประชุม สถานะก็ยังไม่ลงกระดาษ
        $this->actingAs($creator)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            // ตารางสรุปยังนับคนเหมือนเดิม
            ->assertSee('1 คน')
            // รายงานรายรายการบอกชื่อและช่องให้เซ็น
            ->assertSee($attendee->name)
            ->assertSee('ผู้ที่ถูกเชิญ')
            ->assertSee('ลงชื่อเข้าร่วม')
            ->assertDontSee('สถานะตอบรับ')
            ->assertDontSee('ไม่ว่าง')
            /*
             * "รอตอบรับ" คือลายนิ้วมือของบั๊กที่ต้องกันไว้โดยเฉพาะ:
             * ...AttendeeResponseDesign::statusLabel() fallback เป็นค่านี้เมื่อรับ null
             * ถ้าภายหลังมีใครดึงสถานะกลับเข้ามาแล้วเรียกเมธอดนั้นกับค่าที่เป็น null
             * ทุกคนจะกลายเป็น "รอตอบรับ" ซึ่งเป็นสถานะที่ถูกกุขึ้นมา
             */
            ->assertDontSee('รอตอบรับ')
            // เหตุผลที่ไม่ว่างเป็นข้อความอิสระของคนคนหนึ่ง ไม่ลงกระดาษที่ถ่ายสำเนาได้
            ->assertDontSee('ติดงาน Support');
    }

    public function test_a_department_bystander_sees_the_roster(): void
    {
        // ชื่อผู้เข้าร่วมเป็นข้อมูลระดับเดียวกับที่หน้ารายละเอียดแสดงให้ทุกคนที่ผ่าน view
        // อยู่แล้ว คนในแผนกของผู้จัดจึงเห็นได้ แต่ยังไม่มีสถานะให้ใครเห็นในเอกสาร
        $creator = $this->user();
        $attendee = $this->user();
        $bystander = $this->user();
        $meeting = $this->meeting($creator, 'ประชุมทีม');
        $meeting->attendees()->attach($attendee);

        $this->actingAs($bystander)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            ->assertSee($attendee->name)
            ->assertDontSee('สถานะตอบรับ')
            ->assertDontSee('รอตอบรับ');
    }

    public function test_every_exported_item_starts_a_new_printed_page(): void
    {
        $member = $this->user();
        $first = $this->meeting($member, 'ประชุมหนึ่ง', '2026-10-15 09:00');
        $second = $this->meeting($member, 'ประชุมสอง', '2026-10-16 09:00');
        $this->meeting($member, 'ประชุมที่ไม่ได้เลือก', '2026-10-17 09:00');

        $html = $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_ALL,
                'selected' => ['meeting:'.$first->id, 'meeting:'.$second->id],
            ]))
            ->assertOk()
            ->getContent();

        // บล็อกรายงานต้องมีเท่าจำนวนรายการที่เลือก ไม่ใช่ทั้งช่วงวันที่
        $this->assertSame(2, substr_count($html, 'calendar-export__item"'));
        $this->assertStringNotContainsString('ประชุมที่ไม่ได้เลือก', $html);
    }

    public function test_an_item_with_no_attendees_still_gets_a_section_that_says_so(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมที่ยังไม่เชิญใคร');

        $html = $this->actingAs($member)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            ->assertSee('ยังไม่มีผู้เข้าร่วม')
            ->getContent();

        // ยังได้บล็อกของตัวเอง ไม่งั้นจำนวนหน้าของเอกสารเดาไม่ได้
        $this->assertSame(1, substr_count($html, 'calendar-export__item"'));
    }

    public function test_appointment_items_report_their_own_attendees(): void
    {
        $owner = $this->user();
        $attendee = $this->user();
        $appointment = $this->appointment($owner, 'นัดวางระบบ');
        $appointment->update(['visibility' => Appointment::VISIBILITY_DEPARTMENT]);
        $appointment->attendees()->attach($attendee);

        $exportUrl = route('meeting-calendar.export', [
            'from_date' => '2026-10-01',
            'to_date' => '2026-10-31',
            'type' => MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT,
        ]);

        $this->actingAs($owner)
            ->get($exportUrl)
            ->assertOk()
            ->assertSee($attendee->name)
            ->assertSee('ลงชื่อเข้าร่วม')
            ->assertDontSee('สถานะตอบรับ');

        /*
         * viewer ในแผนกเดียวกันเห็นนัดหมายขอบเขตแผนกได้ และเห็นรายชื่อเหมือนกัน
         * เส้นทาง policy ของนัดหมายต่างจากประชุม จึงคุมแยกไว้
         *
         * ต้องสร้างเองเพราะ helper user() ผูกแผนกให้เฉพาะ role 'user'
         * viewer ที่ไม่มีแผนกจะมองไม่เห็นนัดหมายขอบเขตแผนกเลย แล้วเคสนี้จะผ่าน
         * แบบว่างเปล่าโดยไม่ได้ทดสอบอะไร
         */
        $departmentViewer = User::factory()->create([
            'role' => 'viewer',
            'department_id' => $this->department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($departmentViewer)
            ->get($exportUrl)
            ->assertOk()
            ->assertSee($attendee->name)
            ->assertDontSee('สถานะตอบรับ');
    }

    public function test_a_soft_deleted_attendee_is_omitted_from_the_printed_roster(): void
    {
        /*
         * ตรึงพฤติกรรมปัจจุบัน ไม่ใช่รับรองว่าถูก: User ใช้ SoftDeletes และ
         * attendees() ไม่ได้ withTrashed() ผู้เข้าร่วมที่บัญชีถูกลบจึงหลุดทั้งจาก
         * จำนวนและรายชื่อ ขณะที่แถว pivot ยังอยู่ การใส่ withTrashed() จะกระทบ
         * หน้ารายละเอียดและรอบ RSVP ด้วย จึงเกินขอบเขตของฟีเจอร์นี้
         *
         * สิ่งที่สำคัญคือจำนวนกับรายชื่อต้องไม่ขัดกันเอง
         */
        $creator = $this->user();
        $staying = $this->user();
        $leaving = $this->user();
        $meeting = $this->meeting($creator, 'ประชุมทีม');
        $meeting->attendees()->attach([$staying->id, $leaving->id]);

        $leaving->delete();

        $this->actingAs($creator)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            ->assertSee($staying->name)
            ->assertDontSee($leaving->name)
            // จำนวนต้องตรงกับรายชื่อที่พิมพ์ออกมา ไม่ใช่ "2 คน" แต่มีชื่อเดียว
            ->assertSee('ผู้ที่ถูกเชิญ (1 คน)');
    }

    public function test_the_calendar_json_lists_attendee_names(): void
    {
        // endpoint นี้เป็นผู้ผลิต payload ตัวที่สองคู่กับ JSON ที่ฝังในหน้า
        // ถ้าไม่มีเทสคุม รูปร่าง event ของสองเส้นทางจะ drift กันเงียบ ๆ
        $creator = $this->user();
        $attendee = $this->user();
        $meeting = $this->meeting($creator, 'ประชุมทีม');
        $meeting->attendees()->attach($attendee);

        $this->actingAs($creator)
            ->getJson(route('meeting-calendar.events', [
                'start' => '2026-10-01',
                'end' => '2026-10-31',
                'type' => MeetingAppointmentCalendarQuery::TYPE_MEETING,
            ]))
            ->assertOk()
            ->assertJsonPath('events.0.attendees.0.name', $attendee->name)
            // payload ไม่พาสถานะตอบรับไปฝั่ง client เลย จึงไม่มีอะไรให้หลุด
            ->assertJsonMissingPath('events.0.attendees.0.status')
            ->assertJsonMissingPath('events.0.showsAttendeeStatus');
    }

    public function test_the_calendar_payload_hydrates_attendees_without_a_query_per_item(): void
    {
        $member = $this->user();

        $measure = function (int $meetings, int $attendeesEach) use ($member): int {
            Meeting::query()->forceDelete();

            for ($index = 0; $index < $meetings; $index++) {
                $meeting = $this->meeting($member, 'ประชุม '.$index, '2026-10-15 09:00');
                $meeting->attendees()->attach(
                    collect(range(1, $attendeesEach))->map(fn (): int => $this->user()->id)->all()
                );
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(MeetingAppointmentCalendarQuery::class)->calendarEvents(
                MeetingCalendarScope::forViewer($member),
                CarbonImmutable::parse('2026-10-01'),
                CarbonImmutable::parse('2026-10-31'),
                MeetingAppointmentCalendarQuery::TYPE_MEETING
            );
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // จำนวนคิวรีต้องไม่โตตามจำนวนรายการหรือจำนวนผู้เข้าร่วม
        $this->assertSame($measure(1, 2), $measure(5, 8));
    }

    /* ---------- helpers ---------- */

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function meeting(User $creator, string $title, string $startsAt = '2026-10-15 10:00'): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'รายละเอียดการประชุม',
            'starts_at' => CarbonImmutable::parse($startsAt, MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse($startsAt, MeetingQueryService::BUSINESS_TIMEZONE)->addHour()->utc(),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ]);
    }

    private function appointment(
        User $owner,
        string $title,
        string $date = '2026-10-15',
        string $startTime = '09:30'
    ): Appointment {
        $appointment = new Appointment([
            'title' => $title,
            'appointment_date' => $date,
            'starts_at_time' => $startTime,
            'ends_at_time' => '10:30',
            'location' => 'โรงพยาบาล',
            'description' => 'รายละเอียดนัดหมาย',
        ]);
        $appointment->created_by = $owner->id;
        // แผนกเจ้าของมาจากผู้สร้าง เหมือนที่ AppointmentService ทำของจริง
        // ถ้าไม่ตั้ง นัดหมายที่ตั้งขอบเขตเป็น "แผนก" จะไม่มีแผนกให้จับคู่
        // แล้วเทสขอบเขตแผนกจะผ่านแบบว่างเปล่าโดยไม่ได้ทดสอบอะไร
        $appointment->department_id = $owner->department_id;
        $appointment->save();

        return $appointment;
    }

    private function projectTask(User $owner, string $topic): WorkOrder
    {
        $list = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'โปรเจกต์ทดสอบ',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        return WorkOrder::create([
            'work_order_list_id' => $list->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'job_topic' => $topic,
            'job_status' => 1,
            'job_priority' => 2,
            'job_start_at' => CarbonImmutable::parse('2026-10-15 00:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'job_due_at' => CarbonImmutable::parse('2026-10-20 00:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
        ]);
    }
}
