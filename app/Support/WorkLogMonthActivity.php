<?php

namespace App\Support;

use App\Models\WorkLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * กิจกรรมรายวันของหนึ่งเดือน สำหรับกราฟแท่งในแท็บ "สรุปรายเดือน"
 *
 * เป็น pure fold เหมือน WorkLogSummary คือไม่ query เอง รับ collection ที่
 * WorkLogQueryService::monthFor() ดึงมาแล้ว แล้วจัดกลุ่มตาม work_date (วันทำการ)
 * ตัวเลขนาทีใช้กติกาเดียวกับ WorkLogSummary — นับเฉพาะรายการที่ระบุเวลา
 *
 * คืนครบทุกวันของเดือน แม้วันนั้นไม่มีบันทึก เพื่อให้กราฟเห็นช่องว่างจริง
 */
final class WorkLogMonthActivity
{
    /**
     * @param  Collection<int, WorkLog>  $logs
     * @return array{
     *     days: list<array{date: string, day: int, label: string, weekday: string, is_weekend: bool, is_today: bool, is_future: bool, count: int, minutes: int, duration_label: string, ratio: float}>,
     *     active_days: int,
     *     elapsed_days: int,
     *     max_minutes: int,
     *     peak: array{label: string, duration_label: string, count: int}|null,
     *     average_label: string|null
     * }
     */
    public static function build(Collection $logs, CarbonInterface $month, ?CarbonInterface $now = null): array
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'), TodayWorkspace::BUSINESS_TIMEZONE);
        $today = TodayWorkspace::businessNow($now)->format('Y-m-d');

        $grouped = $logs->groupBy(fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '');

        $days = [];
        for ($date = $start; $date->month === $start->month; $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $dayLogs = $grouped->get($key, collect());
            $minutes = (int) $dayLogs->sum(fn (WorkLog $log): int => (int) ($log->duration_minutes ?? 0));

            $days[] = [
                'date' => $key,
                'day' => $date->day,
                'label' => ThaiDate::day($date),
                'weekday' => $date->locale('th')->translatedFormat('D'),
                'is_weekend' => $date->isWeekend(),
                'is_today' => $key === $today,
                'is_future' => $key > $today,
                'count' => $dayLogs->count(),
                'minutes' => $minutes,
                'duration_label' => WorkLogDesign::durationLabel($minutes),
                'ratio' => 0.0,
            ];
        }

        $maxMinutes = max(0, ...array_column($days, 'minutes'));
        foreach ($days as $index => $day) {
            $days[$index]['ratio'] = $maxMinutes > 0 ? round($day['minutes'] / $maxMinutes, 4) : 0.0;
        }

        $active = array_values(array_filter($days, fn (array $day): bool => $day['count'] > 0));
        $totalMinutes = array_sum(array_column($active, 'minutes'));

        $peak = null;
        if ($maxMinutes > 0) {
            foreach ($days as $day) {
                if ($day['minutes'] === $maxMinutes) {
                    $peak = ['label' => $day['label'], 'duration_label' => $day['duration_label'], 'count' => $day['count']];
                    break;
                }
            }
        }

        return [
            'days' => $days,
            'active_days' => count($active),
            'elapsed_days' => count(array_filter($days, fn (array $day): bool => ! $day['is_future'])),
            'max_minutes' => $maxMinutes,
            'peak' => $peak,
            'average_label' => $active !== [] && $totalMinutes > 0
                ? WorkLogDesign::durationLabel(intdiv($totalMinutes, count($active)))
                : null,
        ];
    }
}
