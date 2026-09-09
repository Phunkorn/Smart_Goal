<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\WorkLogService;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เขตเวลาของบันทึกงานประจำวัน
 *
 * ฐานข้อมูลเก็บเป็น UTC แต่เวลาทำการคือ Asia/Bangkok ซึ่งเร็วกว่า 7 ชั่วโมง
 * ช่วง 17:00–23:59 UTC จึงเป็น "วันถัดไป" ที่กรุงเทพแล้ว หากคำนวณด้วย UTC
 * ตรง ๆ ผู้ใช้ที่เปิดหน้าตอนเช้าจะเห็นวันของเมื่อวาน และงานที่บันทึกตอนเช้า
 * จะไปตกอยู่ผิดวันโดยไม่มีใครสังเกต
 *
 * เทสต์ชุดนี้จึงใช้เวลาที่คร่อมเที่ยงคืนของกรุงเทพเป็นหลัก
 */
class WorkLogTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-07 17:30 UTC = อังคาร 8 ก.ย. 00:30 ที่กรุงเทพ */
    private const AFTER_BANGKOK_MIDNIGHT = '2026-09-07 17:30:00';

    /** 2026-09-07 16:59 UTC = จันทร์ 7 ก.ย. 23:59 ที่กรุงเทพ */
    private const BEFORE_BANGKOK_MIDNIGHT = '2026-09-07 16:59:00';

    public function test_the_page_shows_the_bangkok_day_not_the_utc_day(): void
    {
        $owner = $this->user();

        $this->travelTo(CarbonImmutable::parse(self::AFTER_BANGKOK_MIDNIGHT, 'UTC'));

        // ยังเป็นวันจันทร์ตาม UTC แต่เป็นวันอังคารแล้วที่กรุงเทพ
        $this->assertSame('Monday', now()->format('l'));
        $this->assertSame('Tuesday', TodayWorkspace::businessNow()->format('l'));

        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'งานเช้าวันอังคาร',
            'kind' => 'routine',
            'duration_minutes' => 30,
        ])->assertRedirect();

        $this->assertSame('2026-09-08', $this->workDateOf('งานเช้าวันอังคาร'));
    }

    /**
     * ยอดสรุปต้องย้ายไปวันใหม่ทันทีที่ข้ามเที่ยงคืนของกรุงเทพ ไม่ใช่ของ UTC
     */
    public function test_the_daily_summary_rolls_over_at_bangkok_midnight(): void
    {
        $owner = $this->user();

        $this->travelTo(CarbonImmutable::parse(self::BEFORE_BANGKOK_MIDNIGHT, 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'งานก่อนเที่ยงคืน',
            'kind' => 'routine',
            'duration_minutes' => 45,
        ])->assertRedirect();

        // ผ่านไปสองนาที = ข้ามเที่ยงคืนของกรุงเทพแล้ว
        $this->travelTo(CarbonImmutable::parse('2026-09-07 17:01:00', 'UTC'));

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.store'), [
                'title' => 'งานหลังเที่ยงคืน',
                'kind' => 'routine',
                'duration_minutes' => 20,
            ])->assertOk();

        // ยอดของวันใหม่ต้องนับเฉพาะรายการของวันใหม่
        $this->assertSame(20, $response->json('summary.total_minutes'));

        $this->assertSame('2026-09-07', $this->workDateOf('งานก่อนเที่ยงคืน'));
        $this->assertSame('2026-09-08', $this->workDateOf('งานหลังเที่ยงคืน'));
    }

    /**
     * งานที่บันทึกก่อนเที่ยงคืนแล้วมากดยืนยันหลังเที่ยงคืน ต้องยังนับเป็นงานของ
     * วันที่บันทึก ไม่งั้นวันที่ทำงานจริงจะดูเหมือนไม่มีงาน แล้วไปโผล่ในวันถัดไปแทน
     */
    public function test_confirming_after_bangkok_midnight_keeps_the_original_day(): void
    {
        $owner = $this->user();

        // 23:50 ที่กรุงเทพ ของวันจันทร์ที่ 7
        $this->travelTo(CarbonImmutable::parse('2026-09-07 16:50:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'เฝ้าระบบข้ามคืน',
            'kind' => 'field',
        ])->assertRedirect();

        $log = WorkLog::query()->where('title', 'เฝ้าระบบข้ามคืน')->firstOrFail();
        $this->assertSame('2026-09-07', $log->work_date->format('Y-m-d'));

        // 00:10 ที่กรุงเทพ ของวันอังคารที่ 8
        $this->travelTo(CarbonImmutable::parse('2026-09-07 17:10:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.complete', $log))->assertRedirect();

        $log->refresh();

        $this->assertSame('done', $log->status);
        $this->assertSame('2026-09-07', $log->work_date->format('Y-m-d'), 'ต้องยังเป็นงานของวันที่บันทึก');
    }

    /**
     * ตัวจับเวลาที่ค้างมาจากรุ่นก่อนหน้าต้องถูกปิดด้วยขอบวันของกรุงเทพ
     *
     * ระบบจับเวลาถูกถอดออกไปแล้ว จึงไม่มีทางสร้างแถวแบบนี้ผ่านหน้าจอได้อีก
     * แถวในเทสต์นี้จึงถูกสร้างตรง ๆ เหมือนข้อมูลที่ค้างอยู่ในฐานข้อมูลจริง
     * ถ้าไม่ปิดให้ รายการนั้นจะค้างตลอดไปโดยไม่มีปุ่มไหนปิดมันได้อีก
     */
    public function test_leftover_timers_close_at_the_bangkok_day_boundary(): void
    {
        $owner = $this->user();

        // 22:00 ที่กรุงเทพ ของวันจันทร์ (= 15:00 UTC)
        $startedAt = CarbonImmutable::parse('2026-09-07 15:00:00', 'UTC');

        $log = WorkLog::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $owner->department_id,
            'kind' => 'routine',
            'status' => 'open',
            'source' => 'manual',
            'title' => 'ลืมกดจบงาน',
            'work_date' => '2026-09-07',
            'started_at' => $startedAt,
            'open_timer_owner_id' => $owner->id,
        ]);

        // 08:00 ที่กรุงเทพ ของวันอังคาร — ยังไม่ถึงเที่ยงคืน UTC ด้วยซ้ำ
        $this->travelTo(CarbonImmutable::parse('2026-09-08 01:00:00', 'UTC'));

        $closed = app(WorkLogService::class)->closeLeftoverTimers($owner->refresh());

        $log->refresh();

        $this->assertSame(1, $closed);
        $this->assertNull($log->open_timer_owner_id);
        $this->assertSame('done', $log->status);
        $this->assertNotNull($log->auto_closed_at);
        // เวลาสิ้นสุดถูกตรึงไว้ที่สิ้นวันทำการของวันที่เริ่ม ไม่ใช่ปล่อยให้เดินข้ามคืน
        $this->assertLessThanOrEqual(WorkLogDesign::MAX_TIMER_MINUTES, $log->duration_minutes);
        $this->assertSame('2026-09-07', TodayWorkspace::businessNow($log->ended_at)->format('Y-m-d'));
    }

    /**
     * เวลานาฬิกาที่ผู้ใช้กรอกเป็นเวลากรุงเทพ ต้องถูกแปลงเป็น UTC ก่อนเก็บ
     * ไม่ใช่เก็บตัวเลขที่พิมพ์มาตรง ๆ
     */
    public function test_typed_clock_times_are_stored_as_utc(): void
    {
        $owner = $this->user();

        $this->travelTo(CarbonImmutable::parse('2026-09-08 04:00:00', 'UTC'));

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ประชุมเช้า',
            'kind' => 'routine',
            'work_date' => '2026-09-08',
            'start_time' => '09:00',
            'end_time' => '10:20',
        ])->assertRedirect();

        $log = WorkLog::query()->where('title', 'ประชุมเช้า')->firstOrFail();

        // 09:00 ที่กรุงเทพ = 02:00 UTC
        $this->assertSame('2026-09-08 02:00:00', $log->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 03:20:00', $log->ended_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(80, $log->duration_minutes);
    }

    /**
     * เวลาที่แสดงกลับให้ผู้ใช้ต้องเป็นเวลากรุงเทพเสมอ ไม่ใช่ค่า UTC ที่เก็บไว้
     */
    public function test_displayed_times_are_converted_back_to_bangkok(): void
    {
        $owner = $this->user();

        $this->travelTo(CarbonImmutable::parse('2026-09-08 04:00:00', 'UTC'));

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.store'), [
                'title' => 'ประชุมเช้า',
                'kind' => 'routine',
                'work_date' => '2026-09-08',
                'start_time' => '09:00',
                'end_time' => '10:20',
            ])->assertOk();

        $this->assertSame('09:00', $response->json('log.started_time'));
        $this->assertSame('10:20', $response->json('log.ended_time'));
        $this->assertSame('09:00 - 10:20', $response->json('log.time_range_label'));
    }

    /**
     * "พรุ่งนี้" ต้องตัดสินด้วยวันของกรุงเทพ ไม่งั้นช่วง 17:00–23:59 UTC ผู้ใช้จะ
     * บันทึกงานของวันปัจจุบัน (ตามกรุงเทพ) ไม่ได้ เพราะระบบคิดว่าเป็นวันพรุ่งนี้
     */
    public function test_today_in_bangkok_is_accepted_even_when_utc_still_shows_yesterday(): void
    {
        $owner = $this->user();

        $this->travelTo(CarbonImmutable::parse(self::AFTER_BANGKOK_MIDNIGHT, 'UTC'));

        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'งานของวันนี้ตามเวลากรุงเทพ',
                'kind' => 'routine',
                'work_date' => '2026-09-08',
                'duration_minutes' => 15,
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        // ส่วนวันถัดไปจริง ๆ ต้องยังถูกปฏิเสธ
        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'งานล่วงหน้า',
                'kind' => 'routine',
                'work_date' => '2026-09-09',
            ])
            ->assertSessionHasErrors('work_date');
    }

    /**
     * อ่านวันที่ทำงานผ่านโมเดล ไม่ใช่เทียบสตริงดิบในฐานข้อมูล
     *
     * SQLite (ที่ใช้ทดสอบ) เก็บคอลัมน์ date เป็น "2026-09-08 00:00:00" ส่วน MySQL
     * (production) เก็บเป็น "2026-09-08" การเทียบผ่าน cast ของโมเดลจึงให้ผลเหมือนกัน
     * ทั้งสองฐานข้อมูล
     */
    private function workDateOf(string $title): string
    {
        return WorkLog::query()->where('title', $title)->firstOrFail()->work_date->format('Y-m-d');
    }

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => Department::create(['department_name' => 'IT'])->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
