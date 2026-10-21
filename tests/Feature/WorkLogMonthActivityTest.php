<?php

namespace Tests\Feature;

use App\Models\WorkLog;
use App\Support\WorkLogMonthActivity;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * กิจกรรมรายวันของกราฟในแท็บ "สรุปรายเดือน" — จัดกลุ่มตาม work_date จากข้อมูลจริงเท่านั้น
 */
class WorkLogMonthActivityTest extends TestCase
{
    public function test_groups_real_logs_by_work_date_and_returns_every_day_of_the_month(): void
    {
        $logs = collect([
            $this->log('2026-10-05', 60),
            $this->log('2026-10-05', 30),
            $this->log('2026-10-14', 240),
            $this->log('2026-10-20', null),
            $this->log('2026-09-30', 600),
        ]);

        $activity = WorkLogMonthActivity::build($logs, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-22 03:00:00', 'UTC'));
        $days = collect($activity['days'])->keyBy('date');

        $this->assertCount(31, $activity['days']);
        $this->assertSame(2, $days['2026-10-05']['count']);
        $this->assertSame(90, $days['2026-10-05']['minutes']);
        $this->assertSame(0.375, $days['2026-10-05']['ratio']);
        $this->assertSame(1.0, $days['2026-10-14']['ratio']);
        $this->assertSame(1, $days['2026-10-20']['count']);
        $this->assertSame(0, $days['2026-10-20']['minutes']);
        $this->assertSame(0, $days['2026-10-01']['count']);
        $this->assertFalse($days->has('2026-09-30'));

        $this->assertSame(3, $activity['active_days']);
        $this->assertSame(22, $activity['elapsed_days']);
        $this->assertTrue($days['2026-10-22']['is_today']);
        $this->assertTrue($days['2026-10-23']['is_future']);
        $this->assertSame(240, $activity['max_minutes']);
        $this->assertSame('14 ต.ค. 2569', $activity['peak']['label']);
        $this->assertSame('4 ชม.', $activity['peak']['duration_label']);
        // (90 + 240 + 0) / 3 วันที่มีบันทึก = 110 นาที
        $this->assertSame('1 ชม. 50 น.', $activity['average_label']);
    }

    public function test_month_without_timed_logs_has_no_peak_or_average(): void
    {
        $activity = WorkLogMonthActivity::build(
            collect([$this->log('2026-02-03', null)]),
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-03-10'),
        );

        $this->assertCount(28, $activity['days']);
        $this->assertSame(1, $activity['active_days']);
        $this->assertSame(0, $activity['max_minutes']);
        $this->assertNull($activity['peak']);
        $this->assertNull($activity['average_label']);
    }

    private function log(string $workDate, ?int $minutes): WorkLog
    {
        return (new WorkLog)->forceFill(['work_date' => $workDate, 'duration_minutes' => $minutes]);
    }
}
