<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Services\WorkLogRoutineMaterializer;
use App\Support\TodayWorkspace;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การสร้างรายการงานประจำจากแม่แบบ
 *
 * สองเรื่องที่ต้องคุมให้แน่น:
 * 1. ไม่ซ้ำ — ไม่ว่าจะมาจากการเปิดหน้า, artisan command, หรือสองแท็บพร้อมกัน
 * 2. วันในสัปดาห์ต้องคิดด้วยเวลากรุงเทพ ไม่ใช่ UTC (เช้าวันจันทร์ที่กรุงเทพ
 *    ยังเป็นวันอาทิตย์ตามเวลา UTC)
 */
class WorkLogRoutineMaterializationTest extends TestCase
{
    use RefreshDatabase;

    /** จันทร์ 7 ก.ย. 2026 เวลา 09:00 ที่กรุงเทพ (= 02:00 UTC) */
    private const MONDAY_MORNING_UTC = '2026-09-07 02:00:00';

    /** อาทิตย์ 6 ก.ย. 2026 เวลา 09:00 ที่กรุงเทพ */
    private const SUNDAY_MORNING_UTC = '2026-09-06 02:00:00';

    public function test_a_weekday_template_creates_one_entry_on_a_weekday(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน']);

        $created = app(WorkLogRoutineMaterializer::class)->materializeToday($owner);

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('work_logs', [
            'user_id' => $owner->id,
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'source' => 'template',
            'status' => 'open',
            'duration_minutes' => null,
        ]);
    }

    public function test_a_weekday_template_creates_nothing_on_sunday(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::SUNDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner);

