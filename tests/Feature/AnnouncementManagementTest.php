<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\DailyBriefAcknowledgement;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ศูนย์ประกาศของหัวหน้าแผนก — เส้นทาง HTTP จริงตั้งแต่ route middleware ถึง policy
 *
 * เวลาถูกตรึงไว้ที่ 10:00 น. เวลาไทยของวันที่ 25 ก.ย. 2569 (03:00 UTC)
 */
class AnnouncementManagementTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-25';

    private Department $it;

    private Department $hr;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);
        $this->head = $this->user('user', $this->it, ['is_department_head' => true, 'name' => 'สมชาย ใจดี']);

        // สรุปประจำวันก็แสดงประกาศเหมือนกัน ทดสอบหน้าศูนย์ประกาศล้วน ๆ จึงรับทราบสรุปของวันนี้ไว้ก่อน
        // (พฤติกรรมของสรุปอยู่ใน DailyBriefTest)
        DailyBriefAcknowledgement::create([
            'user_id' => $this->head->id,
            'brief_date' => self::TODAY,
            'acknowledged_at' => now(),
        ]);
    }

    public function test_only_department_heads_reach_the_announcement_center(): void
    {
        $this->actingAs($this->head)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('ศูนย์ประกาศประจำวัน');

        $this->actingAs($this->user('user', $this->it))->get(route('announcements.index'))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('announcements.index'))->assertForbidden();
        $this->actingAs($this->user('viewer'))->get(route('announcements.index'))->assertForbidden();
    }

    public function test_sidebar_link_is_shown_only_to_department_heads(): void
    {
        $link = 'href="'.route('announcements.index').'"';

        $this->actingAs($this->head)->get(route('settings.index'))->assertSee($link, false);
        $this->actingAs($this->user('user', $this->it))->get(route('settings.index'))->assertDontSee($link, false);
        $this->actingAs($this->user('admin'))->get(route('settings.index'))->assertDontSee($link, false);
    }

    public function test_head_creates_an_announcement_bound_to_their_own_account_and_department(): void
    {
        $this->actingAs($this->head)
            ->post(route('announcements.store'), [
                'title' => 'ปิดปรับปรุงระบบเซิร์ฟเวอร์',
                'body' => "พรุ่งนี้ปิดปรับปรุง\nขออภัยในความไม่สะดวก",
                'audience' => 'all',
                'starts_on' => self::TODAY,
                'ends_on' => '2026-09-30',
                // ฟอร์มไม่มีช่องเหล่านี้ ค่าที่แอบส่งมาต้องถูกเมิน
                'department_id' => $this->hr->id,
                'created_by' => $this->user('user', $this->hr)->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $announcement = Announcement::sole();
        $this->assertSame($this->head->id, $announcement->created_by);
        $this->assertSame($this->it->id, $announcement->department_id);
        $this->assertSame('all', $announcement->audience);
        $this->assertSame('2026-09-30', $announcement->ends_on->format('Y-m-d'));
        $this->assertTrue(ActivityLog::query()->where('action', 'announcement_created')->exists());
    }

    public function test_store_validates_dates_and_audience(): void
    {
        $this->actingAs($this->head)
            ->post(route('announcements.store'), [
                'title' => '',
                'body' => '',
                'audience' => 'everyone-in-the-world',
                'starts_on' => '2026-09-24',
                'ends_on' => '2026-09-20',
            ])
            ->assertSessionHasErrors(['title', 'body', 'audience', 'starts_on', 'ends_on']);

        $this->assertSame(0, Announcement::count());
    }

    public function test_employee_admin_and_viewer_cannot_create_announcements(): void
    {
        $payload = ['title' => 'x', 'body' => 'y', 'audience' => 'all', 'starts_on' => self::TODAY];

        $this->actingAs($this->user('user', $this->it))->post(route('announcements.store'), $payload)->assertForbidden();
        $this->actingAs($this->user('admin'))->post(route('announcements.store'), $payload)->assertForbidden();
        $this->actingAs($this->user('viewer'))->post(route('announcements.store'), $payload)->assertForbidden();

        $this->assertSame(0, Announcement::count());
    }

    public function test_head_updates_own_announcement_and_can_keep_a_past_start_date(): void
    {
        $announcement = $this->announcement($this->head, ['starts_on' => '2026-09-20']);

        $this->actingAs($this->head)
            ->patch(route('announcements.update', $announcement), [
                'title' => 'หัวข้อใหม่',
                'body' => 'เนื้อหาใหม่',
                'audience' => 'department',
                'starts_on' => '2026-09-20',
                'ends_on' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $announcement->refresh();
        $this->assertSame('หัวข้อใหม่', $announcement->title);
        $this->assertNull($announcement->ends_on);
        $this->assertTrue(ActivityLog::query()->where('action', 'announcement_updated')->exists());

        // ขยับวันเริ่มให้ย้อนไปก่อนวันเดิมไม่ได้
        $this->actingAs($this->head)
            ->patch(route('announcements.update', $announcement), [
                'title' => 'หัวข้อใหม่', 'body' => 'เนื้อหาใหม่', 'audience' => 'department', 'starts_on' => '2026-09-01',
            ])
            ->assertSessionHasErrors('starts_on');
    }

    public function test_head_cannot_edit_or_delete_someone_elses_announcement(): void
    {
        $coHead = $this->user('user', $this->it, ['is_department_head' => true]);
        $announcement = $this->announcement($coHead);

        $this->actingAs($this->head)
            ->patch(route('announcements.update', $announcement), [
                'title' => 'แก้ของคนอื่น', 'body' => 'x', 'audience' => 'all', 'starts_on' => self::TODAY,
            ])
            ->assertForbidden();

        $this->actingAs($this->head)->delete(route('announcements.destroy', $announcement))->assertForbidden();

        $this->assertNotSame('แก้ของคนอื่น', $announcement->fresh()->title);
        $this->assertNull($announcement->fresh()->deleted_at);
    }

    public function test_head_soft_deletes_own_announcement_with_an_audit_entry(): void
    {
        $announcement = $this->announcement($this->head);

        $this->actingAs($this->head)
            ->delete(route('announcements.destroy', $announcement))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSoftDeleted($announcement);
        $this->assertTrue(ActivityLog::query()->where('action', 'announcement_deleted')->exists());

        // ประกาศที่ลบแล้วแก้ต่อไม่ได้ (route model binding ไม่คืนแถวที่ถูก soft delete)
        $this->actingAs($this->head)->delete(route('announcements.destroy', $announcement))->assertNotFound();
    }

    public function test_list_shows_own_department_and_all_department_announcements_with_menu_only_on_own_rows(): void
    {
        $coHead = $this->user('user', $this->it, ['is_department_head' => true]);
        $hrHead = $this->user('user', $this->hr, ['is_department_head' => true, 'name' => 'สุภาวดี พงศ์ศิริ']);

        $mine = $this->announcement($this->head, ['title' => 'ประกาศของฉัน']);
        $this->announcement($coHead, ['title' => 'ประกาศของหัวหน้าร่วม']);
        $this->announcement($hrHead, ['title' => 'ประกาศถึงทุกแผนกจาก HR', 'audience' => 'all']);
        $this->announcement($hrHead, ['title' => 'ประกาศภายใน HR']);

        $response = $this->actingAs($this->head)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('ประกาศของฉัน')
            ->assertSee('ประกาศของหัวหน้าร่วม')
            ->assertSee('ประกาศถึงทุกแผนกจาก HR')
            ->assertSee('หัวหน้าแผนก · HR')
            ->assertDontSee('ประกาศภายใน HR');

        // เมนูแก้ไข/ลบมีเฉพาะแถวที่ตัวเองเป็นเจ้าของ
        $response->assertSee('action="'.route('announcements.destroy', $mine).'"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-announcement-delete'));
    }

    public function test_status_tabs_follow_the_bangkok_business_day(): void
    {
        $this->announcement($this->head, ['title' => 'กำลังแสดงอยู่', 'starts_on' => self::TODAY]);
        $this->announcement($this->head, ['title' => 'รอแสดงพรุ่งนี้', 'starts_on' => '2026-09-26']);
        $this->announcement($this->head, ['title' => 'หมดอายุแล้ว', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-24']);

        $this->actingAs($this->head)->get(route('announcements.index', ['tab' => 'active']))
            ->assertSee('กำลังแสดงอยู่')->assertDontSee('รอแสดงพรุ่งนี้')->assertDontSee('หมดอายุแล้ว');
        $this->actingAs($this->head)->get(route('announcements.index', ['tab' => 'pending']))
            ->assertSee('รอแสดงพรุ่งนี้')->assertDontSee('กำลังแสดงอยู่');
        $this->actingAs($this->head)->get(route('announcements.index', ['tab' => 'expired']))
            ->assertSee('หมดอายุแล้ว')->assertDontSee('กำลังแสดงอยู่');

        // 17:30 UTC ของวันที่ 25 คือ 00:30 ของวันที่ 26 ตามเวลาไทย ประกาศของ "พรุ่งนี้" ต้องเริ่มแสดงแล้ว
        $this->travelTo(now()->parse(self::TODAY.' 17:30:00', 'UTC'));
        $this->actingAs($this->head)->get(route('announcements.index', ['tab' => 'active']))
            ->assertSee('รอแสดงพรุ่งนี้');
    }

    public function test_search_filters_titles(): void
    {
        $this->announcement($this->head, ['title' => 'นโยบาย Hybrid']);
        $this->announcement($this->head, ['title' => 'ปิดปรับปรุงระบบ']);

        $this->actingAs($this->head)->get(route('announcements.index', ['q' => 'Hybrid']))
            ->assertSee('นโยบาย Hybrid')
            ->assertDontSee('ปิดปรับปรุงระบบ');
    }

    public function test_department_with_announcements_cannot_be_deleted(): void
    {
        $department = Department::create(['department_name' => 'Temp']);
        $announcement = new Announcement([
            'title' => 'x', 'body' => 'y', 'audience' => 'department', 'starts_on' => self::TODAY,
        ]);
        $announcement->created_by = $this->head->id;
        $announcement->department_id = $department->id;
        $announcement->save();
        $announcement->delete();

        $this->actingAs($this->user('admin'))
            ->delete(route('admin.departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    private function announcement(User $author, array $attributes = []): Announcement
    {
        $announcement = new Announcement([
            'title' => 'ประกาศ',
            'body' => 'รายละเอียดประกาศ',
            'audience' => Announcement::AUDIENCE_DEPARTMENT,
            'starts_on' => self::TODAY,
            ...$attributes,
        ]);
        $announcement->created_by = $author->id;
        $announcement->department_id = $author->department_id;
        $announcement->save();

        return $announcement;
    }

    private function user(string $role, ?Department $department = null, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
