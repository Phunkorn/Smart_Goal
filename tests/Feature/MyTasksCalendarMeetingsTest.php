<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ปฏิทินของหน้า "งานของฉัน" แสดงทั้งงานและการประชุม
 * ประชุมถูกฝังมาเฉพาะช่วงแคบ ๆ ส่วนเดือนที่ไกลออกไปขอผ่าน endpoint ที่มีเพดานชัดเจน
 */
class MyTasksCalendarMeetingsTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Technology']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preloaded_calendar_meetings_are_scoped_to_the_viewer(): void
    {
        $member = $this->user();
        $stranger = $this->user();
        $this->meeting($member, 'ประชุมที่ฉันเห็น', '2026-08-24 10:00', '2026-08-24 11:00');
        $this->meeting($stranger, 'ประชุมที่ฉันไม่เห็น', '2026-08-24 13:00', '2026-08-24 14:00');

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-calendar-meetings', false)
            ->assertSee('data-meetings-endpoint="'.route('mytasks.calendar.meetings').'"', false)
            ->assertSee('ประชุมที่ฉันเห็น')
            ->assertDontSee('ประชุมที่ฉันไม่เห็น');
    }

    public function test_admin_and_department_head_member_calendars_embed_only_the_subject_meetings(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();
        $this->meeting($admin, 'ประชุมของผู้ดูแล', '2026-08-24 10:00', '2026-08-24 11:00');
        $this->meeting($head, 'ประชุมส่วนตัวของหัวหน้า', '2026-08-24 11:00', '2026-08-24 12:00');
        $this->meeting($member, 'ประชุมของสมาชิก', '2026-08-24 13:00', '2026-08-24 14:00');

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertSee('data-calendar-meetings', false)
            ->assertSee('data-meetings-subject-user-id="'.$member->id.'"', false)
            ->assertSee('ประชุมของสมาชิก')
            ->assertDontSee('ประชุมของผู้ดูแล');

        $this->actingAs($head)
            ->get(route('work-board.member', [$this->department, $member, 'workspace' => 1, 'view' => 'calendar']))
            ->assertOk()
            ->assertSee('data-meetings-subject-user-id="'.$member->id.'"', false)
            ->assertSee('ประชุมของสมาชิก')
            ->assertDontSee('ประชุมส่วนตัวของหัวหน้า');
    }

    public function test_user_and_admin_member_calendars_share_the_same_agenda_component(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();

        $workspaces = [
            [$member, route('mytasks.index', ['view' => 'calendar'])],
            [$head, route('work-board.member', [$this->department, $member, 'workspace' => 1, 'view' => 'calendar'])],
            [$admin, route('admin.work-board.member', [$this->department, $member, 'view' => 'calendar'])],
        ];

        foreach ($workspaces as [$actor, $url]) {
            $this->actingAs($actor)
                ->get($url)
                ->assertOk()
                ->assertSee('data-calendar-agenda', false)
                ->assertSee('data-calendar-today-list', false)
                ->assertSee('data-calendar-month-list', false)
                ->assertSee('data-calendar-month-agenda-title', false)
                // ทุก workspace ใช้ controls ชุดเดียวกัน และเปิดด้วย timeline + วันเริ่ม/สิ้นสุด
                ->assertSee('data-calendar-mode-option="timeline" aria-pressed="true"', false)
                ->assertSee('data-calendar-mode-option="summary" aria-pressed="false"', false)
                ->assertSee('data-calendar-date-point="start" aria-pressed="true"', false)
                ->assertSee('data-calendar-date-point="due" aria-pressed="true"', false)
                ->assertSee('สูงสุด 4 เส้นต่อวัน')
                ->assertSee('งานและการประชุมวันนี้')
                ->assertSee('ผู้ร่วมงาน / ผู้เข้าร่วม')
                ->assertSee('กำหนดส่งและนัดหมายในเดือนนี้')
                // กล่องรายวันแทนที่ popover ของปุ่ม "+N" เดิม ต้องมีทั้งสองบริบทและของเดิมต้องหายไปจริง
                ->assertSee('data-calendar-day-modal', false)
                ->assertSee('data-calendar-day-task-list', false)
                ->assertSee('data-calendar-day-meeting-list', false)
                // งานกับประชุมอยู่คนละ section เสมอ ไม่ปนกันในตารางเดียว
                ->assertSee('data-calendar-day-tasks', false)
                ->assertSee('data-calendar-day-meetings', false)
                ->assertDontSee('data-calendar-popover', false);
        }
    }

    /** ประชุมใช้แถวร่วมในสองการ์ดสรุปและ Modal รายวัน โดยไม่สร้างการ์ดประชุมใบที่สาม */
    public function test_meeting_agenda_card_is_never_rendered_below_the_calendar(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();

        $workspaces = [
            [$member, route('mytasks.index', ['view' => 'calendar'])],
            [$head, route('mytasks.index', ['view' => 'calendar'])],
            [$admin, route('admin.work-board.member', [$this->department, $member, 'view' => 'calendar'])],
        ];

        foreach ($workspaces as [$actor, $url]) {
            $this->actingAs($actor)->get($url)
                ->assertOk()
                ->assertDontSee('data-calendar-meeting-list', false)
                ->assertDontSee('data-calendar-meeting-count', false)
                ->assertDontSee('mytasks-calendar-agenda__section--meeting', false)
                ->assertSee('data-calendar-day-meeting-list', false);
        }
    }

    /**
     * คำอธิบายสีบนปฏิทินยึดความสำคัญของงาน 5 ระดับ จึงต้องแสดงทุกบริบท
     * ส่วนคำอธิบาย "ประชุม" ต้องแสดงทุกบริบทที่ Calendar รับข้อมูลประชุม
     */
    public function test_priority_legend_is_shared_but_the_meeting_legend_follows_the_context(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();

        $workspaces = [
            [$member, route('mytasks.index', ['view' => 'calendar'])],
            [$admin, route('admin.work-board.member', [$this->department, $member, 'view' => 'calendar'])],
        ];

        foreach ($workspaces as [$actor, $url]) {
            $response = $this->actingAs($actor)->get($url)->assertOk();

            foreach (['priority-urgent', 'priority-quick', 'priority-important', 'priority-flexible', 'priority-routine'] as $tone) {
                $response->assertSee('mytasks-calendar__legend-item '.$tone, false);
            }

            $response->assertSee('mytasks-calendar__legend-item--meeting', false);
        }
    }

    public function test_endpoint_returns_bangkok_times_and_prefixed_identifiers(): void
    {
        $member = $this->user();
        $meeting = $this->meeting($member, 'ประชุมเช้า', '2026-11-02 09:30', '2026-11-02 11:00');

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-11-01', 'end' => '2026-11-30']))
            ->assertOk()
            ->assertJsonPath('meetings.0.id', 'meeting-'.$meeting->id)
            ->assertJsonPath('meetings.0.type', 'meeting')
            ->assertJsonPath('meetings.0.title', 'ประชุมเช้า')
            ->assertJsonPath('meetings.0.start', '2026-11-02')
            ->assertJsonPath('meetings.0.due', '2026-11-02')
            ->assertJsonPath('meetings.0.startTime', '09:30')
            ->assertJsonPath('meetings.0.endTime', '11:00')
            ->assertJsonPath('meetings.0.url', route('meetings.show', $meeting));
    }

    public function test_meeting_crossing_thai_midnight_keeps_the_local_day(): void
    {
        $member = $this->user();
        // 23:30 น. ของวันที่ 2 พ.ย. ถึง 00:30 น. ของวันที่ 3 พ.ย. ตามเวลาไทย
        $this->meeting($member, 'ประชุมข้ามเที่ยงคืน', '2026-11-02 23:30', '2026-11-03 00:30');

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-11-01', 'end' => '2026-11-30']))
            ->assertOk()
            ->assertJsonPath('meetings.0.start', '2026-11-02')
            ->assertJsonPath('meetings.0.due', '2026-11-03');
    }

    public function test_endpoint_scopes_results_per_viewer(): void
    {
        $member = $this->user();
        $stranger = $this->user();
        $admin = $this->user('admin');
        $this->meeting($member, 'ประชุม A', '2026-11-02 09:00', '2026-11-02 10:00');
        $this->meeting($stranger, 'ประชุม B', '2026-11-02 11:00', '2026-11-02 12:00');

        $query = ['start' => '2026-11-01', 'end' => '2026-11-30'];

        $this->actingAs($member)->getJson(route('mytasks.calendar.meetings', $query))
            ->assertOk()->assertJsonCount(1, 'meetings')->assertJsonPath('meetings.0.title', 'ประชุม A');

        $this->actingAs($stranger)->getJson(route('mytasks.calendar.meetings', $query))
            ->assertOk()->assertJsonCount(1, 'meetings')->assertJsonPath('meetings.0.title', 'ประชุม B');

        $this->actingAs($admin)->getJson(route('mytasks.calendar.meetings', $query))
            ->assertOk()->assertJsonCount(2, 'meetings');
    }

    public function test_endpoint_subject_scope_is_limited_to_admin_or_the_subject_department_head(): void
    {
        $member = $this->user();
        $otherDepartment = Department::create(['department_name' => 'Finance']);
        $outsider = User::factory()->create([
            'role' => 'user',
            'department_id' => $otherDepartment->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $admin = $this->user('admin');
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();
        $this->meeting($member, 'ประชุมของสมาชิก', '2026-11-02 09:00', '2026-11-02 10:00');
        $this->meeting($outsider, 'ประชุมคนนอก', '2026-11-02 11:00', '2026-11-02 12:00');
        $query = ['start' => '2026-11-01', 'end' => '2026-11-30', 'subject_user_id' => $member->id];

        $this->actingAs($admin)->getJson(route('mytasks.calendar.meetings', $query))
            ->assertOk()->assertJsonCount(1, 'meetings')->assertJsonPath('meetings.0.title', 'ประชุมของสมาชิก');
        $this->actingAs($head)->getJson(route('mytasks.calendar.meetings', $query))
            ->assertOk()->assertJsonCount(1, 'meetings')->assertJsonPath('meetings.0.title', 'ประชุมของสมาชิก');

        $query['subject_user_id'] = $outsider->id;
        $this->actingAs($head)->getJson(route('mytasks.calendar.meetings', $query))->assertForbidden();
    }

    public function test_endpoint_requires_authentication_validation_and_a_bounded_range(): void
    {
        $member = $this->user();

        $this->getJson(route('mytasks.calendar.meetings', ['start' => '2026-11-01', 'end' => '2026-11-30']))
            ->assertUnauthorized();

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start', 'end']);

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '01/11/2026', 'end' => '2026-11-30']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start');

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-11-30', 'end' => '2026-11-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end');

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-01-01', 'end' => '2028-01-01']))
            ->assertStatus(422);
    }

    /**
     * ปฏิทินส่วนตัวของหัวหน้าแผนกต้องมีเฉพาะประชุมของตัวเอง
     *
     * MeetingQueryService::visibleQuery() ให้หัวหน้าแผนกเห็นประชุมทุกใบที่คนในแผนกจัดหรือเข้าร่วม
     * ซึ่งถูกต้องสำหรับหน้ารายการประชุมที่เป็นหน้ากำกับดูแล แต่ผิดสำหรับปฏิทินของตัวเอง
     * เพราะทำให้นัดหมายที่หัวหน้าไม่ได้ถูกเชิญไปปนกับนัดหมายจริงจนแยกไม่ออกว่าต้องไปไหนบ้าง
     */
    public function test_department_head_personal_calendar_only_shows_their_own_meetings(): void
    {
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();
        $teammate = $this->user();

        $this->meeting($head, 'ประชุมของหัวหน้าเอง', '2026-08-24 09:00', '2026-08-24 10:00');
        $this->meeting($teammate, 'ประชุมของลูกทีมที่หัวหน้าไม่ได้ร่วม', '2026-08-24 11:00', '2026-08-24 12:00');

        $this->actingAs($head)
            ->get(route('mytasks.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertSee('ประชุมของหัวหน้าเอง')
            ->assertDontSee('ประชุมของลูกทีมที่หัวหน้าไม่ได้ร่วม');

        $this->actingAs($head)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-08-01', 'end' => '2026-08-31']))
            ->assertOk()
            ->assertJsonCount(1, 'meetings')
            ->assertJsonPath('meetings.0.title', 'ประชุมของหัวหน้าเอง');

        // หน้ารายการประชุมยังเป็นหน้ากำกับดูแล จึงยังเห็นประชุมของทั้งแผนกตามเดิม
        $this->actingAs($head)
            ->get(route('meetings.index'))
            ->assertOk()
            ->assertSee('ประชุมของลูกทีมที่หัวหน้าไม่ได้ร่วม');
    }

    public function test_viewer_cannot_reach_the_calendar_meeting_endpoint(): void
    {
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-11-01', 'end' => '2026-11-30']))
            ->assertForbidden();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /**
     * การ์ดการประชุมแสดงผู้จัดและผู้เข้าร่วมเป็น avatar จึงต้องได้รูปมาจาก payload จริง
     * รูปทุกใบต้องเป็น URL ของ MediaController ห้ามประกอบ path จาก storage เอง
     * และคนที่ยังไม่ได้ตั้งรูปต้องคืน null เพื่อให้ฝั่งหน้าเว็บตกไปใช้อักษรแรกของชื่อ
     */
    public function test_calendar_meeting_payload_carries_organizer_and_attendee_avatars(): void
    {
        $organizer = $this->user();
        $organizer->forceFill(['profile_image' => 'profile-images/organizer.jpg'])->save();

        $withPhoto = $this->user();
        $withPhoto->forceFill(['profile_image' => 'profile-images/attendee.jpg'])->save();
        $withoutPhoto = $this->user();

        $meeting = $this->meeting($organizer, 'ประชุมที่มีผู้เข้าร่วม', '2026-11-02 09:30', '2026-11-02 11:00');
        $meeting->attendees()->sync([$withPhoto->id, $withoutPhoto->id]);

        $response = $this->actingAs($organizer)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-11-01', 'end' => '2026-11-30']))
            ->assertOk()
            ->assertJsonPath('meetings.0.organizer', $organizer->name)
            ->assertJsonPath('meetings.0.organizerAvatar', route('media.profile', $organizer))
            ->assertJsonCount(2, 'meetings.0.attendees');

        $attendees = collect($response->json('meetings.0.attendees'))->keyBy('name');

        $this->assertSame(route('media.profile', $withPhoto), $attendees[$withPhoto->name]['avatar_url']);
        $this->assertNull($attendees[$withoutPhoto->name]['avatar_url']);
    }

    private function meeting(User $creator, string $title, string $startLocal, string $endLocal): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'รายละเอียดการประชุม',
            'starts_at' => CarbonImmutable::parse($startLocal, MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse($endLocal, MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ]);
    }
}
