<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\WorkLogService;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตัวจับเวลาของบันทึกงานประจำวัน
 *
 * กติกาที่ต้องคุมให้แน่นคือ "หนึ่งคนจับเวลาได้ทีละงานเดียว" ซึ่งบังคับด้วย
 * unique index ที่ฐานข้อมูล ไม่ใช่การเช็คในโค้ด เพราะการเช็คก่อนเขียนถูก race ได้
 * เมื่อผู้ใช้เปิดสองแท็บแล้วกดพร้อมกัน
 */
class WorkLogTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_timer_creates_an_open_running_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'Support เครื่องพิมพ์บัญชี',
            'kind' => 'interrupt',
        ])->assertRedirect();

        $log = WorkLog::query()->where('title', 'Support เครื่องพิมพ์บัญชี')->firstOrFail();

        $this->assertSame('open', $log->status);
        $this->assertSame($owner->id, $log->open_timer_owner_id);
        $this->assertNotNull($log->started_at);
        $this->assertNull($log->ended_at);
        $this->assertNull($log->duration_minutes);
        $this->assertSame($department->id, $log->department_id);
    }

    /**
     * กดเริ่มซ้ำขณะยังจับเวลาอยู่ต้องล้มเหลว และต้องไม่เกิดแถวที่สอง
     */
    public function test_a_second_timer_is_rejected_and_creates_no_extra_row(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'งานแรก',
            'kind' => 'routine',
        ])->assertRedirect();

        $this->actingAs($owner)
            ->post(route('daily-logs.timer.start'), ['title' => 'งานที่สอง', 'kind' => 'routine'])
            ->assertSessionHasErrors('timer');

        $this->assertSame(1, WorkLog::query()->where('user_id', $owner->id)->count());
        $this->assertDatabaseMissing('work_logs', ['title' => 'งานที่สอง']);
    }

    public function test_the_rejection_message_tells_the_user_what_to_do(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'งานแรก',
            'kind' => 'routine',
        ]);

        $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.timer.start'), ['title' => 'งานที่สอง', 'kind' => 'routine'])
            ->assertStatus(422)
            ->assertJsonPath('errors.timer.0', 'คุณมีงานที่กำลังจับเวลาอยู่แล้ว กรุณากดเสร็จสิ้นงานนั้นก่อน');
    }

    /**
     * unique index อาศัยว่า NULL ไม่ชนกัน — จริงทั้งบน MySQL (InnoDB) และ SQLite
     * เทสต์นี้ยึดสมมติฐานนั้นไว้ ไม่งั้นระบบจะกลายเป็น "ทั้งบริษัทจับเวลาได้ทีละคน"
     */
    public function test_two_people_can_run_timers_at_the_same_time(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $first = $this->user($department);
        $second = $this->user($department);

        $this->actingAs($first)
            ->post(route('daily-logs.timer.start'), ['title' => 'งานของคนแรก', 'kind' => 'routine'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($second)
            ->post(route('daily-logs.timer.start'), ['title' => 'งานของคนที่สอง', 'kind' => 'routine'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, WorkLog::query()->whereNotNull('open_timer_owner_id')->count());
    }

    public function test_stopping_a_timer_computes_the_duration_from_server_time(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->travelTo(TodayWorkspace::businessNow()->startOfDay()->addHours(9)->utc());

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'ตรวจสอบคอมพิวเตอร์',
            'kind' => 'routine',
        ]);

        $log = WorkLog::query()->where('user_id', $owner->id)->firstOrFail();

        $this->travel(40)->minutes();

        $this->actingAs($owner)->post(route('daily-logs.timer.stop', $log))->assertRedirect();

        $log->refresh();

        $this->assertSame(40, $log->duration_minutes);
        $this->assertSame('done', $log->status);
        $this->assertNull($log->open_timer_owner_id);
        $this->assertNotNull($log->ended_at);
    }

    /**
     * งานที่สั้นกว่าหนึ่งนาทีต้องไม่กลายเป็นรายการที่ปิดแล้วแต่เวลาเป็นศูนย์
     */
    public function test_a_very_short_task_is_recorded_as_one_minute(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'รีสตาร์ทเครื่องพิมพ์',
            'kind' => 'interrupt',
        ]);

        $log = WorkLog::query()->where('user_id', $owner->id)->firstOrFail();

        $this->travel(20)->seconds();
        $this->actingAs($owner)->post(route('daily-logs.timer.stop', $log));

        $this->assertSame(WorkLogDesign::MIN_DURATION_MINUTES, $log->refresh()->duration_minutes);
    }

    public function test_stopping_an_already_stopped_timer_is_rejected_and_keeps_the_duration(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'งานทดสอบ',
            'kind' => 'routine',
        ]);

        $log = WorkLog::query()->where('user_id', $owner->id)->firstOrFail();

        $this->travel(15)->minutes();
        $this->actingAs($owner)->post(route('daily-logs.timer.stop', $log));

        $recorded = $log->refresh()->duration_minutes;

        // งานปิดแล้ว manageTimer จึงไม่ผ่าน policy อีกต่อไป
        $this->actingAs($owner)->post(route('daily-logs.timer.stop', $log))->assertForbidden();

        $this->assertSame($recorded, $log->refresh()->duration_minutes);
    }

    /**
     * เริ่มจับเวลาบนรายการที่มีอยู่แล้ว เช่น งานประจำที่ระบบสร้างให้ตอนเช้า
     */
    public function test_an_existing_open_log_can_be_resumed(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->openLog($owner, $department);

        $this->actingAs($owner)->post(route('daily-logs.timer.resume', $log))->assertRedirect();

        $log->refresh();

        $this->assertSame($owner->id, $log->open_timer_owner_id);
        $this->assertNotNull($log->started_at);
    }

    public function test_resuming_while_another_timer_runs_is_rejected(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->openLog($owner, $department);

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'งานที่กำลังทำ',
            'kind' => 'routine',
        ]);

        $this->actingAs($owner)
            ->post(route('daily-logs.timer.resume', $log))
            ->assertSessionHasErrors('timer');

        $this->assertNull($log->refresh()->open_timer_owner_id);
    }

    public function test_a_member_cannot_control_another_members_timer(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $log = $this->openLog($owner, $department);

        // หัวหน้าเห็นได้ แต่กดจับเวลาแทนลูกทีมไม่ได้
        $this->actingAs($head)->post(route('daily-logs.timer.resume', $log))->assertForbidden();
    }

    public function test_viewer_cannot_start_a_timer(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT']), false, 'viewer');

        $this->actingAs($viewer)
            ->post(route('daily-logs.timer.start'), ['title' => 'งานของ viewer', 'kind' => 'routine'])
            ->assertForbidden();
    }

    /**
     * ลบรายการที่กำลังจับเวลาแล้วต้องเริ่มงานใหม่ได้ทันที
     *
     * work_logs เป็น soft delete แถวจึงยังอยู่ในตาราง ถ้าไม่เคลียร์
     * open_timer_owner_id ก่อนลบ unique index จะยังกันผู้ใช้ไว้ตลอดไป
     */
    public function test_deleting_a_running_log_frees_the_timer_slot(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'งานที่จะลบ',
            'kind' => 'routine',
        ]);

        $log = WorkLog::query()->where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)->delete(route('daily-logs.destroy', $log))->assertRedirect();

        $this->actingAs($owner)
            ->post(route('daily-logs.timer.start'), ['title' => 'งานใหม่', 'kind' => 'routine'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('work_logs', ['title' => 'งานใหม่', 'open_timer_owner_id' => $owner->id]);
    }

    public function test_the_running_timer_is_shown_on_the_daily_page(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'งานที่กำลังทำ',
            'kind' => 'routine',
        ]);

        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('data-timer-banner', false)
            ->assertSee('กำลังทำอยู่')
            ->assertSee('งานที่กำลังทำ');
    }

    /**
     * หน้าอ่านอย่างเดียวของหัวหน้าต้องไม่มีปุ่มจับเวลาให้กดแทนลูกทีม
     */
    public function test_a_read_only_day_has_no_timer_controls(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $this->openLog($owner, $department);

        $this->actingAs($head)
            ->get(route('daily-logs.index', ['user' => $owner->id]))
            ->assertOk()
            ->assertDontSee('data-timer-banner', false)
            ->assertDontSee('data-row-timer-start', false);
    }

    /**
     * จับเวลาย้อนหลังไม่มีความหมาย เพราะเวลามาจากนาฬิกาของเซิร์ฟเวอร์ตอนที่กด
     */
    public function test_a_past_day_offers_no_timer_controls(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $yesterday = TodayWorkspace::businessNow()->subDay()->format('Y-m-d');

        $this->openLog($owner, $department, ['work_date' => $yesterday]);

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => $yesterday]))
            ->assertOk()
            ->assertDontSee('data-composer-start', false)
            ->assertDontSee('data-row-timer-start', false);
    }

    public function test_the_ajax_payload_carries_the_running_state(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.timer.start'), ['title' => 'งานผ่าน AJAX', 'kind' => 'routine'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $response->assertJsonStructure(['ok', 'message', 'log', 'summary', 'html']);
        $this->assertTrue($response->json('log.is_running'));
        $this->assertNotNull($response->json('log.started_at'));
        $this->assertStringContainsString('กำลังทำ', $response->json('log.time_range_label'));
    }

    public function test_the_artisan_command_closes_timers_left_running(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->travelTo(TodayWorkspace::businessNow()->startOfDay()->addHours(22)->utc());
        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'ลืมกดจบงาน',
            'kind' => 'routine',
        ]);

        // ข้ามไปวันถัดไปตามเวลาทำการ
        $this->travel(6)->hours();

        $this->artisan('worklogs:close-stale-timers')->assertSuccessful();

        $log = WorkLog::query()->where('title', 'ลืมกดจบงาน')->firstOrFail();

        $this->assertNull($log->open_timer_owner_id);
        $this->assertSame('done', $log->status);
        $this->assertNotNull($log->auto_closed_at);
    }

    /**
     * ระบบไม่รับประกันว่า cron ถูกตั้งไว้บนเครื่อง production การเปิดหน้าจึงต้อง
     * ปิดตัวจับเวลาที่ค้างให้ด้วย ไม่ใช่รอคำสั่งตามเวลาอย่างเดียว
     */
    public function test_opening_the_page_also_closes_stale_timers(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->travelTo(TodayWorkspace::businessNow()->startOfDay()->addHours(21)->utc());
        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'ลืมกดจบงานเมื่อวาน',
            'kind' => 'routine',
        ]);

        $this->travel(8)->hours();

        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        $log = WorkLog::query()->where('title', 'ลืมกดจบงานเมื่อวาน')->firstOrFail();

        $this->assertNull($log->open_timer_owner_id);
        $this->assertNotNull($log->auto_closed_at);
    }

    /**
     * ตัวจับเวลาที่ค้างต้องถูกจำกัดไม่ให้กลายเป็นรายการหลายสิบชั่วโมง
     * ที่จะทำให้รายงานภาระงานเพี้ยน
     */
    public function test_an_auto_closed_timer_is_capped(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->travelTo(TodayWorkspace::businessNow()->startOfDay()->addHours(8)->utc());
        $this->actingAs($owner)->post(route('daily-logs.timer.start'), [
            'title' => 'ลืมกดจบงานหลายวัน',
            'kind' => 'routine',
        ]);

        $this->travel(3)->days();

        app(WorkLogService::class)->closeStaleTimers($owner->refresh());

        $log = WorkLog::query()->where('title', 'ลืมกดจบงานหลายวัน')->firstOrFail();

        $this->assertNotNull($log->duration_minutes);
        $this->assertLessThanOrEqual(WorkLogDesign::MAX_TIMER_MINUTES, $log->duration_minutes);
    }

    /**
     * แก้เวลาด้วยมือถือว่าเจ้าของยืนยันตัวเลขเองแล้ว ป้ายเตือนจึงต้องหายไป
     */
    public function test_editing_an_auto_closed_log_clears_the_warning(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->openLog($owner, $department, [
            'status' => 'done',
            'duration_minutes' => 720,
            'auto_closed_at' => now(),
        ]);

        $this->actingAs($owner)->patch(route('daily-logs.update', $log), [
            'title' => $log->title,
            'kind' => 'routine',
            'duration_minutes' => 90,
        ])->assertRedirect();

        $log->refresh();

        $this->assertNull($log->auto_closed_at);
        $this->assertSame(90, $log->duration_minutes);
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

    private function openLog(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department?->id,
            'kind' => 'routine',
            'status' => 'open',
            'source' => 'manual',
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ], $overrides));
    }
}
