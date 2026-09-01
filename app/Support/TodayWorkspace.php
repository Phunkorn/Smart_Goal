<?php

namespace App\Support;

use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TodayWorkspace
{
    public const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    /**
     * วงจรสถานะล่าช้าอัตโนมัติต้องแตะเฉพาะงานที่ได้รับอนุมัติแล้ว
     * งานที่ยัง 'pending' (มอบหมายข้ามแผนก รอ Admin ตัดสินใจ) หรือถูก 'rejected'
     * ห้ามถูกดันเป็น "ล่าช้า" ก่อนที่ Admin จะอนุมัติ
     */
    private const AUTOMATED_APPROVAL_STATUS = 'approved';

    public static function synchronizeLate(Builder $query): void
    {
        $todayStartUtc = self::businessToday()->utc();

        (clone $query)->where('approval_status', self::AUTOMATED_APPROVAL_STATUS)
            ->where('job_status', 2)
            ->whereNotNull('job_due_at')->where('job_due_at', '<', $todayStartUtc)
            ->update(['job_status' => 6, 'late_at' => now()]);
    }

    public static function normalizeLateForTransition(WorkOrder $task): bool
    {
        if ($task->approval_status !== self::AUTOMATED_APPROVAL_STATUS
            || (int) $task->job_status !== 2
            || ! $task->job_due_at
            || ! self::businessDate($task->job_due_at)->endOfDay()->lt(self::businessNow())) {
            return (int) $task->job_status === 6;
        }

        $task->update([
            'job_status' => 6,
            'late_at' => $task->late_at ?? now(),
        ]);

        return true;
    }

    public static function isLateBySchedule(WorkOrder $task): bool
    {
        return $task->job_due_at
            && self::businessDate($task->job_due_at)->endOfDay()->lt(self::businessNow());
    }

    /**
     * Status 6 is derived from the schedule. If an authorized schedule edit
     * makes the task no longer overdue, restore the appropriate active state.
     */
    public static function reconcileLateAfterScheduleChange(WorkOrder $task): bool
    {
        if ($task->approval_status !== self::AUTOMATED_APPROVAL_STATUS
            || (int) $task->job_status !== 6
            || self::isLateBySchedule($task)) {
            return false;
        }

        $task->update([
            'job_status' => 2,
            'late_at' => null,
        ]);

        return true;
    }

    public static function tasks(Collection $tasks): Collection
    {
        $today = self::businessToday();

        return $tasks->filter(fn (WorkOrder $task): bool => match ((int) $task->job_status) {
            4 => $task->job_completed_at ? self::businessDate($task->job_completed_at)->isSameDay($today) : false,
            5, 6 => true,
            2, 3 => self::isWithinActiveRange($task, $today),
            default => false,
        })->values();
    }

    public static function timeProgress(WorkOrder $task, ?CarbonInterface $date = null): ?array
    {
        if (! $task->job_start_at || ! $task->job_due_at) {
            return null;
        }

        $today = self::businessToday($date);
        $start = self::businessDate($task->job_start_at);
        $due = self::businessDate($task->job_due_at);

        if ($due->lt($start) || $today->lt($start) || $today->gt($due)) {
            return null;
        }

        $totalDays = (int) $start->diffInDays($due) + 1;
        $currentDay = (int) $start->diffInDays($today) + 1;
        $remainingDays = (int) $today->diffInDays($due);

        return [
            'range_label' => self::thaiDateRange($start, $due),
            'total_days' => $totalDays,
            'current_day' => $currentDay,
            'remaining_days' => $remainingDays,
            'is_single_day' => $totalDays === 1,
            'is_due_today' => $remainingDays === 0,
            'progress_label' => $totalDays === 1
                ? 'ครบกำหนดวันนี้'
                : sprintf(
                    'วันที่ %d/%d • %s',
                    $currentDay,
                    $totalDays,
                    $remainingDays === 0 ? 'ครบกำหนดวันนี้' : 'เหลือ '.$remainingDays.' วัน'
                ),
        ];
    }

    public static function overdueDays(WorkOrder $task, ?CarbonInterface $date = null): int
    {
        if (! $task->job_due_at) {
            return 0;
        }

        $today = self::businessToday($date);
        $due = self::businessDate($task->job_due_at);

        return $today->gt($due) ? (int) $due->diffInDays($today) : 0;
    }

    private static function isWithinActiveRange(WorkOrder $task, CarbonInterface $today): bool
    {
        if (! $task->job_start_at || ! $task->job_due_at) {
            return false;
        }

        $start = self::businessDate($task->job_start_at);
        $due = self::businessDate($task->job_due_at);

        return $start->lte($due) && $today->betweenIncluded($start, $due);
    }

    /**
     * วันที่แบบ Y-m-d สำหรับส่งให้ปฏิทินและบอร์ดฝั่ง client
     *
     * config('app.timezone') คือ UTC ถ้า format ตรง ๆ งานที่ครบกำหนดหลังเที่ยงคืนเวลาไทย
     * จะถูกวางผิดไป 1 วัน จุดที่ผลิตวันที่ให้ frontend จึงต้องผ่านเมธอดนี้ทุกจุด
     * แปลงเฉพาะตอนแสดงผล ไม่แตะค่าที่เก็บใน Database
     */
    public static function calendarDate(?CarbonInterface $date): string
    {
        return $date ? self::businessDate($date)->format('Y-m-d') : '';
    }

    /**
     * ป้ายช่วงวันที่แบบไทย (พ.ศ.) ไม่ผูกกับ "วันนี้" เหมือน timeProgress()
     *
     * timeProgress() คืน null เมื่อวันเริ่มงานยังมาไม่ถึงหรือพ้นกำหนดไปแล้ว เพราะมันคำนวณความคืบหน้า
     * ของช่วงที่ "กำลังดำเนินอยู่" เท่านั้น แต่บางหน้า (เช่น Calendar Quick View) ต้องแสดงช่วงวันที่
     * เสมอไม่ว่าสถานะงานจะเป็นอะไร จึงแยกป้ายช่วงวันที่ล้วน ๆ ออกมาเป็นเมธอดสาธารณะของตัวเอง
     */
    public static function dateRangeLabel(?CarbonInterface $start, ?CarbonInterface $due): ?string
    {
        if (! $start || ! $due) {
            return null;
        }

        return self::thaiDateRange(self::businessDate($start), self::businessDate($due));
    }

    private static function businessNow(?CarbonInterface $date = null): CarbonInterface
    {
        return ($date ?? now())->copy()->setTimezone(self::BUSINESS_TIMEZONE);
    }

    private static function businessToday(?CarbonInterface $date = null): CarbonInterface
    {
        return self::businessNow($date)->startOfDay();
    }

    private static function businessDate(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(self::BUSINESS_TIMEZONE)->startOfDay();
    }

    private static function thaiDateRange(CarbonInterface $start, CarbonInterface $due): string
    {
        $months = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
        $startYear = $start->year + 543;
        $dueYear = $due->year + 543;

        if ($start->isSameDay($due)) {
            return sprintf('%d %s %d', $start->day, $months[$start->month], $startYear);
        }

        if ($start->year === $due->year && $start->month === $due->month) {
            return sprintf('%d–%d %s %d', $start->day, $due->day, $months[$due->month], $dueYear);
        }

        if ($start->year === $due->year) {
            return sprintf('%d %s–%d %s %d', $start->day, $months[$start->month], $due->day, $months[$due->month], $dueYear);
        }

        return sprintf('%d %s %d–%d %s %d', $start->day, $months[$start->month], $startYear, $due->day, $months[$due->month], $dueYear);
    }
}
