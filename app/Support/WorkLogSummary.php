<?php

namespace App\Support;

use App\Models\WorkLog;
use Illuminate\Support\Collection;

/**
 * สรุปตัวเลขของบันทึกงานชุดหนึ่ง (ปกติคือ "งานของหนึ่งคนในหนึ่งวัน")
 *
 * ตั้งใจให้เป็น pure fold ที่ไม่ query ฐานข้อมูลเอง ผู้เรียกเป็นคนส่ง collection
 * เข้ามา เหตุผลคือหน้าสรุปรายวันกับหน้ารายงานภาระงานต้องได้ตัวเลขจากสูตรเดียวกัน
 * ถ้าปล่อยให้แต่ละที่ query และรวมเลขเอง สองหน้าจะเพี้ยนออกจากกันทันทีที่กติกาเปลี่ยน
 *
 * ตัวเลขที่สำคัญที่สุดคือ unlinked_minutes — เวลาที่ไม่ได้ผูกกับโครงการใด
 * เพราะมันคือคำตอบของคำถาม "ทำไมโครงการไม่ขยับเลยวันนี้"
 */
final class WorkLogSummary
{
    /**
     * @param  Collection<int, WorkLog>  $logs
     */
    public static function fromLogs(Collection $logs): array
    {
        $counted = $logs->values();

        return [
            'total_minutes' => self::minutesOf($counted),
            'total_count' => $counted->count(),
            'by_kind' => self::byKind($counted),
            'by_category' => self::byCategory($counted),
            'by_status' => self::byStatus($counted),
            'open_count' => $counted->whereIn('status', ['open', 'in_progress'])->count(),
            'in_progress_count' => $counted->where('status', 'in_progress')->count(),
            'done_count' => $counted->where('status', 'done')->count(),
            'skipped_count' => $counted->where('status', 'skipped')->count(),
            'auto_closed_count' => $counted->filter(fn (WorkLog $log): bool => $log->auto_closed_at !== null)->count(),
            'untimed_count' => $counted->filter(fn (WorkLog $log): bool => $log->duration_minutes === null)->count(),
            'unlinked_minutes' => self::minutesOf(
                $counted->filter(fn (WorkLog $log): bool => $log->work_order_list_id === null && $log->job_id === null)
            ),
            'project_linked_minutes' => self::minutesOf(
                $counted->filter(fn (WorkLog $log): bool => $log->work_order_list_id !== null || $log->job_id !== null)
            ),
            'attachment_count' => $counted->sum(fn (WorkLog $log): int => $log->attachments?->count() ?? 0),
            'overlaps' => self::hasOverlap($counted),
        ];
    }

    /**
     * นาทีรวม โดยนับเฉพาะรายการที่ระบุเวลาไว้
     */
    private static function minutesOf(Collection $logs): int
    {
        return (int) $logs->sum(fn (WorkLog $log): int => (int) ($log->duration_minutes ?? 0));
    }

    /**
     * แยกตามประเภทงาน คืนครบทุกประเภทเสมอแม้จะเป็นศูนย์
     * เพื่อให้ฝั่งแสดงผลไม่ต้องเดาว่าคีย์ไหนหายไป
     */
    private static function byKind(Collection $logs): array
    {
        $result = [];

        foreach (WorkLogDesign::kindKeys() as $kind) {
            $matching = $logs->where('kind', $kind);

            $result[$kind] = [
                'label' => WorkLogDesign::KINDS[$kind]['label'],
                'tone' => WorkLogDesign::KINDS[$kind]['tone'],
                'icon' => WorkLogDesign::KINDS[$kind]['icon'],
                'minutes' => self::minutesOf($matching),
                'count' => $matching->count(),
            ];
        }

        return $result;
    }

    /**
     * แยกตามสถานะที่บันทึกจริง เรียงตามลำดับใน WorkLogDesign::STATUSES
     * คืนเฉพาะสถานะที่มีรายการ เพื่อให้กราฟโดนัทไม่มีชิ้นศูนย์ และมีสัดส่วน (percent)
     * ที่คำนวณจากสูตรเดียวกับจำนวนรวม ฝั่งแสดงผลจะได้ไม่ต้องคิดเลขเอง
     *
     * @return array<string, array{label: string, tone: string, icon: string, count: int, percent: float}>
     */
    private static function byStatus(Collection $logs): array
    {
        $total = $logs->count();
        $order = array_flip(WorkLogDesign::statusKeys());

        return $logs
            ->groupBy(fn (WorkLog $log): string => (string) $log->status)
            ->sortBy(fn (Collection $group, string $key): int => $order[$key] ?? PHP_INT_MAX)
            ->map(function (Collection $group, string $key) use ($total): array {
                $status = WorkLogDesign::status($key);

                return [
                    'label' => $status['label'],
                    'tone' => $status['tone'],
                    'icon' => $status['icon'],
                    'count' => $group->count(),
                    'percent' => round($group->count() / $total * 100, 1),
                ];
            })
            ->all();
    }

    /**
     * แยกตามหมวดงาน เรียงจากเวลามากไปน้อย รายการที่ไม่ระบุหมวดรวมเป็นกลุ่มเดียว
     *
     * @return array<int, array{id: int|null, name: string, minutes: int, count: int}>
     */
    private static function byCategory(Collection $logs): array
    {
        return $logs
            ->groupBy(fn (WorkLog $log): string => (string) ($log->work_log_category_id ?? 0))
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'id' => $first->work_log_category_id,
                    'name' => $first->category?->name ?? 'ไม่ระบุหมวด',
                    'minutes' => self::minutesOf($group),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('minutes')
            ->values()
            ->all();
    }

    /**
     * มีรายการที่ช่วงเวลาซ้อนกันหรือไม่
     *
     * ระบบอนุญาตให้ซ้อนกันได้โดยตั้งใจ เพราะงานจริงซ้อนกันจริง (รับสายระหว่างที่
     * backup กำลังรัน) ถ้าบล็อกไว้ผู้ใช้จะเลี่ยงไปกรอกเวลาที่ไม่ตรงความจริงแทน
     * จึงเลือกที่จะ "เตือน" ไม่ใช่ "ห้าม" — ค่านี้มีไว้ให้ฝั่งแสดงผลขึ้นป้ายเตือนเงียบ ๆ
     */
    private static function hasOverlap(Collection $logs): bool
    {
        $ranges = $logs
            ->filter(fn (WorkLog $log): bool => $log->started_at !== null && $log->ended_at !== null)
            ->map(fn (WorkLog $log): array => [
                'start' => $log->started_at->getTimestamp(),
                'end' => $log->ended_at->getTimestamp(),
            ])
            ->sortBy('start')
            ->values();

        $previousEnd = null;

        foreach ($ranges as $range) {
            if ($previousEnd !== null && $range['start'] < $previousEnd) {
                return true;
            }

            $previousEnd = max($previousEnd ?? $range['end'], $range['end']);
        }

        return false;
    }
}
