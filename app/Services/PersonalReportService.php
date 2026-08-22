<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\WorkBoardDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PersonalReportService
{
    public const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    private const DEFAULT_PERIOD = 'last_3_months';

    private const PERIODS = [
        'this_month' => 'เดือนนี้',
        'last_2_months' => '2 เดือนล่าสุด',
        'last_3_months' => '3 เดือนล่าสุด',
        'last_6_months' => '6 เดือนล่าสุด',
        'this_year' => 'ทั้งปีที่เลือก',
    ];

    public function build(User $user, Request $request): array
    {
        $filters = $this->normalizeFilters($request);
        $jobs = $this->filteredJobs($user, $filters);
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);

        $upcomingJobs = $jobs
            ->filter(fn (WorkOrder $job) => $this->isIncomplete($job)
                && $job->job_due_at
                && CarbonImmutable::instance($job->job_due_at)->setTimezone(self::BUSINESS_TIMEZONE)->endOfDay()->gte($now))
            ->sortBy(fn (WorkOrder $job) => $job->job_due_at->timestamp)
            ->take(8)
            ->map(fn (WorkOrder $job) => $this->presentJob($job, $now))
            ->values();

        $attentionJobs = $jobs
            ->filter(fn (WorkOrder $job) => $this->isIncomplete($job)
                && ($this->isOverdue($job, $now) || $this->isDueSoon($job, $now) || (int) $job->job_priority === 3))
            ->sortBy(fn (WorkOrder $job) => [
                $this->isOverdue($job, $now) ? 0 : ($this->isDueSoon($job, $now) ? 1 : 2),
                $job->job_due_at?->timestamp ?? PHP_INT_MAX,
            ])
            ->take(8)
            ->map(function (WorkOrder $job) use ($now): array {
                $item = $this->presentJob($job, $now);
                $item['reason'] = $this->isOverdue($job, $now)
                    ? 'เกินกำหนด'
                    : ($this->isDueSoon($job, $now) ? 'ครบกำหนดใน 7 วัน' : 'สำคัญด่วน');

                return $item;
            })
            ->values();

        $workload = collect(range(0, 2))->map(function (int $offset) use ($jobs, $now): array {
            $month = $now->addMonthsNoOverflow($offset)->startOfMonth();

            return [
                'key' => $month->format('Y-m'),
                'label' => $month->locale('th')->isoFormat('MMM YY'),
                'value' => $jobs->filter(fn (WorkOrder $job) => $this->isIncomplete($job)
                    && $job->job_due_at
                    && CarbonImmutable::instance($job->job_due_at)->setTimezone(self::BUSINESS_TIMEZONE)->isSameMonth($month))->count(),
            ];
        });

        $prioritySummary = collect(WorkBoardDesign::TASK_PRIORITIES)->map(function (array $meta, int $value) use ($jobs): array {
            return [
                'value' => $value,
                ...$meta,
                'count' => $jobs->where('job_priority', $value)->count(),
            ];
        })->values();

        $presentedJobs = $jobs
            ->sortBy(fn (WorkOrder $job) => [$job->job_due_at?->timestamp ?? PHP_INT_MAX, $job->job_id])
            ->map(fn (WorkOrder $job) => $this->presentJob($job, $now))
            ->values();

        return [
            'employee' => $user->load('department'),
            'filters' => $filters,
            'filterOptions' => [
                'periods' => self::PERIODS,
                'statuses' => [
                    1 => 'ยังไม่เริ่ม',
                    2 => 'กำลังทำ',
                    3 => 'รอตรวจสอบ',
                    4 => 'เสร็จสิ้น',
                    5 => 'พักงาน',
                ],
                'priorities' => WorkBoardDesign::TASK_PRIORITIES,
            ],
            // Keep the existing view contract for integrations/tests that inspect scoped models.
            'jobs' => $jobs,
            'taskRows' => $presentedJobs,
            'upcomingJobs' => $upcomingJobs,
            'attentionJobs' => $attentionJobs,
            'totalJobs' => $jobs->count(),
            'inProgressJobs' => $jobs->where('job_status', 2)->count(),
            'dueSoonJobs' => $jobs->filter(fn (WorkOrder $job) => $this->isDueSoon($job, $now))->count(),
            'overdueJobs' => $jobs->filter(fn (WorkOrder $job) => $this->isOverdue($job, $now))->count(),
            'workloadSummary' => $workload,
            'prioritySummary' => $prioritySummary,
            'chartData' => [
                'workload' => [
                    'labels' => $workload->pluck('label')->all(),
                    'values' => $workload->pluck('value')->all(),
                ],
                'priority' => [
                    'labels' => $prioritySummary->pluck('label')->all(),
                    'values' => $prioritySummary->pluck('count')->all(),
                    'tones' => $prioritySummary->pluck('tone')->all(),
                ],
            ],
        ];
    }

    public function queryFor(int $userId): Builder
    {
        return WorkOrder::query()->where(function (Builder $query) use ($userId): void {
            $query->where('user_id', $userId)
                ->orWhere('created_by', $userId)
                ->orWhere('leader_user_id', $userId)
                ->orWhereHas('collaborators', function (Builder $collaboratorQuery) use ($userId): void {
                    $collaboratorQuery
                        ->where('users.id', $userId)
                        ->where('work_order_collaborators.status', 'accepted');
                });
        });
    }

    private function filteredJobs(User $user, array $filters): Collection
    {
        return $this->queryFor($user->id)
            ->with(['department:id,department_name', 'taskList:id,name'])
            ->whereBetween('created_at', [$filters['start_utc'], $filters['end_utc']])
            ->when($filters['status'], fn (Builder $query, int $status) => $query->where('job_status', $status))
            ->when($filters['priority'], fn (Builder $query, int $priority) => $query->where('job_priority', $priority))
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where('job_topic', 'like', '%'.$search.'%'))
            ->orderBy('job_id')
            ->get();
    }

    private function normalizeFilters(Request $request): array
    {
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $period = $request->string('period')->toString();

        if ($period === '' && $request->filled('year')) {
            $period = 'this_year';
        }

        $period = array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
        [$start, $end] = match ($period) {
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_2_months' => [$now->subMonthNoOverflow()->startOfMonth(), $now->endOfMonth()],
            'last_6_months' => [$now->subMonthsNoOverflow(5)->startOfMonth(), $now->endOfMonth()],
            'this_year' => $this->yearRange($request, $now),
            default => [$now->subMonthsNoOverflow(2)->startOfMonth(), $now->endOfMonth()],
        };

        $status = $request->integer('status');
        $priority = $request->integer('priority');

        return [
            'period' => $period,
            'period_label' => self::PERIODS[$period],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'start_utc' => $start->startOfDay()->utc(),
            'end_utc' => $end->endOfDay()->utc(),
            'year' => $this->selectedYear($request, $now),
            'status' => in_array($status, range(1, 5), true) ? $status : null,
            'priority' => array_key_exists($priority, WorkBoardDesign::TASK_PRIORITIES) ? $priority : null,
            'search' => mb_substr(trim($request->string('search')->toString()), 0, 100),
        ];
    }

    private function yearRange(Request $request, CarbonImmutable $now): array
    {
        $year = $this->selectedYear($request, $now);
        $date = $now->setYear($year);

        return [$date->startOfYear(), $date->endOfYear()];
    }

    private function selectedYear(Request $request, CarbonImmutable $now): int
    {
        $year = $request->integer('year');

        return $year >= 2000 && $year <= 2100 ? $year : $now->year;
    }

    private function presentJob(WorkOrder $job, CarbonInterface $now): array
    {
        $status = $this->status($job, $now);

        return [
            'id' => $job->job_id,
            'topic' => $job->job_topic,
            'project' => $job->taskList?->name ?? $job->department?->department_name ?? 'งานทั่วไป',
            'due_at' => $job->job_due_at?->copy()->timezone(self::BUSINESS_TIMEZONE),
            'progress' => (int) $job->job_progress,
            'status' => $status,
            'priority' => [
                'value' => (int) $job->job_priority,
                ...WorkBoardDesign::taskPriority((int) $job->job_priority),
            ],
            'url' => route('tasks.show', $job->job_id),
        ];
    }

    private function status(WorkOrder $job, CarbonInterface $now): array
    {
        if ($this->isOverdue($job, $now)) {
            return ['key' => 'late', ...WorkBoardDesign::STATUSES['late']];
        }

        $key = match ((int) $job->job_status) {
            2 => 'doing',
            3 => 'review',
            4 => 'done',
            5 => 'paused',
            default => 'todo',
        };

        return ['key' => $key, ...WorkBoardDesign::STATUSES[$key]];
    }

    private function isIncomplete(WorkOrder $job): bool
    {
        return (int) $job->job_status !== 4;
    }

    private function isOverdue(WorkOrder $job, CarbonInterface $now): bool
    {
        return $this->isIncomplete($job)
            && $job->job_due_at
            && CarbonImmutable::instance($job->job_due_at)->setTimezone(self::BUSINESS_TIMEZONE)->endOfDay()->lt($now);
    }

    private function isDueSoon(WorkOrder $job, CarbonInterface $now): bool
    {
        if (! $this->isIncomplete($job) || ! $job->job_due_at || $this->isOverdue($job, $now)) {
            return false;
        }

        $dueAt = CarbonImmutable::instance($job->job_due_at)->setTimezone(self::BUSINESS_TIMEZONE)->endOfDay();

        return $dueAt->betweenIncluded($now, CarbonImmutable::instance($now)->addDays(7)->endOfDay());
    }
}
