<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * รายงานภาระงานปฏิบัติการ — งานประจำ งานแทรก และงานนอกสถานที่
 *
 * แยกออกจาก AdminReportService โดยเด็ดขาด ตัวเลขจากบันทึกงานประจำวันต้องไม่
 * ไหลเข้าไปในรายงานผลงานโครงการ เพราะจะทำให้ KPI ของโครงการเพี้ยน (เช่น
 * "อัตราปิดงาน" ที่มีตัวหารเป็นงานโครงการ) และตีความผิดว่าโครงการเดินหน้า
 * มากกว่าความจริง
 *
 * รายงานนี้ตอบคำถามที่รายงานโครงการตอบไม่ได้: "วันที่โครงการไม่ขยับ พนักงาน
 * เอาเวลาไปทำอะไร" — คำตอบคือชั่วโมงงานปฏิบัติการที่มองไม่เห็นบนบอร์ด
 *
 * เรื่องฐานข้อมูล: การรวมเวลาทำด้วยการ SUM คอลัมน์ duration_minutes และการ
 * จัดกลุ่มทำด้วยคอลัมน์ work_date ที่เป็นวันของกรุงเทพอยู่แล้ว จึงไม่ต้องใช้
 * ฟังก์ชันวันที่ของ SQL ซึ่งเขียนไม่เหมือนกันระหว่าง SQLite (ทดสอบ)
 * กับ MySQL (production)
 */
final class OperationalWorkloadReportService
{
    public const BUSINESS_TIMEZONE = TodayWorkspace::BUSINESS_TIMEZONE;

    private const DEFAULT_PERIOD = 'this_month';

    /** จำนวนคนสูงสุดในกราฟชั่วโมงรายคน มากกว่านี้แท่งจะเตี้ยจนอ่านไม่ออก */
    private const MEMBER_CHART_LIMIT = 8;

    /** จำนวนแถวสูงสุดของตารางย่อในรายงานรายบุคคล */
    public const EMPLOYEE_ROW_LIMIT = 10;

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
            ->orderBy('department_name')
            ->get();

        $categories = WorkLogCategory::query()->selectable()->get();
        $filters = $this->normalizeFilters($request, $departments, $categories, $forcedDepartmentId);
        $logs = $this->filteredLogs($filters);

        $memberSummary = $this->memberSummary($logs);
        $categorySummary = $this->categorySummary($logs);
        $dailySummary = $this->dailySummary($logs, $filters);
        $kindSummary = $this->kindSummary($logs);

        $totalMinutes = $this->minutesOf($logs);
        $unlinkedMinutes = $this->minutesOf($logs->filter(
            fn (WorkLog $log): bool => $log->work_order_list_id === null && $log->job_id === null
        ));

