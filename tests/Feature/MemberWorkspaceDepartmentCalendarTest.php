<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ปฏิทินประชุมและนัดหมายในหน้า Member Workspace — ขอบเขตคือ "แผนกของ Workspace นั้น"
 *
 * สิ่งที่ไฟล์นี้ตรึงไว้ เพราะพังเงียบได้ และพังแล้วคือเห็นข้อมูลผิดแผนก:
 *   1. หัวหน้าแผนกเห็นปฏิทินของแผนกทั้งแผนก ไม่ถูกกรองเหลือเฉพาะใบที่สมาชิกคนนั้นเข้าร่วม
 *   2. Admin เปิดของพนักงานแผนกไหน ก็เห็นปฏิทินของแผนกนั้น — ไม่ใช่แผนกของ Admin
 *      ไม่ใช่ทุกแผนกรวมกัน และสลับสมาชิกข้ามแผนกแล้วปฏิทินต้องเปลี่ยนตาม
 *   3. ขอบเขตเดียวกันถูกใช้ทั้งหน้าแรก, API เปลี่ยนเดือน/ตัวกรอง และเอกสาร Export
 *      ไม่ใช่กรองแค่ฝั่งหน้าจอ
 *   4. ?department= ที่ส่งมาเองไม่ใช่ทางลัดข้ามแผนก — ต้องผ่าน authorization เสมอ
 *   5. นัดหมายส่วนตัวของคนในแผนกไม่หลุดเข้าปฏิทินของแผนกเลย แม้ผู้เปิดดูเป็น Admin
 */
class MemberWorkspaceDepartmentCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->it = Department::create(['department_name' => 'IT']);
        $this->marketing = Department::create(['department_name' => 'Marketing']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /* ---------- ข้อ 1: หัวหน้าแผนกเห็นของทั้งแผนก ---------- */

    public function test_a_department_head_sees_the_whole_department_calendar_not_only_the_member_meetings(): void
    {
        $head = $this->user($this->it, head: true);
        $member = $this->user($this->it);
        $teammate = $this->user($this->it);

        $this->meeting($member, 'ประชุมที่สมาชิกจัดเอง');
        // ของเดิมกรองด้วย MeetingQueryService::indexData($member) ใบนี้จึงหายไป
        $this->meeting($teammate, 'ประชุมของเพื่อนร่วมแผนกที่สมาชิกไม่เกี่ยว');

        $titles = $this->calendarTitles(
            $head,
            route('work-board.member', [$this->it, $member, 'workspace' => 1, 'view' => 'meeting'])
        );

        $this->assertContains('ประชุมที่สมาชิกจัดเอง', $titles);
        $this->assertContains('ประชุมของเพื่อนร่วมแผนกที่สมาชิกไม่เกี่ยว', $titles);
    }

    public function test_a_department_head_never_sees_another_department_calendar_in_a_member_workspace(): void
    {
        $head = $this->user($this->it, head: true);
        $member = $this->user($this->it);
        $outsider = $this->user($this->marketing);

        $this->meeting($member, 'ประชุมแผนกไอที');
        $this->meeting($outsider, 'ประชุมแผนกการตลาด');

        $titles = $this->calendarTitles(
            $head,
            route('work-board.member', [$this->it, $member, 'workspace' => 1, 'view' => 'meeting'])
        );

        $this->assertContains('ประชุมแผนกไอที', $titles);
        $this->assertNotContains('ประชุมแผนกการตลาด', $titles);
    }

    /**
     * ประชุมข้ามแผนกที่หัวหน้าถูกเชิญเอง ต้องไม่โผล่ในปฏิทินของลูกทีม
     *
     * ปฏิทินใบนี้คือ "ตารางของแผนก" ไม่ใช่ตารางของผู้ที่เปิดดู ถ้าผสมของตัวเองเข้าไป
     * หัวหน้าจะอ่านหน้านี้ไม่ออกว่าอันไหนเป็นงานของแผนกและอันไหนเป็นของตัวเอง
     */
    public function test_the_viewer_own_cross_department_invitations_stay_out_of_the_department_calendar(): void
    {
        $head = $this->user($this->it, head: true);
        $member = $this->user($this->it);
        $outsider = $this->user($this->marketing);

        $this->meeting($outsider, 'ประชุมข้ามแผนกที่หัวหน้าถูกเชิญ')->attendees()->sync([$head->id]);

        $titles = $this->calendarTitles(
            $head,
            route('work-board.member', [$this->it, $member, 'workspace' => 1, 'view' => 'meeting'])
        );

        $this->assertNotContains('ประชุมข้ามแผนกที่หัวหน้าถูกเชิญ', $titles);
    }

    /* ---------- ข้อ 2: Admin ใช้แผนกจาก route ---------- */

    public function test_admin_sees_the_calendar_of_the_department_in_the_route_and_switching_member_switches_calendar(): void
    {
        $admin = $this->user(null, role: 'admin');
        $itMember = $this->user($this->it);
        $marketingMember = $this->user($this->marketing);

        $this->meeting($itMember, 'ประชุมแผนกไอที');
        $this->meeting($marketingMember, 'ประชุมแผนกการตลาด');
        // Admin เห็นประชุมทั้งระบบผ่าน visibleQuery() ของตัวเองจึงต้องไม่ปนเข้ามา
        $this->meeting($admin, 'ประชุมของผู้ดูแลเอง');

        $itTitles = $this->calendarTitles(
            $admin,
            route('admin.work-board.member', [$this->it, $itMember, 'view' => 'meeting'])
        );
        $this->assertContains('ประชุมแผนกไอที', $itTitles);
        $this->assertNotContains('ประชุมแผนกการตลาด', $itTitles);
        $this->assertNotContains('ประชุมของผู้ดูแลเอง', $itTitles);

        $marketingTitles = $this->calendarTitles(
            $admin,
            route('admin.work-board.member', [$this->marketing, $marketingMember, 'view' => 'meeting'])
        );
        $this->assertContains('ประชุมแผนกการตลาด', $marketingTitles);
        $this->assertNotContains('ประชุมแผนกไอที', $marketingTitles);
        $this->assertNotContains('ประชุมของผู้ดูแลเอง', $marketingTitles);
    }

    /** หัวเรื่องและคำอธิบายต้องบอกแผนกเจ้าของปฏิทิน ไม่ใช่แผนกของผู้ที่ล็อกอิน */
    public function test_the_heading_names_the_department_of_the_workspace(): void
    {
        $admin = $this->user(null, role: 'admin');
        $marketingMember = $this->user($this->marketing);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->marketing, $marketingMember, 'view' => 'meeting']))
            ->assertOk()
            ->assertSee('ปฏิทินประชุมและนัดหมาย แผนกMarketing')
            ->assertSee('การประชุมและนัดหมายทั้งหมดของแผนกMarketing');
    }

    /* ---------- ข้อ 3: API และ Export ใช้ขอบเขตเดียวกัน ---------- */

    /** endpoint บนหน้าต้องพา ?department= ไปด้วย ไม่งั้นเปลี่ยนเดือนแล้วขอบเขตหลุด */
    public function test_the_page_hands_the_department_to_the_events_endpoint_and_the_export_form(): void
    {
        $admin = $this->user(null, role: 'admin');
        $itMember = $this->user($this->it);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->it, $itMember, 'view' => 'meeting']))
            ->assertOk()
            ->assertSee(
                'data-calendar-events-endpoint="'.e(route('meeting-calendar.events', ['department' => $this->it->id])).'"',
                false
            )
            // ฟอร์ม GET ทิ้ง query string ของ action ตอน submit จึงต้องเป็น input
            ->assertSee('<input type="hidden" name="department" value="'.$this->it->id.'">', false);
    }

    public function test_the_events_api_returns_the_requested_department_calendar(): void
    {
        $admin = $this->user(null, role: 'admin');
        $itMember = $this->user($this->it);
        $marketingMember = $this->user($this->marketing);

        $this->meeting($itMember, 'ประชุมแผนกไอที');
        $this->meeting($marketingMember, 'ประชุมแผนกการตลาด');
        $this->meeting($admin, 'ประชุมของผู้ดูแลเอง');

        $titles = collect($this->actingAs($admin)
            ->getJson(route('meeting-calendar.events', [
                'start' => '2026-10-01',
                'end' => '2026-10-31',
                'department' => $this->it->id,
            ]))
            ->assertOk()
            ->json('events'))
            ->pluck('title')
            ->all();

        $this->assertContains('ประชุมแผนกไอที', $titles);
        $this->assertNotContains('ประชุมแผนกการตลาด', $titles);
        $this->assertNotContains('ประชุมของผู้ดูแลเอง', $titles);
    }

    public function test_the_export_document_follows_the_department_scope(): void
    {
        $admin = $this->user(null, role: 'admin');
        $itMember = $this->user($this->it);
        $marketingMember = $this->user($this->marketing);

        $this->meeting($itMember, 'ประชุมแผนกไอที');
        $this->meeting($marketingMember, 'ประชุมแผนกการตลาด');

        $this->actingAs($admin)
            ->get(route('meeting-calendar.export', [
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'department' => $this->it->id,
            ]))
            ->assertOk()
            // เอกสารที่พิมพ์ออกไปต้องบอกแผนกไว้บนกระดาษ
            ->assertSee('ปฏิทินประชุมและนัดหมาย แผนกIT')
            ->assertSee('ประชุมแผนกไอที')
            ->assertDontSee('ประชุมแผนกการตลาด');
    }

    /** ปฏิทินหลักของผู้ใช้ต้องไม่เปลี่ยนพฤติกรรมเมื่อไม่ส่ง ?department= มา */
    public function test_the_main_calendar_api_still_answers_with_the_viewer_own_scope(): void
    {
        $itMember = $this->user($this->it);
        $marketingMember = $this->user($this->marketing);

        $this->meeting($itMember, 'ประชุมแผนกไอที');
        $this->meeting($marketingMember, 'ประชุมแผนกการตลาด');

        $titles = collect($this->actingAs($itMember)
            ->getJson(route('meeting-calendar.events', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()
            ->json('events'))
            ->pluck('title')
            ->all();

        $this->assertContains('ประชุมแผนกไอที', $titles);
        $this->assertNotContains('ประชุมแผนกการตลาด', $titles);
    }

    /* ---------- ข้อ 4: ?department= ไม่ใช่ทางลัดข้ามแผนก ---------- */

    public function test_a_crafted_department_parameter_cannot_open_another_department_calendar(): void
    {
        $itMember = $this->user($this->it);
        $itHead = $this->user($this->it, head: true);
        $viewer = $this->user($this->it, role: 'viewer');

        foreach ([$itMember, $itHead, $viewer] as $intruder) {
            $this->actingAs($intruder)
                ->getJson(route('meeting-calendar.events', [
                    'start' => '2026-10-01',
                    'end' => '2026-10-31',
                    'department' => $this->marketing->id,
                ]))
                ->assertForbidden();

            $this->actingAs($intruder)
                ->get(route('meeting-calendar.export', [
                    'from_date' => '2026-10-01',
                    'to_date' => '2026-10-31',
                    'department' => $this->marketing->id,
                ]))
                ->assertForbidden();
        }
    }

    public function test_every_role_may_still_ask_for_their_own_department_calendar(): void
    {
        foreach ([
            $this->user($this->it),
            $this->user($this->it, head: true),
            $this->user($this->it, role: 'viewer'),
        ] as $insider) {
            $this->actingAs($insider)
                ->getJson(route('meeting-calendar.events', [
                    'start' => '2026-10-01',
                    'end' => '2026-10-31',
                    'department' => $this->it->id,
                ]))
                ->assertOk();
        }
    }

    public function test_a_department_that_does_not_exist_is_rejected_by_validation(): void
    {
        $admin = $this->user(null, role: 'admin');

        $this->actingAs($admin)
            ->getJson(route('meeting-calendar.events', [
                'start' => '2026-10-01',
                'end' => '2026-10-31',
                'department' => 9999,
            ]))
            ->assertStatus(422);
    }

    /* ---------- ข้อ 5: นัดหมายส่วนตัวไม่หลุด ---------- */

    public function test_personal_appointments_never_reach_the_department_calendar(): void
    {
        $admin = $this->user(null, role: 'admin');
        $itMember = $this->user($this->it);

        $personal = $this->appointment($itMember, 'นัดหมอฟันส่วนตัว', Appointment::VISIBILITY_PERSONAL);
        $shared = $this->appointment($itMember, 'นัดวางระบบเครือข่ายของแผนก', Appointment::VISIBILITY_DEPARTMENT);

        $titles = $this->calendarTitles(
            $admin,
            route('admin.work-board.member', [$this->it, $itMember, 'view' => 'meeting'])
        );

        $this->assertContains('นัดวางระบบเครือข่ายของแผนก', $titles);
        $this->assertNotContains('นัดหมอฟันส่วนตัว', $titles);

        /*
         * สิ่งที่โผล่บนปฏิทินต้องกดเข้าไปได้ และสิ่งที่ไม่โผล่ต้องกดเข้าไม่ได้
         * ถ้าสองอย่างนี้ไม่ตรงกัน ผู้ใช้จะเจอ 403 จากรายการที่ระบบเพิ่งแสดงให้เอง
         */
        $this->actingAs($admin)->get(route('appointments.show', $shared))->assertOk();
        $this->actingAs($admin)->get(route('appointments.show', $personal))->assertForbidden();
    }

    /** นัดหมายที่แชร์ให้แผนกอื่น ต้องไม่ติดมากับปฏิทินของแผนกนี้ */
    public function test_a_department_appointment_belongs_only_to_its_own_department_calendar(): void
    {
        $admin = $this->user(null, role: 'admin');
        $itMember = $this->user($this->it);
        $marketingMember = $this->user($this->marketing);

        $this->appointment($marketingMember, 'นัดลูกค้าของแผนกการตลาด', Appointment::VISIBILITY_DEPARTMENT);

        $titles = $this->calendarTitles(
            $admin,
            route('admin.work-board.member', [$this->it, $itMember, 'view' => 'meeting'])
        );

        $this->assertNotContains('นัดลูกค้าของแผนกการตลาด', $titles);
    }

    /**
     * เห็นปฏิทินของแผนกไม่ได้แปลว่าแก้หรือลบนัดหมายของคนในแผนกได้
     *
     * ข้อกำหนด "แยกสิทธิ์ดูปฏิทินออกจากสิทธิ์สร้าง/แก้ไข/ลบ" ต้องยังจริงหลังการเปลี่ยนนี้
     */
    public function test_reading_a_department_calendar_grants_no_write_access(): void
    {
        $admin = $this->user(null, role: 'admin');
        $head = $this->user($this->it, head: true);
        $itMember = $this->user($this->it);
        $shared = $this->appointment($itMember, 'นัดของแผนก', Appointment::VISIBILITY_DEPARTMENT);

        foreach ([$admin, $head] as $reader) {
            $this->assertTrue($reader->can('view', $shared));
            $this->assertFalse($reader->can('update', $shared));
            $this->assertFalse($reader->can('delete', $shared));
            $this->assertFalse($reader->can('viewAttendeeResponses', $shared));
        }
    }

    /**
     * ชื่อเรื่องของรายการที่ปฏิทินวาดจริง อ่านจาก payload ที่ฝังอยู่ในหน้า
     *
     * ไม่ assert กับ HTML ทั้งหน้า เพราะชื่อสมาชิกหรือชื่องานอาจบังเอิญซ้ำกับชื่อประชุม
     * payload ก้อนนี้คือสิ่งที่ JavaScript ใช้วาดตารางจริง (ดู data-calendar-payload)
     *
     * @return array<int, string>
     */
    private function calendarTitles(User $viewer, string $url): array
    {
        $content = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

        $start = strpos($content, '<script type="application/json" data-calendar-payload>');
        $this->assertNotFalse($start, 'ไม่พบ payload ของปฏิทินในหน้า');
        $start = strpos($content, '>', $start) + 1;
        $end = strpos($content, '</script>', $start);

        $payload = json_decode(
            html_entity_decode(substr($content, $start, $end - $start), ENT_QUOTES, 'UTF-8'),
            true
        );
        $this->assertIsArray($payload['events'] ?? null, 'payload ของปฏิทินต้องมีรายการ events');

        return array_column($payload['events'], 'title');
    }

    private function user(?Department $department, string $role = 'user', bool $head = false): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function meeting(User $creator, string $title): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'รายละเอียดการประชุม',
            'starts_at' => CarbonImmutable::parse('2026-10-15 10:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse('2026-10-15 11:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ]);
    }

    private function appointment(User $owner, string $title, string $visibility): Appointment
    {
        $appointment = new Appointment([
            'title' => $title,
            'appointment_date' => '2026-10-16',
            'starts_at_time' => '09:00',
            'ends_at_time' => '10:00',
            'location' => 'ออฟฟิศ',
            'visibility' => $visibility,
        ]);
        // created_by และ department_id ถูกกันออกจาก $fillable โดยตั้งใจ (ดู Appointment)
        $appointment->created_by = $owner->id;
        $appointment->department_id = $owner->department_id;
        $appointment->save();

        return $appointment;
    }
}
