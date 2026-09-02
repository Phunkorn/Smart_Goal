<?php

namespace App\Services;

use App\Models\Department;
use App\Models\WorkOrder;
use App\Support\ReportMetrics;
use App\Support\WorkBoardDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class AdminReportService
{
    public const BUSINESS_TIMEZONE = ReportMetrics::BUSINESS_TIMEZONE;

    private const DEFAULT_PERIOD = 'last_6_months';

    private const PERIOD_LABELS = [
        'this_month' => 'เดือนนี้',
        'last_month' => 'เดือนที่แล้ว',
        'last_3_months' => '3 เดือนล่าสุด',
        'last_6_months' => '6 เดือนล่าสุด',
        'this_year' => 'ปีนี้',
        'custom' => 'กำหนดเอง',
    ];

    public function build(Request $request, ?int $forcedDepartmentId = null): array
    {
        $departments = Department::query()
            ->when($forcedDepartmentId, fn ($query, int $id) => $query->whereKey($id))
            ->withCount(['users as active_users_count' => fn ($query) => $query
                ->where('role', 'user')
                ->where('is_active', true)])
            ->orderBy('department_name')
            ->get();

        $filters = $this->normalizeFilters($request, $departments, $forcedDepartmentId);
        $jobs = $this->filteredJobs($filters);
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);

        $createdJobs = $jobs->filter(fn (WorkOrder $job) => $this->dateIsInPeriod($job->created_at, $filters));
        $completedJobs = $jobs->filter(fn (WorkOrder $job) => ReportMetrics::isCompleted($job)
            && $this->dateIsInPeriod($job->job_completed_at, $filters));
        $overdueJobs = $jobs->filter(fn (WorkOrder $job) => ReportMetrics::isOverdue($job, $now));
        $statusCounts = array_fill_keys(array_keys(WorkBoardDesign::STATUSES), 0);

        foreach ($jobs as $job) {
            $statusKey = ReportMetrics::statusKey($job, $now);

            if (array_key_exists($statusKey, $statusCounts)) {
                $statusCounts[$statusKey]++;
            }
        }

        $statusSummary = collect(WorkBoardDesign::STATUSES)->map(fn (array $meta, string $key) => [
            'key' => $key,
            ...$meta,
            'value' => $statusCounts[$key],
        ])->values();

        $departmentSummary = $departments
            ->when($filters['department_id'], fn (Collection $items) => $items->where('id', $filters['department_id']))
            ->map(function (Department $department) use ($jobs, $completedJobs, $overdueJobs, $now): array {
                $departmentJobs = $jobs->where('department_id', $department->id);
                $departmentCompleted = $completedJobs->where('department_id', $department->id);
                $done = $completedJobs->where('department_id', $department->id)->count();
                $total = $departmentJobs->count();
                $onTimeEligible = $departmentCompleted->filter(fn (WorkOrder $job) => $job->job_due_at !== null);
                $onTime = $onTimeEligible->filter(fn (WorkOrder $job) => ReportMetrics::isOnTime($job))->count();

                $workload = collect(['doing', 'review', 'late'])->mapWithKeys(fn (string $status) => [
                    $status => $departmentJobs->filter(fn (WorkOrder $job) => ReportMetrics::statusKey($job, $now) === $status)->count(),
                ])->all();

                return [
                    'id' => $department->id,
                    'name' => $department->department_name,
                    'employees' => (int) $department->active_users_count,
                    'total' => $total,
                    'active' => $departmentJobs->filter(fn (WorkOrder $job) => ReportMetrics::isIncomplete($job))->count(),
                    'done' => $done,
                    'overdue' => $overdueJobs->where('department_id', $department->id)->count(),
                    'rate' => $total > 0 ? min(100, (int) round(($done / $total) * 100)) : 0,
                    'on_time' => $onTime,
                    'on_time_eligible' => $onTimeEligible->count(),
                    'on_time_rate' => $onTimeEligible->isNotEmpty()
                        ? (int) round(($onTime / $onTimeEligible->count()) * 100)
                        : 0,
                    'workload' => $workload,
                ];
            })->values();

        $attentionJobs = $jobs
            ->filter(fn (WorkOrder $job) => ReportMetrics::isIncomplete($job)
                && (ReportMetrics::isOverdue($job, $now) || ReportMetrics::isDueSoon($job, $now)))
            ->sortBy(fn (WorkOrder $job) => $job->job_due_at?->timestamp ?? PHP_INT_MAX)
            ->take(10)
            ->map(fn (WorkOrder $job) => [
                'id' => $job->job_id,
                'topic' => $job->job_topic,
                'assignee' => $job->user?->name ?? 'ไม่ระบุผู้รับผิดชอบ',
                'department' => $job->department?->department_name ?? 'ไม่ระบุแผนก',
                'due_at' => $job->job_due_at?->copy()->timezone(self::BUSINESS_TIMEZONE),
                'is_overdue' => ReportMetrics::isOverdue($job, $now),
                'priority' => [
                    'value' => (int) $job->job_priority,
                    ...WorkBoardDesign::taskPriority((int) $job->job_priority),
                ],
                'url' => route('tasks.show', $job->job_id),
            ])->values();

        $monthlySummary = $this->monthlySummary($createdJobs, $completedJobs, $filters);
        $prioritySummary = collect(WorkBoardDesign::TASK_PRIORITIES)->map(fn (array $meta, int $value) => [
            'value' => $value,
            ...$meta,
            'count' => $jobs->where('job_priority', $value)->count(),
        ])->values();
        $totalJobs = $jobs->count();
        $completedCount = $completedJobs->count();

        return [
            'filters' => $filters,
            'filterOptions' => [
                'periods' => self::PERIOD_LABELS,
                'departments' => $departments,
                'statuses' => WorkBoardDesign::STATUSES,
                'priorities' => WorkBoardDesign::TASK_PRIORITIES,
            ],
            'totalJobs' => $totalJobs,
            'completedJobs' => $completedCount,
            'activeJobs' => $jobs->filter(fn (WorkOrder $job) => ReportMetrics::isIncomplete($job))->count(),
            'overdueJobs' => $overdueJobs->count(),
            'completionRate' => $totalJobs > 0 ? min(100, (int) round(($completedCount / $totalJobs) * 100)) : 0,
            'statusSummary' => $statusSummary,
            'departmentSummary' => $departmentSummary,
            'prioritySummary' => $prioritySummary,
            'attentionJobs' => $attentionJobs,
            'monthlySummary' => $monthlySummary,
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
                'departments' => [
                    'labels' => $departmentSummary->pluck('name')->all(),
                    'total' => $departmentSummary->pluck('total')->all(),
                    'completed' => $departmentSummary->pluck('done')->all(),
                    'overdue' => $departmentSummary->pluck('overdue')->all(),
                ],
                'completed' => [
                    'labels' => $monthlySummary->pluck('label')->all(),
                    'values' => $monthlySummary->pluck('completed')->all(),
                ],
                'onTime' => [
                    'labels' => $departmentSummary->pluck('name')->all(),
                    'values' => $departmentSummary->pluck('on_time_rate')->all(),
                    'eligible' => $departmentSummary->pluck('on_time_eligible')->all(),
                ],
                'priority' => [
                    'labels' => $prioritySummary->pluck('label')->all(),
                    'values' => $prioritySummary->pluck('count')->all(),
                    'tones' => $prioritySummary->pluck('tone')->all(),
                ],
                'workload' => [
                    'labels' => $departmentSummary->pluck('name')->all(),
                    'doing' => $departmentSummary->pluck('workload.doing')->all(),
                    'review' => $departmentSummary->pluck('workload.review')->all(),
                    'late' => $departmentSummary->pluck('workload.late')->all(),
                ],
            ],
        ];
    }

    public function isOverdue(WorkOrder $job, ?CarbonInterface $now = null): bool
    {
        return ReportMetrics::isOverdue($job, $now);
    }

    public function exportJobs(Request $request, ?int $forcedDepartmentId = null): Collection
    {
        $departments = Department::query()
            ->when($forcedDepartmentId, fn ($query, int $id) => $query->whereKey($id))
            ->orderBy('department_name')->get();

        return $this->filteredJobs($this->normalizeFilters($request, $departments, $forcedDepartmentId));
    }

    private function filteredJobs(array $filters): Collection
    {
        $query = WorkOrder::query()
            ->with([
                'user:id,name,department_id',
                'department:id,department_name',
            ])
            ->where('approval_status', 'approved')
            ->where(function ($builder) use ($filters): void {
                $builder->whereBetween('created_at', [$filters['start_utc'], $filters['end_utc']])
                    ->orWhere(function ($completed) use ($filters): void {
                        $completed->where('job_status', 4)
                            ->whereNotNull('job_completed_at')
                            ->whereBetween('job_completed_at', [$filters['start_utc'], $filters['end_utc']]);
                    });
            })
            ->when($filters['department_id'], fn ($builder, int $departmentId) => $builder->where('department_id', $departmentId))
            ->when($filters['priority'], fn ($builder, int $priority) => $builder->where('job_priority', $priority))
            ->orderBy('job_id');

        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);

        return $query->get()
            ->when($filters['status'], fn (Collection $items, string $status) => $items
                ->filter(fn (WorkOrder $job) => ReportMetrics::statusKey($job, $now) === $status)
                ->values());
    }

    private function normalizeFilters(Request $request, Collection $departments, ?int $forcedDepartmentId = null): array
    {
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $period = $request->string('period')->toString();
        $period = array_key_exists($period, self::PERIOD_LABELS) ? $period : self::DEFAULT_PERIOD;

        [$start, $end] = match ($period) {
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [$now->subMonthsNoOverflow(2)->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => $this->customRange($request, $now),
            default => [$now->subMonthsNoOverflow(5)->startOfMonth(), $now->endOfMonth()],
        };

        $departmentId = $forcedDepartmentId ?: $request->integer('department');
        $departmentId = $departments->contains('id', $departmentId) ? $departmentId : null;
        $status = $request->string('status')->toString();
        $status = array_key_exists($status, WorkBoardDesign::STATUSES) ? $status : null;
        $priority = $request->integer('priority');
        $priority = array_key_exists($priority, WorkBoardDesign::TASK_PRIORITIES) ? $priority : null;

        return [
            'period' => $period,
            'period_label' => self::PERIOD_LABELS[$period],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'start_utc' => $start->startOfDay()->utc(),
            'end_utc' => $end->endOfDay()->utc(),
            'department_id' => $departmentId,
            'status' => $status,
            'priority' => $priority,
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
            $start = CarbonImmutable::createFromFormat('Y-m-d', $startValue, self::BUSINESS_TIMEZONE)->startOfDay();
            $end = CarbonImmutable::createFromFormat('Y-m-d', $endValue, self::BUSINESS_TIMEZONE)->endOfDay();
        } catch (\Throwable) {
            return [$fallback->startOfMonth(), $fallback->endOfMonth()];
        }

        if ($start->gt($end)) {
            return [$end->startOfDay(), $start->endOfDay()];
        }

        return [$start, $end];
    }

    private function monthlySummary(Collection $createdJobs, Collection $completedJobs, array $filters): Collection
    {
        $cursor = CarbonImmutable::parse($filters['start_date'], self::BUSINESS_TIMEZONE)->startOfMonth();
        $last = CarbonImmutable::parse($filters['end_date'], self::BUSINESS_TIMEZONE)->startOfMonth();
        $months = collect();

        while ($cursor->lte($last) && $months->count() < 24) {
            $month = $cursor;
            $months->push([
                'key' => $month->format('Y-m'),
                'label' => $month->locale('th')->isoFormat('MMM YY'),
                'created' => $createdJobs->filter(fn (WorkOrder $job) => $job->created_at
                    && CarbonImmutable::instance($job->created_at)->setTimezone(self::BUSINESS_TIMEZONE)->isSameMonth($month))->count(),
                'completed' => $completedJobs->filter(fn (WorkOrder $job) => $job->job_completed_at
                    && CarbonImmutable::instance($job->job_completed_at)->setTimezone(self::BUSINESS_TIMEZONE)->isSameMonth($month))->count(),
            ]);
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function dateIsInPeriod(?CarbonInterface $date, array $filters): bool
    {
        return $date !== null
            && CarbonImmutable::instance($date)->utc()->betweenIncluded($filters['start_utc'], $filters['end_utc']);
    }
}
