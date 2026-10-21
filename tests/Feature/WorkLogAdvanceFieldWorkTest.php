<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\WorkLogDesign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * งานนอกสถานที่ลงวันล่วงหน้าได้
 *
 * เดิมช่องวันที่ (logEntryDate) มี max = วันนี้ และ WorkLogService ปฏิเสธทุกวันที่เลยวันนี้
 * งานนอกสถานที่ซึ่งรู้นัดก่อนวันจริงจึงลงไว้ก่อนไม่ได้ ตอนนี้ลงล่วงหน้าได้เฉพาะงานนอกสถานที่
 * และเวลาที่กรอกเป็น "เวลาที่วางไว้" รายการรอเริ่มจนถึงวันนั้นแล้วเจ้าของกดยืนยัน
 */
class WorkLogAdvanceFieldWorkTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-08 03:00 UTC = อังคาร 8 ก.ย. 10:00 ที่กรุงเทพ */
    private const NOW = '2026-09-08 03:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    public function test_field_work_can_be_logged_for_a_future_day_as_planned_and_open(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->postJson(route('daily-logs.store'), [
                'title' => 'ติดตั้งเครื่องพิมพ์สาขาบางนา',
                'kind' => 'field',
                'location' => 'สาขาบางนา',
                'work_date' => '2026-09-15',
                'start_time' => '09:00',
                'end_time' => '11:30',
            ])
            ->assertOk()
            ->assertJsonPath('log.work_date', '2026-09-15')
            ->assertJsonPath('log.status', 'open')
            ->assertJsonPath('log.is_future_day', true)
            ->assertJsonPath('log.planned_start_time', '09:00')
            ->assertJsonPath('log.planned_end_time', '11:30');

        $log = WorkLog::query()->firstOrFail();

        $this->assertSame('2026-09-15', $log->work_date->format('Y-m-d'));
        $this->assertSame('open', $log->status);
        // ยังไม่ได้ทำจริง จึงไม่มีเวลาจริงและไม่นับนาทีเข้าสรุปของวันไหน
        $this->assertNull($log->started_at);
        $this->assertNull($log->ended_at);
        $this->assertNull($log->duration_minutes);
        // 09:00 กรุงเทพ = 02:00 UTC
        $this->assertSame('2026-09-15 02:00:00', $log->planned_start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 04:30:00', $log->planned_end_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_multiple_future_days_can_be_picked_for_field_work(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)
            ->postJson(route('daily-logs.store'), [
                'title' => 'ออกตรวจสาขา',
                'kind' => 'field',
                'location' => 'สาขาบางนา',
                'work_dates' => ['2026-09-08', '2026-09-10'],
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 2);

        $this->assertEqualsCanonicalizing(
            ['2026-09-08', '2026-09-10'],
            WorkLog::query()->get()->map(fn (WorkLog $log) => $log->work_date->format('Y-m-d'))->all()
        );
    }

    public function test_routine_work_still_cannot_be_logged_ahead(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('daily-logs.store'), [
                'title' => 'งานประจำล่วงหน้า',
                'kind' => 'routine',
                'work_date' => '2026-09-09',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_date');

        $this->assertSame(0, WorkLog::query()->count());
    }

    public function test_field_work_cannot_go_further_than_the_advance_limit(): void
    {
        $owner = $this->user();
        $limit = CarbonImmutable::parse('2026-09-08')->addDays(WorkLogDesign::MAX_ADVANCE_DAYS);

        $this->actingAs($owner)
            ->postJson(route('daily-logs.store'), [
                'title' => 'ไกลเกินไป',
                'kind' => 'field',
                'location' => 'เชียงใหม่',
                'work_date' => $limit->addDay()->format('Y-m-d'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_date');

        $this->actingAs($owner)
            ->postJson(route('daily-logs.store'), [
                'title' => 'วันสุดท้ายที่ลงได้',
                'kind' => 'field',
                'location' => 'เชียงใหม่',
                'work_date' => $limit->format('Y-m-d'),
            ])
            ->assertOk();
    }

    public function test_duration_only_is_rejected_for_a_future_day(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('daily-logs.store'), [
                'title' => 'ระบุแค่นาที',
                'kind' => 'field',
                'location' => 'สาขาบางนา',
                'work_date' => '2026-09-15',
                'duration_minutes' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration_minutes');
    }

    public function test_future_field_work_is_confirmed_only_once_the_day_arrives(): void
    {
        $owner = $this->user();
        $log = $this->futureFieldLog($owner);

        $this->actingAs($owner)
            ->postJson(route('daily-logs.complete', $log))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_date');

        $this->assertSame('open', $log->fresh()->status);

        // 15 ก.ย. 12:00 ที่กรุงเทพ — ถึงวันทำงานแล้ว ยืนยันได้และได้เวลาตามที่วางไว้
        $this->travelTo(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'));

        $this->actingAs($owner)
            ->postJson(route('daily-logs.complete', $log))
            ->assertOk()
            ->assertJsonPath('log.status', 'done');

        $log->refresh();
        $this->assertSame('2026-09-15 02:00:00', $log->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 04:30:00', $log->ended_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(150, $log->duration_minutes);
    }

    public function test_editing_keeps_the_plan_and_moving_to_today_records_actual_times(): void
    {
        $owner = $this->user();
        $log = $this->futureFieldLog($owner);

        $payload = [
            '_method' => 'PATCH',
            'title' => 'ติดตั้งเครื่องพิมพ์สาขาบางนา',
            'kind' => 'field',
            'location' => 'สาขาบางนา',
            'work_date' => '2026-09-16',
            'start_time' => '13:00',
            'end_time' => '14:00',
        ];

        $this->actingAs($owner)->postJson(route('daily-logs.update', $log), $payload)->assertOk();

        $log->refresh();
        $this->assertSame('open', $log->status);
        $this->assertNull($log->started_at);
        $this->assertSame('2026-09-16 06:00:00', $log->planned_start_at->utc()->format('Y-m-d H:i:s'));

        // ทำจริงวันนี้ — กลายเป็นบันทึกปกติที่มีเวลาจริง และไม่เหลือเวลาที่วางไว้ค้าง
        $this->actingAs($owner)
            ->postJson(route('daily-logs.update', $log), [...$payload, 'work_date' => '2026-09-08', 'start_time' => '08:00', 'end_time' => '09:00'])
            ->assertOk();

        $log->refresh();
        $this->assertSame('done', $log->status);
        $this->assertSame(60, $log->duration_minutes);
        $this->assertNull($log->planned_start_at);
        $this->assertNull($log->planned_end_at);
    }

    public function test_a_participant_copy_of_future_field_work_gets_the_planned_times(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);

        $this->actingAs($owner)
            ->postJson(route('daily-logs.store'), [
                'title' => 'ติดตั้งเครื่องพิมพ์สาขาบางนา',
                'kind' => 'field',
                'location' => 'สาขาบางนา',
                'work_date' => '2026-09-15',
                'start_time' => '09:00',
                'end_time' => '11:30',
                'participants' => [$colleague->id],
            ])
            ->assertOk();

        $copy = WorkLog::query()->where('user_id', $colleague->id)->firstOrFail();

        $this->assertSame('2026-09-15', $copy->work_date->format('Y-m-d'));
        $this->assertSame('open', $copy->status);
        $this->assertSame('2026-09-15 02:00:00', $copy->planned_start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 04:30:00', $copy->planned_end_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_entry_form_date_limit_follows_the_kind(): void
    {
        $limits = WorkLogDesign::latestWorkDates();

        $this->assertSame('2026-09-08', $limits['routine']);
        $this->assertSame(
            CarbonImmutable::parse('2026-09-08')->addDays(WorkLogDesign::MAX_ADVANCE_DAYS)->format('Y-m-d'),
            $limits['field']
        );

        $this->actingAs($this->user())
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('id="logEntryDate"', false)
            ->assertSee('max="2026-09-08"', false)
            ->assertSee('data-entry-date-max-by-kind="'.e(json_encode($limits)).'"', false);
    }

    private function futureFieldLog(User $owner): WorkLog
    {
        $this->actingAs($owner)
            ->postJson(route('daily-logs.store'), [
                'title' => 'ติดตั้งเครื่องพิมพ์สาขาบางนา',
                'kind' => 'field',
                'location' => 'สาขาบางนา',
                'work_date' => '2026-09-15',
                'start_time' => '09:00',
                'end_time' => '11:30',
            ])
            ->assertOk();

        return WorkLog::query()->latest('id')->firstOrFail();
    }

    private function user(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => ($department ?? Department::create(['department_name' => 'IT']))->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
