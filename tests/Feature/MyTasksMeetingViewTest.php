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
 * มุมมอง "ประชุม" ในหน้างานของฉันใช้ partial และ service ชุดเดียวกับหน้า /meetings
 * สิทธิ์จึงต้องถูกบังคับที่ SQL ผ่าน MeetingQueryService::visibleQuery() เหมือนเดิม
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
        $stranger = $this->user();
        $this->meeting($member, 'ประชุมของฉัน');
        $this->meeting($stranger, 'ประชุมของคนอื่น');

        $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting', 'period' => 'all']))
            ->assertOk()
            ->assertSee('notion-database" data-view="meeting"', false)
            ->assertSee('mytasks-meeting-view', false)
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

    public function test_meeting_filters_stay_inside_the_meeting_view(): void
    {
        $member = $this->user();
        $this->meeting($member, 'ประชุมค้นเจอ');
        $this->meeting($member, 'ประชุมอื่นที่ไม่ตรงคำค้น');

        $content = $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting', 'period' => 'all', 'search' => 'ค้นเจอ']))
            ->assertOk()
            ->assertSee('notion-database" data-view="meeting"', false)
            ->assertSee('action="'.route('mytasks.index').'"', false)
            ->assertSee('<input type="hidden" name="view" value="meeting">', false)
            ->assertSee('href="'.route('mytasks.index', ['view' => 'meeting']).'"', false)
            ->getContent();

        // ตัวกรองมีผลกับรายการประชุมเท่านั้น ข้อมูลที่ฝังไว้ให้ปฏิทินเป็นคนละชุดและไม่ถูกกรอง
        $list = $this->meetingListSection($content);
        $this->assertStringContainsString('ประชุมค้นเจอ', $list);
        $this->assertStringNotContainsString('ประชุมอื่นที่ไม่ตรงคำค้น', $list);
    }

    public function test_embedded_pagination_returns_to_my_tasks_with_the_meeting_view(): void
    {
        $member = $this->user();
        for ($index = 1; $index <= 21; $index++) {
            $this->meeting($member, 'ประชุมลำดับ '.$index);
        }

        $content = $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'meeting', 'period' => 'all']))
            ->assertOk()
            ->assertSee('meetings-page__pagination', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#href="[^"]*/my-tasks\?[^"]*view=meeting[^"]*page=2#',
            $content,
            'ลิงก์หน้าถัดไปต้องกลับมาที่ /my-tasks พร้อม view=meeting'
        );
        $this->assertStringNotContainsString('href="'.route('meetings.index').'?', $content);
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

    private function meetingListSection(string $content): string
    {
        $start = strpos($content, 'meetings-page__list');
        $this->assertNotFalse($start, 'ไม่พบรายการประชุมในหน้า');
        $end = strpos($content, '</section>', $start);
        $this->assertNotFalse($end, 'รายการประชุมไม่ถูกปิดแท็ก');

        return substr($content, $start, $end - $start);
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
