<?php

namespace App\Support;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TodayWorkspace
{
    public static function synchronizeActiveToday(Builder $query): void
    {
        (clone $query)->where('job_status', 1)
            ->whereDate('job_start_at', now()->toDateString())
            ->update(['job_status' => 2]);
    }

    public static function synchronizeLate(Builder $query): void
    {
        (clone $query)->whereNotIn('job_status', [4, 5, 6])
            ->whereNotNull('job_due_at')->where('job_due_at', '<', now()->startOfDay())
            ->update(['job_status' => 6, 'late_at' => now()]);
    }

    public static function normalizeLateForTransition(WorkOrder $task): bool
    {
        if (in_array((int) $task->job_status, [4, 5, 6], true)
            || ! $task->job_due_at
            || ! $task->job_due_at->copy()->endOfDay()->isPast()) {
            return (int) $task->job_status === 6;
        }

        $task->update([
            'job_status' => 6,
            'late_at' => $task->late_at ?? now(),
        ]);

        return true;
    }

    public static function tasks(Collection $tasks): Collection
    {
        $today = now()->startOfDay();

        return $tasks->filter(fn (WorkOrder $task): bool => match ((int) $task->job_status) {
            4 => $task->job_completed_at?->isSameDay($today) ?? false,
            5, 6 => true,
            default => $task->job_start_at?->isSameDay($today) ?? false,
        })->values();
    }
}
