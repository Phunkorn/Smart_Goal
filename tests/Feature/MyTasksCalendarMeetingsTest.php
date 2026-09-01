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

    public function test_admin_member_workspace_calendar_never_embeds_meetings(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $this->meeting($admin, 'ประชุมของผู้ดูแล', '2026-08-24 10:00', '2026-08-24 11:00');

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertDontSee('data-calendar-meetings', false)
            ->assertDontSee('data-meetings-endpoint', false)
            ->assertDontSee('ประชุมของผู้ดูแล');
    }

    public function test_user_and_admin_member_calendars_share_the_same_agenda_component(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();

        $workspaces = [
            [$member, route('mytasks.index', ['view' => 'calendar'])],
            [$admin, route('admin.work-board.member', [$this->department, $member, 'view' => 'calendar'])],
        ];

        foreach ($workspaces as [$actor, $url]) {
            $this->actingAs($actor)
                ->get($url)
                ->assertOk()
                ->assertSee('data-calendar-agenda', false)
                ->assertSee('data-calendar-today-list', false)
                ->assertSee('data-calendar-month-list', false)
                ->assertSee('data-calendar-month-agenda-title', false);
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
