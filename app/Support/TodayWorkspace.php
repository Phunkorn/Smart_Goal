<?php

namespace App\Support;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TodayWorkspace
{
    public const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    /**
     * เวลาตั้งต้นเมื่อผู้ใช้กรอกมาแต่วันที่ ไม่ได้เลือกเวลา
     *
     * งานเริ่มต้นวันทำการ และครบกำหนดตอนเลิกงาน ซึ่งตรงกับความหมายที่คนพูดกันว่า
     * "ส่งภายในวันนั้น" มากที่สุด ค่าพวกนี้เป็นค่าตั้งต้นเท่านั้น ไม่ใช่กติกาบังคับ
     * ผู้ใช้เลือกเวลาอื่นได้เสมอจากตัวเลือกวันที่-เวลาเดียวกัน
     */
    public const DEFAULT_START_TIME = '00:00';

    public const DEFAULT_DUE_TIME = '17:00';

    /**
     * วงจรสถานะล่าช้าอัตโนมัติต้องแตะเฉพาะงานที่ได้รับอนุมัติแล้ว
     * งานที่ยัง 'pending' (มอบหมายข้ามแผนก รอ Admin ตัดสินใจ) หรือถูก 'rejected'
     * ห้ามถูกดันเป็น "ล่าช้า" ก่อนที่ Admin จะอนุมัติ
     */
    private const AUTOMATED_APPROVAL_STATUS = 'approved';

    /** สถานะที่ระบบดันเป็น "ล่าช้า" เองได้เมื่อเลยกำหนดส่ง — กำลังทำ และ พักงาน */
    private const LATE_ELIGIBLE_STATUSES = [2, 5];

    /** สถานะ "เสร็จแล้ว/ปิดงาน" */
    private const DONE_STATUS = 4;

    public static function synchronizeLate(Builder $query): void
    {
        // งานที่ "พักงาน" ค้างไว้จนเลยกำหนดส่ง ต้องกลายเป็นล่าช้าเหมือนงานที่กำลังทำ
        // ไม่เช่นนั้นการพักงานจะกลายเป็นช่องทางหลบสถานะล่าช้าไปได้ไม่จำกัด
        //
        // เทียบกับ now() ตรง ๆ ได้เพราะทั้ง job_due_at และ now() เป็น UTC ทั้งคู่
        // การเทียบ "ขณะเวลา" ไม่ต้องแปลง timezone ก่อน — เวลาไทย 16:00 กับ UTC 09:00
        // คือขณะเดียวกัน กำหนดส่ง 17 มิ.ย. 16:00 จึงกลายเป็นล่าช้าตอน 16:00 ตามที่ผู้ใช้ตั้งไว้จริง
        (clone $query)->where('approval_status', self::AUTOMATED_APPROVAL_STATUS)
            ->whereIn('job_status', self::LATE_ELIGIBLE_STATUSES)
            ->whereNotNull('job_due_at')->where('job_due_at', '<', now())
            ->update(['job_status' => 6, 'late_at' => now()]);
    }

