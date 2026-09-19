<?php

namespace App\Support;

use App\Models\WorkLog;
use Illuminate\Support\Collection;

/**
 * งานประจำที่ทำร่วมกัน — แหล่งเดียวของกติกา "รายการไหนคืองานชิ้นเดียวกัน"
 *
 * งานประจำที่มีผู้ร่วมงานเก็บเป็นแถวของใครของมัน (หนึ่งแถวต่อคนต่อวัน) แต่คนที่มาทำด้วยกัน
 * เริ่มและจบพร้อมกันเสมอ (RoutineAccountabilityService) ปฏิทินแผนกและรายงานระดับแผนก
 * จึงต้องเห็นเป็นงานชิ้นเดียว ไม่ใช่สองรายการซ้ำกัน
 *
 * รวมเฉพาะสถานะที่ "ทำอยู่ด้วยกัน" ได้จริง สถานะที่แต่ละคนต้องตอบเหตุผลเอง
 * (ไม่มา / ไม่ได้เริ่ม / เริ่มแล้วไม่กดเสร็จ / ไม่ได้ทำ) แยกแถวเสมอ เพราะเป็นเรื่องของคนนั้นคนเดียว
 * "พบปัญหา" นับเป็น "เสร็จแล้ว" เพราะรายละเอียดปัญหาอยู่ที่แถวของคนกดเสร็จเท่านั้น
 */
final class JointRoutineWork
{
    /** planned / waiting คือแผนบนปฏิทินที่ยังไม่มีรายการจริง (WorkLogQueryService::calendarFor) */
    public const SHARED_STATUSES = ['planned', 'waiting', 'open', 'overdue', 'in_progress', 'done'];

    /**
     * สถานะที่ต่างกันแค่ชื่อแต่เป็นช่วงเดียวกันของงานชิ้นเดียว
     *
     * - พบปัญหา = เสร็จแล้ว (รายละเอียดปัญหาอยู่ที่แถวของคนกดเสร็จ)
     * - ยังไม่มีใครเริ่ม: คนที่เปิดระบบแล้วเป็น "รอเริ่ม" (หรือ "เกินเวลา" เมื่อเลยเวลาสิ้นสุด)
     *   ส่วนคนที่ยังไม่เปิดระบบยังเป็นแผนจากแม่แบบ "ยังไม่เริ่ม" — ทั้งหมดคืองานเดียวกันที่รอเริ่ม
     */
    private const SAME_STAGE = [
        'issue' => 'done',
        'waiting' => 'pending',
        'open' => 'pending',
        'overdue' => 'pending',
    ];

    /**
     * กุญแจของกลุ่มงานร่วม หรือ null เมื่อรายการนี้ต้องแสดงแยกของใครของมัน
     */
    public static function key(?int $templateId, ?string $date, string $status): ?string
    {
        if ($templateId === null || $date === null) {
            return null;
        }

        if ($status !== 'issue' && ! in_array($status, self::SHARED_STATUSES, true)) {
            return null;
        }

        return $templateId.'|'.$date.'|'.(self::SAME_STAGE[$status] ?? $status);
    }

    public static function keyOf(WorkLog $log): ?string
    {
        return self::key(
            $log->work_log_template_id === null ? null : (int) $log->work_log_template_id,
            $log->work_date?->format('Y-m-d'),
            WorkLogPresenter::displayStatus($log)
        );
    }

    /**
     * ยุบงานร่วมให้เหลือรายการเดียวต่อกลุ่ม — ใช้กับตัวเลขระดับแผนก ไม่ใช่ยอดรายคน
     *
     * ตัวแทนของกลุ่มคือแถวของคนที่กดเริ่ม/เสร็จ เพราะเหตุผลและรายละเอียดปัญหาอยู่ที่แถวนั้นแถวเดียว
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return Collection<int, WorkLog>
     */
    public static function collapse(Collection $logs): Collection
    {
        $groups = [];
        $order = [];

        foreach ($logs->values() as $index => $log) {
            $key = self::keyOf($log) ?? 'single:'.$index;

            if (! isset($groups[$key])) {
                $order[] = $key;
            }

            $groups[$key][] = $log;
        }

        return collect($order)
            ->map(fn (string $key): WorkLog => collect($groups[$key])
                ->sortByDesc(fn (WorkLog $log): int => self::accountabilityWeight($log))
                ->first())
            ->values();
    }

    /** แถวที่มีคำอธิบายของคนกด (ปัญหา / เหตุผลช้า) มาก่อนแถวที่ถูกปิดตามไปด้วย */
    private static function accountabilityWeight(WorkLog $log): int
    {
        return ($log->has_issue ? 4 : 0)
            + (filled($log->late_completion_reason) ? 2 : 0)
            + (filled($log->late_start_reason) ? 1 : 0);
    }
}
