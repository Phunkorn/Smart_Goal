<?php

namespace App\Support;

use App\Models\Department;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WorkBoardDesign
{
    public const STATUSES = [
        'doing' => ['label' => 'กำลังทำ', 'tone' => 'blue', 'icon' => 'bi-play-circle'],
        'review' => ['label' => 'รอตรวจสอบ', 'tone' => 'purple', 'icon' => 'bi-eye'],
        'done' => ['label' => 'เสร็จสิ้น', 'tone' => 'green', 'icon' => 'bi-check-circle'],
        'paused' => ['label' => 'พักงาน', 'tone' => 'amber', 'icon' => 'bi-pause-circle'],
        'late' => ['label' => 'ล่าช้า', 'tone' => 'red', 'icon' => 'bi-exclamation-circle'],
    ];

    public const TASK_PRIORITIES = [
        1 => ['label' => 'routine', 'tone' => 'gray'],
        2 => ['label' => 'สำคัญไม่ด่วน', 'tone' => 'blue'],
        3 => ['label' => 'สำคัญด่วน', 'tone' => 'red'],
        4 => ['label' => 'ด่วนไม่ค่อยสำคัญ', 'tone' => 'amber'],
        5 => ['label' => 'ไม่รีบ ไม่มีกำหนด', 'tone' => 'green'],
    ];

    public const PROJECT_PRIORITIES = [
        1 => ['label' => 'ต่ำ', 'tone' => 'gray'],
        2 => ['label' => 'กลาง', 'tone' => 'amber'],
        3 => ['label' => 'สูง', 'tone' => 'red'],
    ];

    private const DEPARTMENT_TONES = ['blue', 'teal', 'purple', 'amber', 'cyan', 'rose'];

    public static function statusKey(WorkOrder $job): string
    {
        if ((int) $job->job_status === 5) {
            return 'paused';
        }

        if ((int) $job->job_status === 6 || ((int) $job->job_status !== 4 && $job->job_due_at?->copy()->endOfDay()->isPast())) {
            return 'late';
        }

        return match ((int) $job->job_status) {
            2 => 'doing',
            3 => 'review',
            4 => 'done',
            default => 'unsupported',
        };
    }

    public static function status(WorkOrder $job): array
    {
        $key = self::statusKey($job);

        return ['key' => $key, ...self::statusMeta($key)];
    }

    public static function statusMeta(string $key): array
    {
        return self::STATUSES[$key] ?? [
            'label' => 'สถานะไม่รองรับ',
            'tone' => 'gray',
            'icon' => 'bi-question-circle',
        ];
    }

    public static function taskPriority(int $priority): array
    {
        return self::TASK_PRIORITIES[$priority] ?? self::TASK_PRIORITIES[2];
    }

    public static function statusCounts(Collection $jobs): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach ($jobs as $job) {
            $key = self::statusKey($job);

            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    public static function departmentTone(Department $department): string
    {
        $hash = (int) sprintf('%u', crc32(Str::lower($department->department_name)));

        return self::DEPARTMENT_TONES[$hash % count(self::DEPARTMENT_TONES)];
    }

    public static function departmentCode(Department $department): string
    {
        $name = trim($department->department_name);
        $latin = preg_replace('/[^A-Za-z0-9]/', '', $name);

        return Str::upper(Str::substr($latin ?: $name, 0, 2));
    }

    public static function initials(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '?' : Str::upper(Str::substr($name, 0, 2));
    }
}
