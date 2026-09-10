<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ContributionRole;
use App\Support\ReportMetrics;
use App\Support\ReportTaskTree;
use App\Support\TaskTeamSummary;
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

    /** สถานะที่เลือกได้ในตัวกรอง แปลงเป็นคีย์เดียวกับที่ ReportMetrics ใช้แสดงผล */
    private const STATUS_KEYS = [
        2 => 'doing',
        3 => 'review',
        4 => 'done',
        5 => 'paused',
        6 => 'late',
    ];

    private const STATUS_LABELS = [
        2 => 'กำลังทำ',
        3 => 'รอตรวจสอบ',
        4 => 'เสร็จสิ้น',
        5 => 'พักงาน',
        6 => 'ล่าช้า',
    ];

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
            ->map(fn (WorkOrder $job) => $this->presentJob($job, $now, $user->id))
            ->values();

        $attentionJobs = $jobs
            ->filter(fn (WorkOrder $job) => $this->isIncomplete($job)
                && ($this->isOverdue($job, $now) || $this->isDueSoon($job, $now) || (int) $job->job_priority === 3))
            ->sortBy(fn (WorkOrder $job) => [
                $this->isOverdue($job, $now) ? 0 : ($this->isDueSoon($job, $now) ? 1 : 2),
                $job->job_due_at?->timestamp ?? PHP_INT_MAX,
            ])
            ->map(function (WorkOrder $job) use ($now, $user): array {
                $item = $this->presentJob($job, $now, $user->id);
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
            ->map(fn (WorkOrder $job) => $this->presentJob($job, $now, $user->id))
            ->values();

        return [
            'employee' => $user->load('department'),
            'filters' => $filters,
            'filterOptions' => [
                'periods' => self::PERIODS,
                'statuses' => self::STATUS_LABELS,
                'priorities' => WorkBoardDesign::TASK_PRIORITIES,
            ],
            // Keep the existing view contract for integrations/tests that inspect scoped models.
            'jobs' => $jobs,
            'taskRows' => $presentedJobs,
            'upcomingJobs' => $upcomingJobs,
            'attentionJobs' => $attentionJobs,
            'totalJobs' => $jobs->count(),
            // แยกงานที่ถือความรับผิดชอบหลักออกจากงานที่ไปร่วมกับคนอื่น
            // ยอดรวมก้อนเดียวทำให้อ่านไม่ออกว่าผลงานส่วนไหนเป็นงานของตัวเอง
            'ownedJobs' => $jobs->filter(fn (WorkOrder $job) => ContributionRole::isPrimary($job, $user->id))->count(),
            'joinedJobs' => $jobs->reject(fn (WorkOrder $job) => ContributionRole::isPrimary($job, $user->id))->count(),
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
        return WorkOrder::query()->contributedBy($userId);
    }

    private function filteredJobs(User $user, array $filters): Collection
    {
        return $this->queryFor($user->id)
            ->with(['user:id,name', 'leader:id,name', 'assigner:id,name', 'creator:id,name', 'collaborators:id,name', 'children:job_id,parent_job_id,job_topic', 'department:id,department_name', 'taskList:id,name'])
            // งานที่ปิดในช่วงนี้ต้องนับด้วยแม้จะสร้างก่อนหน้า มิฉะนั้นผลงานที่เพิ่งส่งมอบจะหายไปจากรายงาน
            ->where(function (Builder $window) use ($filters): void {
                $window->whereBetween('created_at', [$filters['start_utc'], $filters['end_utc']])
                    ->orWhere(function (Builder $completed) use ($filters): void {
                        $completed->where('job_status', 4)
                            ->whereNotNull('job_completed_at')
                            ->whereBetween('job_completed_at', [$filters['start_utc'], $filters['end_utc']]);
                    });
            })
            ->when($filters['priority'], fn (Builder $query, int $priority) => $query->where('job_priority', $priority))
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where('job_topic', 'like', '%'.$search.'%'))
            ->orderBy('job_id')
            ->get()
            /*
             * กรองสถานะจาก "สถานะที่ตารางแสดง" ไม่ใช่ค่าดิบในคอลัมน์ job_status
             *
             * งานที่ยังเป็น "กำลังทำ" แต่เลยกำหนดส่งแล้วจะถูกแสดงว่าล่าช้า ถ้ากรองด้วย
             * คอลัมน์ตรง ๆ ผู้ใช้จะเลือก "ล่าช้า" แล้วไม่เจองานที่หน้าจอบอกว่าล่าช้าอยู่
             */
            // ยุบงานย่อยเข้าใต้งานแม่ก่อนกรองและนับ มิฉะนั้นงานใบเดียวจะถูกนับหลายรอบ
            ->pipe(fn (Collection $jobs) => ReportTaskTree::collapse($jobs))
            ->when($filters['status'], function (Collection $jobs, int $status): Collection {
                $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
                $wanted = self::STATUS_KEYS[$status];

                return $jobs->filter(fn (WorkOrder $job) => ReportMetrics::statusKey($job, $now) === $wanted)->values();
            });
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
            'status' => array_key_exists($status, self::STATUS_KEYS) ? $status : null,
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

    private function presentJob(WorkOrder $job, CarbonInterface $now, int $userId): array
    {
        $status = $this->status($job, $now);

        return [
            'id' => $job->job_id,
            'topic' => $job->job_topic,
            'team' => TaskTeamSummary::for($job, $userId),
            'subtasks' => ReportTaskTree::subtaskNames($job),
            'role' => ContributionRole::for($job, $userId),
            'project' => $job->taskList?->name ?? $job->department?->department_name ?? 'งานทั่วไป',
            'due_at' => $job->job_due_at?->copy()->timezone(self::BUSINESS_TIMEZONE),
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
        $key = ReportMetrics::statusKey($job, $now);

        return ['key' => $key, ...WorkBoardDesign::statusMeta($key)];
    }

    private function isIncomplete(WorkOrder $job): bool
    {
        return ReportMetrics::isIncomplete($job);
    }

    private function isOverdue(WorkOrder $job, CarbonInterface $now): bool
    {
        return ReportMetrics::isOverdue($job, $now);
    }

    /**
     * หน้ารายงานของฉันเตือนล่วงหน้า 7 วัน กว้างกว่าค่าปริยาย 3 วันของ ReportMetrics
     * เพราะพนักงานใช้หน้านี้วางแผนงานรายสัปดาห์ ไม่ใช่ดูเฉพาะสิ่งที่ต้องทำวันนี้
     */
    private function isDueSoon(WorkOrder $job, CarbonInterface $now): bool
    {
        return ReportMetrics::isIncomplete($job) && ReportMetrics::isDueSoon($job, $now, 7);
    }
}
