<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MeetingNotificationTest extends TestCase
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

    public function test_department_head_scheduling_a_meeting_notifies_every_attendee(): void
    {
        $head = $this->user('user', ['is_department_head' => true]);
        $first = $this->user();
        $second = $this->user();

        $this->actingAs($head)
            ->post(route('meetings.store'), $this->payload(['attendees' => [$first->id, $second->id]]))
            ->assertRedirect();

        foreach ([$first, $second] as $attendee) {
            $notification = SystemNotification::where('user_id', $attendee->id)->sole();

            $this->assertSame('meeting_scheduled', $notification->type);
            $this->assertSame('meeting', $notification->category);
            $this->assertSame((int) $head->id, (int) $notification->actor_user_id);
            $this->assertNull($notification->work_order_id);
            $this->assertSame((int) Meeting::sole()->id, (int) $notification->data['meeting_id']);
            // เวลาในข้อความต้องเป็นเวลาไทย ไม่ใช่ 03:00 ที่เป็นค่า UTC ของ 10:00 น.
            $this->assertStringContainsString('10:00', $notification->message);
            $this->assertStringContainsString('Product sync', $notification->message);
        }
    }

    public function test_creator_inactive_and_viewer_accounts_never_receive_the_meeting_notification(): void
    {
        $head = $this->user('user', ['is_department_head' => true]);
        $viewer = $this->user('viewer');
        $inactive = $this->user('user', ['is_active' => false]);
        $attendee = $this->user();

        $this->actingAs($head)->post(route('meetings.store'), $this->payload([
            'attendees' => [$attendee->id, $head->id],
        ]))->assertRedirect();

        $this->assertSame(1, SystemNotification::count());
        $this->assertSame((int) $attendee->id, (int) SystemNotification::sole()->user_id);

        // viewer และบัญชีที่ปิดใช้งานถูกกันตั้งแต่ชั้น validation ของผู้เข้าร่วมอยู่แล้ว
        $this->actingAs($head)->post(route('meetings.store'), $this->payload([
            'attendees' => [$viewer->id, $inactive->id],
        ]))->assertSessionHasErrors();

        $this->assertSame(0, SystemNotification::whereIn('user_id', [$viewer->id, $inactive->id])->count());
    }

    public function test_opening_a_meeting_notification_lands_on_the_meeting_detail_page(): void
    {
        $head = $this->user('user', ['is_department_head' => true]);
        $attendee = $this->user();

        $this->actingAs($head)
            ->post(route('meetings.store'), $this->payload(['attendees' => [$attendee->id]]))
            ->assertRedirect();

        $meeting = Meeting::sole();
        $notification = SystemNotification::where('user_id', $attendee->id)->sole();

        $this->actingAs($attendee)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('meetings.show', $meeting));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_for_a_deleted_meeting_falls_back_to_the_notification_center(): void
    {
        $head = $this->user('user', ['is_department_head' => true]);
        $attendee = $this->user();

        $this->actingAs($head)
            ->post(route('meetings.store'), $this->payload(['attendees' => [$attendee->id]]))
            ->assertRedirect();

        $notification = SystemNotification::where('user_id', $attendee->id)->sole();
        Meeting::sole()->delete();

        $this->actingAs($attendee)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('warning');
    }

    public function test_failed_meeting_creation_leaves_no_notification_behind(): void
    {
        Log::spy();
        $head = $this->user('user', ['is_department_head' => true]);
        $attendee = $this->user();

        DB::shouldReceive('transaction')->once()->andThrow(new \PDOException('database unavailable'));

        $this->actingAs($head)
            ->from(route('meetings.index'))
            ->post(route('meetings.store'), $this->payload(['attendees' => [$attendee->id]]))
            ->assertRedirect(route('meetings.index'))
            ->assertSessionHas('meeting_error');

        $this->assertSame(0, SystemNotification::count());
    }

    public function test_meeting_category_is_selectable_in_the_notification_center(): void
    {
        $head = $this->user('user', ['is_department_head' => true]);
        $attendee = $this->user();

        $this->actingAs($head)
            ->post(route('meetings.store'), $this->payload(['attendees' => [$attendee->id]]))
            ->assertRedirect();

        // ตรวจที่รายการที่ query คืนมา ไม่ใช่ HTML ทั้งหน้า เพราะ dropdown กระดิ่งใน layout
        // แสดงการแจ้งเตือนล่าสุดเสมอไม่ว่าตัวกรองของศูนย์การแจ้งเตือนจะเป็นอะไร
        $matching = $this->actingAs($attendee)
            ->get(route('notifications.index', ['category' => 'meeting']))
            ->assertOk()
            ->assertSee('มีการนัดประชุมใหม่')
            ->viewData('items');

        $this->assertSame(1, $matching->total());

        $other = $this->actingAs($attendee)
            ->get(route('notifications.index', ['category' => 'deadline']))
            ->assertOk()
            ->viewData('items');

        $this->assertSame(0, $other->total());
    }

    private function user(string $role = 'user', array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Product sync',
            'description' => 'Weekly product sync',
            'starts_at' => '2026-08-24T10:00',
            'ends_at' => '2026-08-24T11:00',
            'location' => 'ห้องใหญ่',
            'attendees' => [],
        ], $overrides);
    }
}