        return [
            'filters' => $filters,
            'filterOptions' => [
                'periods' => self::PERIOD_LABELS,
                'departments' => $departments,
                'kinds' => WorkLogDesign::KINDS,
                'categories' => $categories,
            ],
            'totalMinutes' => $totalMinutes,
            'totalHoursLabel' => $this->hoursLabel($totalMinutes),
            'totalCount' => $logs->count(),
            'interruptCount' => $logs->where('kind', 'interrupt')->count(),
            'unlinkedMinutes' => $unlinkedMinutes,
            'unlinkedHoursLabel' => $this->hoursLabel($unlinkedMinutes),
            'unlinkedShare' => $totalMinutes > 0
                ? (int) round(($unlinkedMinutes / $totalMinutes) * 100)
                : 0,
            'peopleCount' => $logs->pluck('user_id')->unique()->count(),
            'kindSummary' => $kindSummary,
            'memberSummary' => $memberSummary,
            'categorySummary' => $categorySummary,
            'dailySummary' => $dailySummary,
            'topTitles' => $this->topTitles($logs),
            'chartData' => [
                // ชั่วโมงงานตามประเภท ต่อวัน — เห็นได้ทันทีว่าวันไหนงานแทรกกินเวลา
                'daily' => [
                    'labels' => $dailySummary->pluck('label')->all(),
                    'routine' => $dailySummary->pluck('routine_hours')->all(),
                    'interrupt' => $dailySummary->pluck('interrupt_hours')->all(),
                    'field' => $dailySummary->pluck('field_hours')->all(),
                ],
                'categories' => [
                    'labels' => $categorySummary->pluck('name')->all(),
                    'values' => $categorySummary->pluck('hours')->all(),
                    'tones' => $categorySummary->pluck('tone')->all(),
                ],
                'members' => [
                    'labels' => $memberSummary->take(self::MEMBER_CHART_LIMIT)->pluck('name')->all(),
                    'values' => $memberSummary->take(self::MEMBER_CHART_LIMIT)->pluck('hours')->all(),
                ],
                // จำนวนงานแทรกต่อวัน — ภาพที่อธิบายว่าทำไมโครงการนิ่งในบางวัน
                'interrupts' => [
                    'labels' => $dailySummary->pluck('label')->all(),
                    'values' => $dailySummary->pluck('interrupt_count')->all(),
                ],
            ],
        ];
    }

    /**
     * สรุปภาระงานปฏิบัติการของพนักงานหนึ่งคน สำหรับแสดงในรายงานรายบุคคล
     *
     * แยกเป็นบล็อกของตัวเองบนหน้านั้น ไม่รวมเข้ากับตัวเลขผลงานโครงการ เพราะ
     * ชั่วโมงงานปฏิบัติการไม่ใช่ผลงานโครงการ การรวมกันจะทำให้อัตราปิดงานและ
     * ความคืบหน้าของโครงการเพี้ยน สิ่งที่บล็อกนี้ตอบคือ "เวลาที่หายไปจาก
     * โครงการไปอยู่ที่ไหน" ซึ่งเป็นคนละคำถามกับ "ทำโครงการได้ดีแค่ไหน"
     *
     * @return array<string, mixed>
     */
    public function forEmployee(User $employee, string $startDate, string $endDate): array
    {
        $logs = WorkLog::query()
            ->with(['category', 'project:id,name', 'participants:id,name'])
            ->where('user_id', $employee->id)
            ->whereDate('work_date', '>=', $startDate)
            ->whereDate('work_date', '<=', $endDate)
            ->get();

        $minutes = $this->minutesOf($logs);
        $daily = $this->dailySummary($logs, ['start_date' => $startDate, 'end_date' => $endDate]);

        return [
            'minutes' => $minutes,
            'hours_label' => WorkLogDesign::durationLabel($minutes),
            'hours_value' => $this->hoursLabel($minutes),
            'count' => $logs->count(),
            'unlinked_minutes' => $this->minutesOf($logs->filter(
                fn (WorkLog $log): bool => $log->work_order_list_id === null && $log->job_id === null
            )),
            'kinds' => $this->kindSummary($logs),
            'chart' => [
                'labels' => $daily->pluck('label')->all(),
                'routine' => $daily->pluck('routine_hours')->all(),
                'interrupt' => $daily->pluck('interrupt_hours')->all(),
                'field' => $daily->pluck('field_hours')->all(),
            ],
            'rows' => $this->employeeRows($logs),
            'row_limit' => self::EMPLOYEE_ROW_LIMIT,
        ];
    }

    /**
     * แถวล่าสุดของตารางย่อ เรียงจากวันใหม่ไปเก่า
     *
     * จำกัดไว้ที่ EMPLOYEE_ROW_LIMIT เพราะบล็อกนี้เป็นภาพประกอบบนหน้ารายงาน
     * รายบุคคล ไม่ใช่รายการทั้งหมด ผู้ที่ต้องการดูครบมีปุ่มไปหน้ารายงานเต็ม
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return Collection<int, array<string, mixed>>
     */
    private function employeeRows(Collection $logs): Collection
    {
        return $logs
            ->sortByDesc([
                fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '',
                fn (WorkLog $log): int => $log->started_at?->getTimestamp() ?? 0,
            ])
            ->take(self::EMPLOYEE_ROW_LIMIT)
            ->map(fn (WorkLog $log): array => [
                'date' => $log->work_date,
                'kind' => $log->kind,
                'kind_label' => WorkLogDesign::kind($log->kind)['label'],
                'kind_tone' => WorkLogDesign::kind($log->kind)['tone'],
                'category' => $log->category?->name,
                'title' => $log->title,
                'duration_label' => WorkLogDesign::durationLabel($log->duration_minutes),
                'time_range' => $log->started_at === null
                    ? null
                    : TodayWorkspace::businessNow($log->started_at)->format('H:i'),
                'project' => $log->project?->name,
                'participants' => $log->participants->pluck('name')->all(),
            ])
            ->values();
    }

    /**
     * แถวสำหรับไฟล์ CSV
     *
     * @return Collection<int, WorkLog>
     */
    public function exportRows(Request $request, ?int $forcedDepartmentId = null): Collection
    {
        $departments = Department::query()
            ->when($forcedDepartmentId, fn ($query, int $id) => $query->whereKey($id))
            ->orderBy('department_name')
            ->get();

        $filters = $this->normalizeFilters(
            $request,
            $departments,
            WorkLogCategory::query()->selectable()->get(),
            $forcedDepartmentId
        );

        return $this->filteredLogs($filters)->sortBy([
            fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '',
            fn (WorkLog $log): int => $log->started_at?->getTimestamp() ?? 0,
        ])->values();
    }

    /**
     * @return Collection<int, WorkLog>
     */
    private function filteredLogs(array $filters): Collection
    {
        return WorkLog::query()
            ->with(['user:id,name,department_id', 'user.department:id,department_name', 'category', 'project:id,name', 'department:id,department_name'])
            // work_date เป็นวันของกรุงเทพอยู่แล้ว จึงเทียบกับสตริงวันที่ได้ตรง ๆ
            // ไม่ต้องแปลง timezone ทีละแถวใน SQL
            ->whereDate('work_date', '>=', $filters['start_date'])
            ->whereDate('work_date', '<=', $filters['end_date'])
            ->when($filters['kind'], fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when(
                $filters['category_id'],
                fn (Builder $query, int $id) => $query->where('work_log_category_id', $id)
            )
            ->when($filters['department_id'], fn (Builder $query, int $id) => $query
                ->where(fn (Builder $scoped) => $scoped
                    ->where('department_id', $id)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner->where('department_id', $id)))))
            ->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function memberSummary(Collection $logs): Collection
    {
        return $logs
            ->groupBy('user_id')
            ->map(function (Collection $group): array {
                $owner = $group->first()->user;
                $minutes = $this->minutesOf($group);

                return [
                    'id' => $group->first()->user_id,
                    'name' => $owner?->name ?? 'ไม่ทราบผู้บันทึก',
                    'department' => $owner?->department?->department_name ?? '—',
                    'minutes' => $minutes,
                    'hours' => $this->hours($minutes),
                    'hours_label' => WorkLogDesign::durationLabel($minutes),
                    'count' => $group->count(),
                    'routine' => $group->where('kind', 'routine')->count(),
                    'interrupt' => $group->where('kind', 'interrupt')->count(),
                    'field' => $group->where('kind', 'field')->count(),
                    'unlinked_minutes' => $this->minutesOf($group->filter(
                        fn (WorkLog $log): bool => $log->work_order_list_id === null && $log->job_id === null
                    )),
                ];
            })
            ->sortByDesc('minutes')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function categorySummary(Collection $logs): Collection
    {
        return $logs
            ->groupBy(fn (WorkLog $log): string => (string) ($log->work_log_category_id ?? 0))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $minutes = $this->minutesOf($group);

                return [
                    'id' => $first->work_log_category_id,
                    'name' => $first->category?->name ?? 'ไม่ระบุหมวด',
                    'tone' => $first->category?->tone ?? 'gray',
                    'minutes' => $minutes,
                    'hours' => $this->hours($minutes),
                    'hours_label' => WorkLogDesign::durationLabel($minutes),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('minutes')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function kindSummary(Collection $logs): Collection
    {
        return collect(WorkLogDesign::KINDS)->map(function (array $meta, string $key) use ($logs): array {
            $group = $logs->where('kind', $key);
            $minutes = $this->minutesOf($group);

            return [
                'key' => $key,
                ...$meta,
                'minutes' => $minutes,
                'hours' => $this->hours($minutes),
                'hours_label' => WorkLogDesign::durationLabel($minutes),
                'count' => $group->count(),
            ];
        })->values();
    }

    /**
     * สรุปรายวัน
     *
     * เดินวันทีละวันจากช่วงที่เลือก เพื่อให้กราฟมีจุดของวันที่ไม่มีข้อมูลด้วย
     * (ช่องว่างบอกอะไรได้เท่ากับแท่งสูง) แต่จำกัดไว้ไม่ให้ช่วงยาวเกินอ่านไหว
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function dailySummary(Collection $logs, array $filters): Collection
    {
        $start = CarbonImmutable::parse($filters['start_date'], self::BUSINESS_TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($filters['end_date'], self::BUSINESS_TIMEZONE)->startOfDay();

        // ช่วงยาวมากให้สรุปเป็นรายเดือนแทน ไม่งั้นแกนวันจะอ่านไม่ออก
        if ($start->diffInDays($end) > 62) {
            return $this->monthlySummary($logs, $start, $end);
        }

        $byDay = $logs->groupBy(fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '');
        $rows = collect();
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m-d');
            $group = $byDay->get($key, collect());

            // translatedFormat แปลชื่อเดือนตาม locale ส่วน format() คืนภาษาอังกฤษเสมอ
            // แกนวันของกราฟจึงเคยขึ้นเป็น "5 Sep" ปนอยู่กับข้อความไทยทั้งหน้า
            $rows->push($this->periodRow($cursor->locale('th')->translatedFormat('j M'), $key, $group));
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function monthlySummary(Collection $logs, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $byMonth = $logs->groupBy(fn (WorkLog $log): string => $log->work_date?->format('Y-m') ?? '');
        $rows = collect();
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $key = $cursor->format('Y-m');
            $group = $byMonth->get($key, collect());

            // ปีพุทธศักราชตามการแสดงผลของระบบ
            $rows->push($this->periodRow(
                $cursor->locale('th')->translatedFormat('M').' '.substr((string) ($cursor->year + 543), -2),
                $key,
                $group
            ));
            $cursor = $cursor->addMonth();
        }

        return $rows;
    }

    /**
     * @param  Collection<int, WorkLog>  $group
     * @return array<string, mixed>
     */
    private function periodRow(string $label, string $key, Collection $group): array
    {
        $routine = $this->minutesOf($group->where('kind', 'routine'));
        $interrupt = $this->minutesOf($group->where('kind', 'interrupt'));
        $field = $this->minutesOf($group->where('kind', 'field'));

        return [
            'key' => $key,
            'label' => $label,
            'minutes' => $routine + $interrupt + $field,
            'hours' => $this->hours($routine + $interrupt + $field),
            'routine_hours' => $this->hours($routine),
            'interrupt_hours' => $this->hours($interrupt),
            'field_hours' => $this->hours($field),
            'interrupt_count' => $group->where('kind', 'interrupt')->count(),
            'count' => $group->count(),
        ];
    }

    /**
     * งานที่ทำบ่อยที่สุด
     *
     * ตอบคำถาม "ที่ผ่านมาเราทำอะไรมาบ้าง" ซึ่งเป็นเหตุผลหนึ่งที่ต้องมีฟีเจอร์นี้
     * จัดกลุ่มด้วยชื่องานที่ตัดช่องว่างหัวท้ายแล้ว
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topTitles(Collection $logs): Collection
    {
        return $logs
            ->groupBy(fn (WorkLog $log): string => trim((string) $log->title))
            ->map(fn (Collection $group, string $title): array => [
                'title' => $title === '' ? 'ไม่ระบุชื่องาน' : $title,
                'count' => $group->count(),
                'minutes' => $this->minutesOf($group),
                'hours_label' => WorkLogDesign::durationLabel($this->minutesOf($group)),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(8);
    }

    private function minutesOf(Collection $logs): int
    {
        return (int) $logs->sum(fn (WorkLog $log): int => (int) ($log->duration_minutes ?? 0));
    }

    /** ชั่วโมงทศนิยมหนึ่งตำแหน่ง สำหรับใช้เป็นค่าในกราฟ */
    private function hours(int $minutes): float
    {
        return round($minutes / 60, 1);
    }

    private function hoursLabel(int $minutes): string
    {
        return number_format($minutes / 60, 1);
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @param  Collection<int, WorkLogCategory>  $categories
     * @return array<string, mixed>
     */
    private function normalizeFilters(
        Request $request,
        Collection $departments,
        Collection $categories,
        ?int $forcedDepartmentId
    ): array {
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $period = $request->string('period')->toString();
        $period = array_key_exists($period, self::PERIOD_LABELS) ? $period : self::DEFAULT_PERIOD;

        [$start, $end] = match ($period) {
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'last_3_months' => [$now->subMonths(2)->startOfMonth(), $now->endOfMonth()],
            'last_6_months' => [$now->subMonths(5)->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => $this->customRange($request, $now),
        };

        // หัวหน้าแผนกถูกบังคับขอบเขตไว้ที่แผนกตัวเอง จะเลือกแผนกอื่นไม่ได้
        $departmentId = $forcedDepartmentId ?: $request->integer('department');
        $departmentId = $departments->contains('id', $departmentId) ? (int) $departmentId : null;

        $kind = $request->string('kind')->toString();
        $kind = array_key_exists($kind, WorkLogDesign::KINDS) ? $kind : null;

        $categoryId = $request->integer('category');
        $categoryId = $categories->contains('id', $categoryId) ? (int) $categoryId : null;

        return [
            'period' => $period,
            'period_label' => self::PERIOD_LABELS[$period],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'department_id' => $departmentId,
            'kind' => $kind,
            'kind_label' => $kind === null ? 'ทุกประเภท' : WorkLogDesign::KINDS[$kind]['label'],
            'category_id' => $categoryId,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
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

        return $start->gt($end) ? [$end->startOfDay(), $start->endOfDay()] : [$start, $end];
    }

    /**
     * ชื่อผู้ใช้ที่รายงานนี้ครอบคลุม ใช้ประกอบหัวข้อ ไม่ได้ใช้กรอง
     */
    public function scopeLabel(array $filters, Collection $departments): string
    {
        if ($filters['department_id'] === null) {
            return 'ทุกแผนก';
        }

        return (string) ($departments->firstWhere('id', $filters['department_id'])?->department_name ?? 'ทุกแผนก');
    }

    /**
     * @return Collection<int, User>
     */
    public function reportableMembers(?int $forcedDepartmentId): Collection
    {
        return User::query()
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($forcedDepartmentId, fn (Builder $query, int $id) => $query->where('department_id', $id))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id']);
    }
}
