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
 * มุมมอง "ประชุม" ในหน้างานของฉันคือ "ปฏิทินประชุมและนัดหมาย"
 *
 * ขอบเขตการมองเห็นของการประชุมยังมาจาก MeetingQueryService::visibleQuery() ตัวเดิม
 * ผ่าน MeetingAppointmentCalendarQuery จึงไม่มีกฎสิทธิ์ชุดที่สอง
 *
 * ตัวกรองประเภท ปฏิทินโปรเจกต์ และเอกสาร Export อยู่ใน MeetingAppointmentCalendarTest
 */
class MyTasksMeetingViewTest extends TestCase
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

    public function test_member_meeting_view_only_shows_meetings_they_may_see(): void
    {
        $member = $this->user();
        /*
         * ปฏิทินเป็นระดับแผนกแล้ว คนที่ "ไม่ควรเห็น" จึงต้องเป็นคนนอกแผนกของผู้เปิดหน้า
         * ไม่ใช่เพื่อนร่วมแผนกที่ไม่ได้ถูกเชิญ ซึ่งตอนนี้เห็นได้โดยถูกต้อง
         * (ขอบเขตอยู่ที่ MeetingQueryService::calendarScopedQuery และถูกตรึงไว้ครบใน
         * DepartmentCalendarScopeTest)
         */
        $stranger = $this->user(department: Department::create(['department_name' => 'Finance']));
        $this->meeting($member, 'ประชุมของฉัน');
        $this->meeting($stranger, 'ประชุมของคนอื่น');

        $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting']))
            ->assertOk()
            ->assertSee('notion-database" data-view="meeting"', false)
            ->assertSee('mytasks-meeting-view', false)
            ->assertSee('data-meeting-calendar', false)
            ->assertSee('ประชุมของฉัน')
            ->assertDontSee('ประชุมของคนอื่น');
    }

    public function test_member_viewbar_has_the_meeting_tab_and_navigates_instead_of_toggling(): void
    {
        $member = $this->user();

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-view="meeting"', false)
            ->assertSee('data-view-navigate', false)
            ->assertSee('href="'.route('mytasks.index', ['view' => 'meeting']).'"', false)
            ->assertSee('bi-calendar-event-fill', false);
    }

    public function test_admin_has_no_meeting_view_and_falls_back_to_calendar(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->get(route('mytasks.index', ['view' => 'meeting']))
            ->assertOk()
            ->assertSee('notion-database" data-view="calendar"', false)
            ->assertDontSee('data-view="meeting"', false)
            ->assertDontSee('mytasks-meeting-view', false);
    }

    public function test_viewer_is_still_redirected_away_from_my_tasks(): void
    {
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)
            ->get(route('mytasks.index', ['view' => 'meeting']))
            ->assertRedirect(route('board.index'));
    }

    public function test_the_meeting_view_lands_on_the_calendar_with_its_own_header_and_actions(): void
    {
        $member = $this->user();

        $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting']))
            ->assertOk()
            // หัวเรื่องและคำอธิบายต้องระบุแผนกเจ้าของปฏิทิน ไม่ใช่ถ้อยคำแบบ "ของคุณ"
            ->assertSee('ปฏิทินประชุมและนัดหมาย แผนก'.$member->department->department_name)
            ->assertSee('การประชุมและนัดหมายทั้งหมดของแผนก'.$member->department->department_name)
            ->assertDontSee('จัดการการประชุมและนัดหมายของคุณในที่เดียว')
            ->assertSee('data-calendar-grid', false)
            ->assertSee('data-calendar-month-label', false)
            ->assertSee('Export PDF')
            // ไม่ใช่รายการการ์ดประชุมแบบเดิมอีกแล้ว
            ->assertDontSee('meetings-page__pagination', false)
            ->assertDontSee('meetings-page__list', false);
    }

    public function test_original_meetings_route_is_not_forced_into_the_meeting_view(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมหน้าเดิม');

        $this->actingAs($member)
            ->get(route('meetings.index', ['period' => 'all']))
            ->assertOk()
            ->assertSee('ประชุมหน้าเดิม')
            ->assertSee('action="'.route('meetings.index').'"', false)
            ->assertDontSee('name="view" value="meeting"', false)
            ->assertDontSee('mytasks-meeting-view', false);
    }

    public function test_meeting_links_from_the_workspace_carry_their_origin_back_to_the_workspace(): void
    {
        $member = $this->user();
        $meeting = $this->meeting($member, 'ประชุมของฉัน');

        // กดประชุมบนปฏิทินแล้วต้องกลับเข้า Workspace ได้ ไม่ใช่ถูกทิ้งไว้ที่หน้า /meetings
        $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting']))
            ->assertOk()
            ->assertSee(route('meetings.show', ['meeting' => $meeting->id, 'from' => 'workspace']), false);

        // เข้าจาก Workspace ปุ่มกลับต้องพากลับ Workspace ไม่ใช่ทิ้งไว้ที่ /meetings
        $this->actingAs($member)
            ->get(route('meetings.show', ['meeting' => $meeting->id, 'from' => 'workspace']))
            ->assertOk()
            ->assertSee('class="meetings-page__back" href="'.route('mytasks.index', ['view' => 'meeting']).'"', false);

        // เข้าจาก /meetings ตรง ๆ ปุ่มกลับต้องยังทำงานเหมือนเดิม
        $this->actingAs($member)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('class="meetings-page__back" href="'.route('meetings.index').'"', false);
    }

    public function test_standalone_meeting_page_links_do_not_claim_a_workspace_origin(): void
    {
        $member = $this->user();
        $meeting = $this->meeting($member, 'ประชุมของฉัน');

        $this->actingAs($member)
            ->get(route('meetings.index', ['period' => 'all']))
            ->assertOk()
            ->assertDontSee('from=workspace', false)
            ->assertSee(route('meetings.show', ['meeting' => $meeting->id]), false);
    }

    private function user(string $role = 'user', ?Department $department = null): User
    {
        return User::factory()->create([
            'role' => $role,
            // $department ชนะค่าเริ่มต้น ใช้กับเคสที่ต้องการคนนอกแผนกของผู้เปิดหน้าจริง ๆ
            'department_id' => $department?->id ?? ($role === 'user' ? $this->department->id : null),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function meeting(User $creator, string $title): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'รายละเอียดการประชุม',
            'starts_at' => CarbonImmutable::parse('2026-08-24 10:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-24 11:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ]);
    }
}
