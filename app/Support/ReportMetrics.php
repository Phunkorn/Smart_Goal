<?php

namespace App\Support;

use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ReportMetrics
{
    public const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    public static function isCompleted(WorkOrder $job): bool
    {
        return (int) $job->job_status === 4 && $job->job_completed_at !== null;
    }

    public static function isIncomplete(WorkOrder $job): bool
    {
        return (int) $job->job_status !== 4;
    }

    public static function isOverdue(WorkOrder $job, ?CarbonInterface $now = null): bool
    {
        if (! self::isIncomplete($job)) {
            return false;
        }

        if ((int) $job->job_status === 6) {
            return true;
        }

        if (! $job->job_due_at) {
            return false;
        }

        return self::businessDueAt($job)->lt(self::businessNow($now));
    }

    public static function isOnTime(WorkOrder $job): bool
    {
        if (! self::isCompleted($job) || ! $job->job_due_at) {
            return false;
        }

        $completedAt = CarbonImmutable::instance($job->job_completed_at)
            ->setTimezone(self::BUSINESS_TIMEZONE);

        return $completedAt->lte(self::businessDueAt($job));
    }

    public static function isDueSoon(WorkOrder $job, ?CarbonInterface $now = null, int $days = 3): bool
    {
        if (! $job->job_due_at || self::isOverdue($job, $now)) {
            return false;
        }

        $businessNow = self::businessNow($now);

        return self::businessDueAt($job)->betweenIncluded(
            $businessNow,
            $businessNow->addDays($days)->endOfDay(),
        );
    }

    public static function statusKey(WorkOrder $job, ?CarbonInterface $now = null): string
    {
        if ((int) $job->job_status === 4) {
            return 'done';
        }

        if (self::isOverdue($job, $now)) {
            return 'late';
        }

        return match ((int) $job->job_status) {
            2 => 'doing',
            3 => 'review',
            5 => 'paused',
            6 => 'late',
            default => 'todo',
        };
    }

    private static function businessNow(?CarbonInterface $now): CarbonImmutable
    {
        return ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now(self::BUSINESS_TIMEZONE))
            ->setTimezone(self::BUSINESS_TIMEZONE);
    }

    private static function businessDueAt(WorkOrder $job): CarbonImmutable
    {
        return CarbonImmutable::instance($job->job_due_at)
            ->setTimezone(self::BUSINESS_TIMEZONE)
            ->endOfDay();
    }
}
