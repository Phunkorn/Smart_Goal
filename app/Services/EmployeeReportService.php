<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ReportMetrics;
use App\Support\WorkBoardDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class EmployeeReportService
{
    private const DEFAULT_PERIOD = 'last_6_months';

    private const PERIOD_LABELS = [
        'this_month' => 'เดือนนี้',
        'last_3_months' => '3 เดือนล่าสุด',
        'last_6_months' => '6 เดือนล่าสุด',
        'this_year' => 'ปีนี้',
        'custom' => 'กำหนดเอง',
    ];

    public function build(User $employee, Request $request): array
    {
        $filters = $this->normalizeFilters($request);
        $jobs = $this->filteredJobs($employee, $filters);
        $now = CarbonImmutable::now(ReportMetrics::BUSINESS_TIMEZONE);
        $createdJobs = $jobs->filter(fn (WorkOrder $job) => $this->dateIsInPeriod($job->created_at, $filters));
        $completedJobs = $jobs->filter(fn (WorkOrder $job) => ReportMetrics::isCompleted($job)
            && $this->dateIsInPeriod($job->job_completed_at, $filters));

        $statusCounts = array_fill_keys(array_keys(WorkBoardDesign::STATUSES), 0);
        foreach ($jobs as $job) {
            $statusCounts[ReportMetrics::statusKey($job, $now)]++;
        }

        $statusSummary = collect(WorkBoardDesign::STATUSES)->map(fn (array $meta, string $key) => [
            'key' => $key,
            ...$meta,
            'value' => $statusCounts[$key],
        ])->values();

        $prioritySummary = collect(WorkBoardDesign::TASK_PRIORITIES)->map(fn (array $meta, int $value) => [
            'value' => $value,
            ...$meta,
            'count' => $jobs->where('job_priority', $value)->count(),
        ])->values();

        $monthlySummary = $this->monthlySummary($createdJobs, $completedJobs, $filters);
        $onTimeEligible = $completedJobs->filter(fn (WorkOrder $job) => $job->job_due_at !== null);
        $onTimeCount = $onTimeEligible->filter(fn (WorkOrder $job) => ReportMetrics::isOnTime($job))->count();
        $onTimeRate = $onTimeEligible->isNotEmpty()
            ? (int) round(($onTimeCount / $onTimeEligible->count()) * 100)
            : 0;

        $attentionJobs = $jobs
            ->filter(fn (WorkOrder $job) => ReportMetrics::isIncomplete($job)
                && (ReportMetrics::isOverdue($job, $now) || ReportMetrics::isDueSoon($job, $now)))
            ->sortBy(fn (WorkOrder $job) => $job->job_due_at?->timestamp ?? PHP_INT_MAX)
            ->take(8)
            ->map(fn (WorkOrder $job) => $this->presentJob($job, $now))
            ->values();

        $taskRows = $jobs
            ->sortByDesc(fn (WorkOrder $job) => $job->job_completed_at?->timestamp ?? $job->created_at?->timestamp ?? 0)
            ->map(fn (WorkOrder $job) => $this->presentJob($job, $now))
            ->values();

        return [
            'employee' => $employee->load('department'),
            'filters' => $filters,
            'filterOptions' => ['periods' => self::PERIOD_LABELS],
            'totalJobs' => $jobs->count(),
            'completedJobs' => $completedJobs->count(),
            'overdueJobs' => $jobs->filter(fn (WorkOrder $job) => ReportMetrics::isOverdue($job, $now))->count(),
            'onTimeCount' => $onTimeCount,
            'onTimeEligible' => $onTimeEligible->count(),
            'onTimeRate' => $onTimeRate,
            'statusSummary' => $statusSummary,
            'prioritySummary' => $prioritySummary,
            'monthlySummary' => $monthlySummary,
            'attentionJobs' => $attentionJobs,
            'taskRows' => $taskRows,
            'chartData' => [
                'trend' => [
                    'labels' => $monthlySummary->pluck('label')->all(),
                    'created' => $monthlySummary->pluck('created')->all(),
                    'completed' => $monthlySummary->pluck('completed')->all(),
                ],
                'status' => [
                    'labels' => $statusSummary->pluck('label')->all(),
                    'values' => $statusSummary->pluck('value')->all(),
                    'tones' => $statusSummary->pluck('tone')->all(),
                ],
                'completed' => [
                    'labels' => $monthlySummary->pluck('label')->all(),
                    'values' => $monthlySummary->pluck('completed')->all(),
                ],
                'onTime' => [
                    'rate' => $onTimeRate,
                    'onTime' => $onTimeCount,
                    'late' => max(0, $onTimeEligible->count() - $onTimeCount),
                    'eligible' => $onTimeEligible->count(),
                ],
                'priority' => [
                    'labels' => $prioritySummary->pluck('label')->all(),
                    'values' => $prioritySummary->pluck('count')->all(),
                    'tones' => $prioritySummary->pluck('tone')->all(),
                ],
            ],
        ];
    }

    public function exportJobs(User $employee, Request $request): Collection
    {
        return $this->filteredJobs($employee, $this->normalizeFilters($request));
    }

    private function filteredJobs(User $employee, array $filters): Collection
    {
        return WorkOrder::query()
            ->with(['user:id,name,department_id', 'department:id,department_name', 'taskList:id,name'])
            ->where('user_id', $employee->id)
            ->where('approval_status', 'approved')
            ->where(function ($query) use ($filters): void {
                $query->whereBetween('created_at', [$filters['start_utc'], $filters['end_utc']])
                    ->orWhere(function ($completed) use ($filters): void {
                        $completed->where('job_status', 4)
                            ->whereNotNull('job_completed_at')
                            ->whereBetween('job_completed_at', [$filters['start_utc'], $filters['end_utc']]);
                    });
            })
            ->orderBy('job_id')
            ->get();
    }

    private function normalizeFilters(Request $request): array
    {
        $now = CarbonImmutable::now(ReportMetrics::BUSINESS_TIMEZONE);
        $period = $request->string('period')->toString();
        $period = array_key_exists($period, self::PERIOD_LABELS) ? $period : self::DEFAULT_PERIOD;

        [$start, $end] = match ($period) {
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_3_months' => [$now->subMonthsNoOverflow(2)->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => $this->customRange($request, $now),
            default => [$now->subMonthsNoOverflow(5)->startOfMonth(), $now->endOfMonth()],
        };

        return [
            'period' => $period,
            'period_label' => self::PERIOD_LABELS[$period],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'start_utc' => $start->startOfDay()->utc(),
            'end_utc' => $end->endOfDay()->utc(),
        ];
    }

    private function customRange(Request $request, CarbonImmutable $fallback): array
    {
        $startValue = $request->string('start_date')->toString();
        $endValue = $request->string('end_date')->toString();

        if (! CarbonImmutable::hasFormat($startValue, 'Y-m-d')
            || ! CarbonImmutable::hasFormat($endValue, 'Y-m-d')) {
            return [$fallback->startOfMonth(), $fallback->endOfMonth()];
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $startValue, ReportMetrics::BUSINESS_TIMEZONE)->startOfDay();
            $end = CarbonImmutable::createFromFormat('Y-m-d', $endValue, ReportMetrics::BUSINESS_TIMEZONE)->endOfDay();
        } catch (\Throwable) {
            return [$fallback->startOfMonth(), $fallback->endOfMonth()];
        }

        return $start->lte($end) ? [$start, $end] : [$end->startOfDay(), $start->endOfDay()];
    }

    private function monthlySummary(Collection $createdJobs, Collection $completedJobs, array $filters): Collection
    {
        $cursor = CarbonImmutable::parse($filters['start_date'], ReportMetrics::BUSINESS_TIMEZONE)->startOfMonth();
        $last = CarbonImmutable::parse($filters['end_date'], ReportMetrics::BUSINESS_TIMEZONE)->startOfMonth();
        $months = collect();

        while ($cursor->lte($last) && $months->count() < 24) {
            $month = $cursor;
            $months->push([
                'key' => $month->format('Y-m'),
                'label' => $month->locale('th')->isoFormat('MMM YY'),
                'created' => $createdJobs->filter(fn (WorkOrder $job) => $job->created_at
                    && CarbonImmutable::instance($job->created_at)->setTimezone(ReportMetrics::BUSINESS_TIMEZONE)->isSameMonth($month))->count(),
                'completed' => $completedJobs->filter(fn (WorkOrder $job) => $job->job_completed_at
                    && CarbonImmutable::instance($job->job_completed_at)->setTimezone(ReportMetrics::BUSINESS_TIMEZONE)->isSameMonth($month))->count(),
            ]);
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function presentJob(WorkOrder $job, CarbonInterface $now): array
    {
        $statusKey = ReportMetrics::statusKey($job, $now);

        return [
            'id' => $job->job_id,
            'topic' => $job->job_topic,
            'project' => $job->taskList?->name ?? $job->department?->department_name ?? 'งานทั่วไป',
            'status' => ['key' => $statusKey, ...WorkBoardDesign::STATUSES[$statusKey]],
            'priority' => ['value' => (int) $job->job_priority, ...WorkBoardDesign::taskPriority((int) $job->job_priority)],
            'start_at' => $job->job_start_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
            'due_at' => $job->job_due_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
            'completed_at' => $job->job_completed_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
            'is_overdue' => ReportMetrics::isOverdue($job, $now),
            'url' => route('tasks.show', $job->job_id),
        ];
    }

    private function dateIsInPeriod(?CarbonInterface $date, array $filters): bool
    {
        return $date !== null
            && CarbonImmutable::instance($date)->utc()->betweenIncluded($filters['start_utc'], $filters['end_utc']);
    }
}
