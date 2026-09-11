<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * งานที่ถูกสร้างตอนหน้าจอยังมีแต่ช่องวันที่ถูกเก็บเป็นเที่ยงคืน UTC (= 07:00 น. เวลาไทย)
 *
 * ตอนนั้นไม่มีปัญหาเพราะระบบปัดกำหนดส่งเป็นสิ้นวันก่อนเทียบเสมอ เวลาที่เก็บไว้จึงไม่เคยถูกใช้
 * พอระบบเริ่มตัดสินความล่าช้าด้วยเวลาจริง งานเก่าทุกใบจะกลายเป็นล่าช้าตั้งแต่เช้าของวันครบกำหนด
 * migration จึงต้องย้ายค่าเดิมให้ตรงกับพฤติกรรมที่ผู้ใช้เคยเห็นมาตลอด และต้องไม่แตะงานที่มีเวลาจริงแล้ว
 */
class LegacyScheduleTimeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_midnight_utc_rows_move_to_the_business_day_they_always_meant(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $legacy = $this->job($user, '2026-06-16 00:00:00', '2026-06-17 00:00:00');

        $this->runMigration();
        $legacy->refresh();

        // วันที่ผู้ใช้เคยเห็นต้องไม่เปลี่ยน เปลี่ยนแต่เวลาในวันนั้น
        $this->assertSame('2026-06-16', TodayWorkspace::calendarDate($legacy->job_start_at));
        $this->assertSame('2026-06-17', TodayWorkspace::calendarDate($legacy->job_due_at));
        $this->assertSame('00:00', TodayWorkspace::clockTime($legacy->job_start_at));
        $this->assertSame('23:59', TodayWorkspace::clockTime($legacy->job_due_at));

        // และงานต้องยังไม่ล่าช้าตลอดทั้งวันครบกำหนดเหมือนที่เคยเป็น
        $this->travelTo(Carbon::parse('2026-06-17 16:00:00', 'UTC')); // 23:00 น. เวลาไทย
        $this->assertFalse(TodayWorkspace::isLateBySchedule($legacy->fresh()));

        $this->travelTo(Carbon::parse('2026-06-17 17:00:00', 'UTC')); // เที่ยงคืนเวลาไทย
        $this->assertTrue(TodayWorkspace::isLateBySchedule($legacy->fresh()));
    }

    public function test_rows_that_already_carry_a_real_time_are_left_alone(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        // 09:00 UTC = 16:00 น. เวลาไทย ซึ่งเป็นเวลาที่ผู้ใช้ตั้งเองจากหน้ามอบหมายของ Admin
        $explicit = $this->job($user, '2026-06-16 02:00:00', '2026-06-17 09:00:00');
        $before = $explicit->job_due_at->toDateTimeString();

        $this->runMigration();

        $this->assertSame($before, $explicit->fresh()->job_due_at->toDateTimeString());
        $this->assertSame('16:00', TodayWorkspace::clockTime($explicit->fresh()->job_due_at));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_10_000001_convert_legacy_midnight_schedules_to_business_time.php');
        $migration->up();
    }

    private function job(User $user, string $start, string $due): WorkOrder
    {
        $job = WorkOrder::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'leader_user_id' => $user->id,
            'job_topic' => 'Legacy task '.uniqid(),
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => $start,
            'job_due_at' => $due,
        ]);

        // เขียนตรงเพื่อเลี่ยง cast ของโมเดล ข้อมูลเก่าถูกเก็บด้วยค่าดิบแบบนี้จริง ๆ
        DB::table('work_orders')->where('job_id', $job->job_id)
            ->update(['job_start_at' => $start, 'job_due_at' => $due]);

        return $job->fresh();
    }
}