        $this->assertSame(0, app(WorkLogRoutineMaterializer::class)->materializeToday($owner));
        $this->assertSame(0, WorkLog::query()->count());
    }

    /**
     * บั๊กที่ดีไซน์ทั้งหมดกันอยู่: 2026-09-06 17:30 UTC ยังเป็นวันอาทิตย์ตาม UTC
     * แต่เป็นเช้าวันจันทร์ที่กรุงเทพแล้ว แม่แบบ จ–ศ ต้องทำงาน
     */
    public function test_weekday_matching_uses_bangkok_time_not_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-06 17:30:00', 'UTC'));

        $this->assertSame('Sunday', now()->format('l'));
        $this->assertSame('Monday', TodayWorkspace::businessNow()->format('l'));

        $owner = $this->user();
        $this->template($owner, ['title' => 'งานประจำเช้าวันจันทร์']);

        $this->assertSame(1, app(WorkLogRoutineMaterializer::class)->materializeToday($owner));

        $log = WorkLog::query()->where('title', 'งานประจำเช้าวันจันทร์')->firstOrFail();
        $this->assertSame('2026-09-07', $log->work_date->format('Y-m-d'));
    }

    public function test_running_the_materializer_twice_creates_only_one_entry(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner);

        $materializer = app(WorkLogRoutineMaterializer::class);

        $this->assertSame(1, $materializer->materializeToday($owner));
        $this->assertSame(0, $materializer->materializeToday($owner->refresh()));
        $this->assertSame(1, WorkLog::query()->count());
    }

    /**
     * เส้นทางเปิดหน้าและ artisan command ต้องไม่สร้างซ้ำกัน
     */
    public function test_the_page_and_the_command_together_still_create_one_entry(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner);

        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();
        $this->artisan('worklogs:materialize-routines')->assertSuccessful();
        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        $this->assertSame(1, WorkLog::query()->count());
    }

    public function test_opening_the_page_creates_todays_routine_entries(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['title' => 'Backup ข้อมูลรายวัน']);

        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('Backup ข้อมูลรายวัน');

        $this->assertDatabaseHas('work_logs', ['title' => 'Backup ข้อมูลรายวัน', 'source' => 'template']);
    }

    /**
     * ปิดแม่แบบกลางสัปดาห์ต้องหยุดแค่วันถัดไป รายการของวันนี้ที่สร้างไปแล้วเป็น
     * ประวัติการทำงาน ต้องอยู่ครบ
     */
    public function test_deactivating_a_template_stops_tomorrow_and_keeps_today(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $template = $this->template($owner, ['title' => 'งานประจำที่จะปิด']);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        $this->assertSame(1, WorkLog::query()->count());

        $template->update(['is_active' => false]);

        // วันอังคาร
        $this->travelTo(CarbonImmutable::parse('2026-09-08 02:00:00', 'UTC'));
        $this->assertSame(0, app(WorkLogRoutineMaterializer::class)->materializeToday($owner->refresh()));

        // รายการของวันจันทร์ยังอยู่
        $this->assertSame(1, WorkLog::query()->count());
        $this->assertDatabaseHas('work_logs', ['title' => 'งานประจำที่จะปิด']);
    }

    /**
     * สร้างแม่แบบตอนบ่ายต้องเห็นผลวันเดียวกัน ไม่ใช่ต้องรอถึงพรุ่งนี้
     * ซึ่งทำให้ผู้ใช้รู้สึกว่าระบบไม่ทำงาน
     */
    public function test_a_template_created_midday_materializes_the_same_day(): void
    {
        // จันทร์ 14:00 ที่กรุงเทพ
        $this->travelTo(CarbonImmutable::parse('2026-09-07 07:00:00', 'UTC'));

        $owner = $this->user();

        $this->actingAs($owner)->post(route('daily-logs.routines.store'), [
            'title' => 'ตรวจสอบเครื่องสำรองไฟ',
            'kind' => 'routine',
            'weekdays' => [0, 1, 2, 3, 4],
        ])->assertRedirect();

        $this->assertDatabaseHas('work_logs', [
            'title' => 'ตรวจสอบเครื่องสำรองไฟ',
            'source' => 'template',
        ]);
    }

    /**
     * ระบบต้องไม่สร้างรายการย้อนหลังให้เอง เพราะจะกลายเป็นสัญญาณ "ไม่ได้ทำงาน"
     * ปลอม ๆ ของวันที่ลาหยุด ซึ่งจะไปเพี้ยนในรายงานภาระงาน
     */
    public function test_past_days_are_not_materialized_automatically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 02:00:00', 'UTC'));
        $owner = $this->user();
        $this->template($owner, ['title' => 'งานประจำ']);

        // ข้ามไปวันจันทร์โดยไม่เปิดหน้าเลยตลอดสุดสัปดาห์
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        // มีเฉพาะของวันจันทร์ ไม่มีของวันศุกร์ที่ผ่านมา
        $this->assertSame(1, WorkLog::query()->count());
        $this->assertSame(
            '2026-09-07',
            WorkLog::query()->firstOrFail()->work_date->format('Y-m-d')
        );
    }

    /**
     * วันย้อนหลังไม่มีปุ่ม "สร้างย้อนหลัง" อีกแล้ว
     *
     * การสร้างรายการของวันที่ผ่านไปแล้วเท่ากับเปิดให้กดเริ่มงานย้อนหลัง ซึ่งจะ
     * บันทึกเวลาของวันนี้ลงในรายการของเมื่อวาน สิ่งที่เหลือให้ทำได้คือระบุเหตุผล
     */
    public function test_a_past_day_offers_a_reason_button_instead_of_backfill(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 02:00:00', 'UTC'));
        $owner = $this->user();
        $this->template($owner, ['title' => 'งานประจำที่ยังไม่ได้ลง']);

        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => '2026-09-04']))
            ->assertOk()
            ->assertSee('งานประจำที่ยังไม่ได้ลง')
            ->assertSee('ระบุเหตุผลที่ไม่ได้ทำ')
            ->assertDontSee('สร้างย้อนหลัง');
    }

    /**
     * การเปิดดูวันย้อนหลังต้องไม่สร้างรายการใด ๆ ให้เอง
     */
    public function test_opening_a_past_day_creates_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 02:00:00', 'UTC'));
        $owner = $this->user();
        $this->template($owner, ['title' => 'งานประจำย้อนหลัง']);

        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => '2026-09-04']))
            ->assertOk();

        $this->assertSame(0, WorkLog::query()->whereDate('work_date', '2026-09-04')->count());
        $this->assertSame(
            0,
            app(WorkLogRoutineMaterializer::class)->materializeDay(
                $owner,
                TodayWorkspace::businessNow(CarbonImmutable::parse('2026-09-04 02:00:00', 'UTC'))->startOfDay()
            ),
            'materializeDay ต้องไม่สร้างรายการของวันที่ผ่านไปแล้ว'
        );
    }

    /**
     * ทางเดียวที่วันย้อนหลังจะมีรายการ คือเจ้าของระบุเหตุผลที่ไม่ได้ทำ
     * และรายการนั้นต้องเกิดในสถานะ "ไม่ได้ทำ" ไม่ใช่ "รอเริ่ม"
     */
    public function test_recording_a_reason_creates_a_skipped_entry_for_the_past_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 02:00:00', 'UTC'));
        $owner = $this->user();
        $template = $this->template($owner, ['title' => 'งานประจำที่ลืมทำ']);

        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $this->actingAs($owner)
            ->post(route('daily-logs.routines.missed', $template), [
                'date' => '2026-09-04',
                'reason' => 'ลางาน',
            ])
            ->assertRedirect();

        $log = WorkLog::query()->whereDate('work_date', '2026-09-04')->firstOrFail();

        $this->assertSame('skipped', $log->status);
        $this->assertSame('ลางาน', $log->skip_reason);
        $this->assertNull($log->started_at);
        $this->assertNull($log->duration_minutes);

        // ระบุซ้ำอีกครั้งต้องไม่เกิดรายการที่สอง
        $this->actingAs($owner)
            ->post(route('daily-logs.routines.missed', $template), [
                'date' => '2026-09-04',
                'reason' => 'ลางาน',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, WorkLog::query()->whereDate('work_date', '2026-09-04')->count());
    }

    /**
     * เหตุผลย้อนหลังใช้กับ "วันนี้" ไม่ได้ วันนี้มีปุ่มเริ่มงานและปุ่มไม่ได้ทำวันนี้อยู่แล้ว
     */
    public function test_a_reason_cannot_be_recorded_for_today(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $owner = $this->user();
        $template = $this->template($owner);

        $this->actingAs($owner)
            ->post(route('daily-logs.routines.missed', $template), [
                'date' => '2026-09-07',
                'reason' => 'ลืมทำ',
            ])
            ->assertSessionHasErrors('date');
    }

    /**
     * เจ้าของลบรายการงานประจำของวันนี้ทิ้ง = บอกว่าวันนี้ไม่ได้ทำ
     * ระบบต้องไม่สร้างกลับมาให้ใหม่ทุกครั้งที่เปิดหน้า
     */
    public function test_a_deleted_routine_entry_is_not_recreated(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['title' => 'งานประจำที่ไม่ได้ทำวันนี้']);

        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        $log = WorkLog::query()->firstOrFail();
        $this->actingAs($owner)->delete(route('daily-logs.destroy', $log))->assertRedirect();

        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        $this->assertSame(0, WorkLog::query()->count(), 'ต้องไม่ถูกสร้างกลับมาใหม่');
    }

    public function test_a_user_without_templates_opens_the_page_normally(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();

        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        $this->assertSame(0, WorkLog::query()->count());
    }

    /**
     * แม่แบบเป็นราย user ผู้ใช้ที่เพิ่งถูกเพิ่มเข้าระบบจึงไม่มีอะไรค้าง
     * และไม่ทำให้ของคนอื่นถูกสร้างข้ามกัน
     */
    public function test_templates_are_materialized_per_user_only(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $department = Department::create(['department_name' => 'IT']);
        $first = $this->user($department);
        $second = $this->user($department);

        $this->template($first, ['title' => 'งานของคนแรก']);

        $this->artisan('worklogs:materialize-routines')->assertSuccessful();

        $this->assertSame(1, WorkLog::query()->where('user_id', $first->id)->count());
        $this->assertSame(0, WorkLog::query()->where('user_id', $second->id)->count());
    }

    public function test_a_template_start_time_orders_the_entry_but_leaves_it_open(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['default_start_time' => '08:30']);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);

        $log = WorkLog::query()->firstOrFail();

        // 08:30 ที่กรุงเทพ = 01:30 UTC
        $this->assertSame('2026-09-07 01:30:00', $log->planned_start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($log->started_at, 'ต้องรอให้ผู้ใช้กดเริ่มก่อนจึงบันทึกเวลาเริ่มจริง');
        $this->assertSame('open', $log->status);
        $this->assertNull($log->duration_minutes, 'ระบบต้องไม่เดาแทนว่าทำไปกี่นาที');
    }

    public function test_a_template_carries_its_kind_and_category_to_the_entry(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $category = WorkLogCategory::query()->firstOrFail();
        $this->template($owner, ['kind' => 'field', 'work_log_category_id' => $category->id]);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);

        $this->assertDatabaseHas('work_logs', [
            'kind' => 'field',
            'work_log_category_id' => $category->id,
        ]);
    }

    public function test_a_template_outside_its_date_window_is_skipped(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['title' => 'ยังไม่เริ่ม', 'starts_on' => '2026-09-10']);
        $this->template($owner, ['title' => 'หมดอายุแล้ว', 'ends_on' => '2026-09-01']);

        $this->assertSame(0, app(WorkLogRoutineMaterializer::class)->materializeToday($owner));
    }

    /**
     * งานประจำเป็นโหมดหนึ่งในกล่องเพิ่มงานของหน้าบันทึกงาน ไม่มีหน้าแยกและ
     * ไม่มีกล่องของตัวเองอีกต่อไป
     *
     * ชื่อที่ใช้ทดสอบต้องไม่ชนกับข้อความ placeholder ในฟอร์มเพิ่ม ไม่งั้น
     * assertDontSee จะเจอ placeholder แล้วสอบตกทั้งที่โค้ดถูก
     */
    public function test_the_routine_mode_lists_templates_and_is_private(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);

        $this->template($owner, ['title' => 'สำรองฐานข้อมูลกลางคืน']);

        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('data-entry-panel="routine"', false)
            ->assertSee('สำรองฐานข้อมูลกลางคืน')
            ->assertSee('จ–ศ');

        // หัวหน้าเปิดหน้าของตัวเองได้ แต่ต้องไม่เห็นแม่แบบของลูกทีม
        $this->actingAs($head)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertDontSee('สำรองฐานข้อมูลกลางคืน');
    }

    /**
     * ลิงก์เดิมของหน้าแม่แบบที่ถูกบุ๊กมาร์กไว้ต้องไม่กลายเป็น 404
     */
    public function test_the_old_routines_page_redirects_into_the_daily_log(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->get(route('daily-logs.routines.index'))
            ->assertRedirect(route('daily-logs.index'));
    }

    public function test_a_member_cannot_delete_another_members_template(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $template = $this->template($owner);

        $this->actingAs($head)
            ->delete(route('daily-logs.routines.destroy', $template))
            ->assertForbidden();

        $this->assertDatabaseHas('work_log_templates', ['id' => $template->id]);
    }

    /**
     * ลบแม่แบบ = หยุดสร้างรายการใหม่ ไม่ใช่ลบประวัติที่ผ่านมา
     */
    public function test_deleting_a_template_keeps_the_entries_it_already_created(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $template = $this->template($owner, ['title' => 'งานประจำที่เคยทำ']);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);

        $this->actingAs($owner)
            ->delete(route('daily-logs.routines.destroy', $template))
            ->assertRedirect();

        $this->assertDatabaseMissing('work_log_templates', ['id' => $template->id]);
        $this->assertDatabaseHas('work_logs', ['title' => 'งานประจำที่เคยทำ']);
    }

    public function test_a_template_requires_at_least_one_weekday(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->post(route('daily-logs.routines.store'), [
                'title' => 'ไม่เลือกวันเลย',
                'kind' => 'routine',
                'weekdays' => [],
            ])
            ->assertSessionHasErrors('weekdays');
    }

    public function test_viewer_cannot_reach_the_routine_settings(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT']), false, 'viewer');

        $this->actingAs($viewer)->get(route('daily-logs.routines.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('daily-logs.routines.store'), [
            'title' => 'งานของ viewer',
            'kind' => 'routine',
            'weekdays' => [0],
        ])->assertForbidden();
    }

    private function user(?Department $department = null, bool $head = false, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => ($department ?? Department::create(['department_name' => 'IT-'.uniqid()]))->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function template(User $owner, array $overrides = []): WorkLogTemplate
    {
        return WorkLogTemplate::create(array_merge([
            'user_id' => $owner->id,
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK,
            'is_active' => true,
        ], $overrides));
    }
}