    public static function normalizeLateForTransition(WorkOrder $task): bool
    {
        if ($task->approval_status !== self::AUTOMATED_APPROVAL_STATUS
            || ! in_array((int) $task->job_status, self::LATE_ELIGIBLE_STATUSES, true)
            || ! $task->job_due_at
            || ! self::isLateBySchedule($task)) {
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
        return $task->job_due_at && $task->job_due_at->lt(now());
    }

    /**
     * "เลยกำหนดส่งแล้วและยังไม่ปิดงาน" — นิยามเดียวที่หน้าบอร์ดใช้ทำแถวให้เป็นสีแดง
     *
     * ในโค้ดเดิมนิยามนี้ถูกพิมพ์ซ้ำเป็นนิพจน์เดียวกันสองที่ คือแถวงานแม่ใน
     * project-board-card.blade.php และแถวงานย่อยใน task-detail-row.blade.php
     * พอจะเพิ่มป้ายเตือน "มีงานย่อยเลยกำหนด" บนหัวข้องาน จึงต้องมีแหล่งความจริงเดียว
     * มิฉะนั้นตัวเลขบนป้ายกับจำนวนแถวที่กางออกมาแล้วเป็นสีแดงจะไม่ตรงกัน
     *
     * ตั้งใจให้ต่างจากนิยามอื่นในระบบ ซึ่งตอบคนละคำถาม:
     *   WorkBoardDesign::statusKey()  ปัดไปสิ้นวัน และให้ "พักงาน" ชนะ "ล่าช้า"
     *   มุมมองตาราง/notion            ดูแค่ job_status === 6 ซึ่งเป็นสถานะที่เก็บไว้จริง
     */
    public static function isOverdue(WorkOrder $task): bool
    {
        return (int) $task->job_status !== self::DONE_STATUS && self::isLateBySchedule($task);
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

        // งานที่ถูกพักไว้แล้วค่อยกลายเป็นล่าช้า ต้องกลับไป "พักงาน" ตามเดิม ไม่ใช่ถูกปลุกมาทำงานเอง
        // paused_at ยังคงอยู่ตลอดช่วงที่งานถูกพัก จึงใช้เป็นหลักฐานว่าเดิมงานอยู่สถานะใด
        $task->update([
            'job_status' => $task->paused_at ? 5 : 2,
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

    /**
     * งานที่มุมมอง "ตาราง" ควรแสดงตามขอบเขตที่ผู้ใช้กรองไว้
     *
     * ตารางจัดคอลัมน์ตามสถานะ คอลัมน์ "เสร็จแล้ว" จึงเป็นช่องบอกว่า "วันนี้ปิดอะไรไปบ้าง"
     * ไม่ใช่คลังงานที่ปิดแล้วทั้งหมด ถ้าไม่ตัดตามวัน คอลัมน์นี้จะยาวขึ้นทุกวันจนกลบ
     * งานที่ยังต้องทำ และผู้ใช้ต้องเลื่อนผ่านงานเก่าทุกครั้งที่เปิดหน้า
     *
     * งานที่ปิดไปแล้วไม่ได้หายไปไหน มันย้ายไปอยู่กลุ่ม "งานที่เสร็จแล้ว" ของมุมมองบอร์ด
     * ซึ่งจัดกลุ่มตามโปรเจกต์และเก็บประวัติทั้งหมดไว้
     *
     * งานที่ยังไม่ปิดจะแสดงครบทุกใบเสมอ ไม่ว่าจะยังไม่ถึงวันเริ่มหรือเลยกำหนดไปแล้ว
     * เพราะตัวนับของตัวกรองนับงานเหล่านั้นอยู่ ถ้าตารางซ่อนไว้ตัวเลขกับสิ่งที่เห็นจะไม่ตรงกัน
     *
     * @param  Collection<int, WorkOrder>  $tasks
     * @return Collection<int, WorkOrder>
     */
    public static function workspaceTable(Collection $tasks): Collection
    {
        $today = self::businessToday();

        return $tasks->filter(fn (WorkOrder $task): bool => (int) $task->job_status !== self::DONE_STATUS
            || ($task->job_completed_at && self::businessDate($task->job_completed_at)->isSameDay($today)))
            ->values();
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
     * 'Y-m-d\TH:i' ตามเวลาไทย สำหรับใส่เป็น value ของ <input type="datetime-local">
     *
     * ช่อง datetime-local ของเบราว์เซอร์อ่านค่าเป็นเวลาท้องถิ่นล้วน ไม่มีส่วนบอก timezone
     * ถ้า format จากค่า UTC ตรง ๆ ผู้ใช้จะเห็นเวลาเพี้ยนไป 7 ชั่วโมงทุกครั้งที่เปิดฟอร์ม
     */
    public static function calendarDateTime(?CarbonInterface $date): string
    {
        return $date ? self::businessMoment($date)->format('Y-m-d\TH:i') : '';
    }

    /** 'H:i' ตามเวลาไทย สำหรับ data attribute ที่ฝั่ง client ใช้แสดงเวลากำหนดส่ง */
    public static function clockTime(?CarbonInterface $date): string
    {
        return $date ? self::businessMoment($date)->format('H:i') : '';
    }

    /** ป้ายเวลาแบบที่ UI ไทยใช้ เช่น "16:00 น." คืน null เมื่อไม่มีค่า */
    public static function timeLabel(?CarbonInterface $date): ?string
    {
        return $date ? self::businessMoment($date)->format('H:i').' น.' : null;
    }

    /**
     * แปลงค่าที่ผู้ใช้กรอกให้เป็นเวลา UTC สำหรับเก็บลงคอลัมน์
     *
     * ช่องในหน้าจอส่งมาเป็นเวลาไทยเสมอ ('Y-m-d\TH:i' จาก datetime-local หรือ 'Y-m-d'
     * ล้วนจากช่องเก่า/การเรียกผ่าน API) แต่ config('app.timezone') คือ UTC การ
     * Carbon::parse() ตรง ๆ จึงตีความ "16:00" เป็น 16:00 UTC ซึ่งคือ 23:00 เวลาไทย
     * ทุกจุดที่รับวันเวลาจากผู้ใช้ต้องผ่านเมธอดนี้ ห้าม parse เอง
     *
     * ค่าที่ไม่มีส่วนเวลามาด้วยจะได้เวลาตั้งต้นตาม $fallbackTime ซึ่งเป็นเหตุผลที่
     * วันเริ่มกับกำหนดส่งต้องส่งค่าต่างกัน (ต้นวันทำการ กับ เวลาเลิกงาน)
     */
    public static function parseBusinessInput(mixed $value, string $fallbackTime): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc();
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        // 'Y-m-d' ล้วน — ไม่มีเวลามาด้วย จึงเติมเวลาตั้งต้นให้ก่อนตีความเป็นเวลาไทย
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $text .= ' '.$fallbackTime;
        }

        return Carbon::parse(str_replace('T', ' ', $text), self::BUSINESS_TIMEZONE)->utc();
    }

    /**
     * เวลาตามโซนธุรกิจโดย "คงเวลานาฬิกาไว้"
     *
     * ต่างจาก businessDate() ที่ปัดลงเป็นต้นวันเสมอ เพราะตั้งแต่ระบบมีเวลากำหนดส่ง
     * มีจุดที่ต้องการทั้งวันและเวลา (ป้ายเวลา ช่องกรอก) ไม่ใช่แค่วัน
     */
    public static function businessMoment(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(self::BUSINESS_TIMEZONE);
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

    /**
     * เวลาปัจจุบันตามเวลาทำการ (Asia/Bangkok)
     *
     * เปิดเป็น public เพราะฟีเจอร์ที่ทำงานระดับ "เวลานาฬิกา" (เช่น บันทึกงาน
     * ประจำวัน) ต้องถามเวลาทางธุรกิจเหมือนกัน และต้องถามจากที่นี่ที่เดียว
     * ห้ามเรียก now()->setTimezone('Asia/Bangkok') เองในคลาสอื่น เพราะค่า
     * timezone ถูกคัดลอกกระจายไปหลายที่แล้วและเริ่มเพี้ยนออกจากกัน
     */
    public static function businessNow(?CarbonInterface $date = null): CarbonInterface
    {
        return ($date ?? now())->copy()->setTimezone(self::BUSINESS_TIMEZONE);
    }

    /**
     * ขอบเขตของหนึ่งวันทำการ คืนเป็นเวลา UTC เพื่อนำไปใช้กับคอลัมน์ที่เก็บ UTC
     *
     * ใช้เมื่อต้องหาว่ารายการใด "อยู่ในวันนั้น" โดยไม่ต้องแปลง timezone ทีละแถว
     * ใน SQL ซึ่งพฤติกรรมต่างกันระหว่าง SQLite (ทดสอบ) กับ MySQL (production)
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface} [เริ่มวัน, สิ้นสุดวัน] ตามเวลา UTC
     */
    public static function businessDayBounds(string|CarbonInterface|null $date = null): array
    {
        $day = $date instanceof CarbonInterface
            ? self::businessDate($date)
            : self::businessNow(
                is_string($date) && $date !== ''
                    ? Carbon::createFromFormat('Y-m-d', $date, self::BUSINESS_TIMEZONE)->startOfDay()
                    : null
            )->startOfDay();

        return [
            $day->copy()->utc(),
            $day->copy()->endOfDay()->utc(),
        ];
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
