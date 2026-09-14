<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * รายงานภาระงานปฏิบัติการ — งานประจำและงานนอกสถานที่
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

    /** จำนวนแถวต่อหนึ่งหน้าของตารางการตรวจงานประจำ ค่าเดียวกับตารางรายคน */
    public const CHECKLIST_PAGE_SIZE = 10;

    /**
     * มุมมองงานประจำที่หัวหน้าใช้จริง
     *
     * ทุกค่าเป็นการเทียบ "เวลาจริง" กับ "เวลาตามแผน" ของรายการที่มาจากแม่แบบ
     * จึงต้องอยู่แยกจากตัวกรองสถานะ (open/in_progress/done/skipped) ที่ตอบคนละคำถาม
     */
    private const ROUTINE_FOCUSES = [
        'on_time' => 'ทำเสร็จตรงเวลา',
        'late_start' => 'เริ่มช้า',
        'late_completion' => 'เสร็จเกินเวลา',
        'skipped' => 'ไม่ได้ทำ',
        'unclosed' => 'ยังไม่ปิดรายการ',
    ];

    private const PERIOD_LABELS = [
        'this_month' => 'เดือนนี้',
        'last_month' => 'เดือนที่แล้ว',
        'last_3_months' => '3 เดือนล่าสุด',
        'last_6_months' => '6 เดือนล่าสุด',
        'this_year' => 'ปีนี้',
        'custom' => 'กำหนดเอง',
    ];

    /**
     * @param  int|null  $forcedOwnerId  บังคับให้เห็นเฉพาะบันทึกของคนนี้ (พนักงานทั่วไป)
     */
    public function build(Request $request, ?int $forcedDepartmentId = null, ?int $forcedOwnerId = null): array
    {
        $departments = Department::query()
            ->when($forcedDepartmentId, fn ($query, int $id) => $query->whereKey($id))
            ->orderBy('department_name')
            ->get();

        $categories = WorkLogCategory::query()->selectable()->get();
        $owners = $this->ownerOptions($forcedDepartmentId, $forcedOwnerId);
        $routines = $this->routineOptions($forcedDepartmentId, $forcedOwnerId);
        $projects = $this->projectOptions($forcedDepartmentId, $forcedOwnerId);
        $filters = $this->normalizeFilters($request, $departments, $categories, $owners, $routines, $projects, $forcedDepartmentId, $forcedOwnerId);
        $logs = $this->filteredLogs($filters);

        $memberSummary = $this->memberSummary($logs);
        $categorySummary = $this->categorySummary($logs);
        $dailySummary = $this->dailySummary($logs, $filters);
        $kindSummary = $this->kindSummary($logs);

        $routineCompliance = $this->memberDailyBoard($logs, $owners, $filters);
        $routineDays = $this->routineDays($logs, $filters['owner_id']);

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
                'owners' => $owners,
                'statuses' => collect(WorkLogDesign::STATUSES)->only(['open', 'in_progress', 'overdue', 'done', 'skipped'])->all(),
                'routineFocuses' => self::ROUTINE_FOCUSES,
                'routines' => $routines,
                'projects' => $projects,
            ],
            'totalMinutes' => $totalMinutes,
            'totalHoursLabel' => $this->hoursLabel($totalMinutes),
            'totalCount' => $logs->count(),
            'routineCount' => $logs->whereNotNull('work_log_template_id')->count(),
            'routineDoneCount' => $logs->whereNotNull('work_log_template_id')->where('status', 'done')->count(),
            'routineSkippedCount' => $logs->whereNotNull('work_log_template_id')->where('status', 'skipped')->count(),
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
            /*
             * หน้านี้ทำงานเป็นสองจังหวะ: เลือกคน แล้วค่อยดูรายวันของคนนั้น
             *
             * routineCompliance = รายชื่อลูกทีมพร้อมอัตราทำงานประจำ ใช้เป็นหน้าแรก
             * routineDays       = รายวันของคนที่ถูกเลือก ว่างเสมอเมื่อยังไม่เลือกใคร
             */
            'routineCompliance' => $routineCompliance,
            'routineDays' => $routineDays,
            'selectedOwner' => $filters['owner_id']
                ? $owners->firstWhere('id', $filters['owner_id'])
                : null,
            // ตารางสถานะของ "วันนี้" อ่านข้อมูลของวันนี้เสมอ ไม่ขึ้นกับช่วงเวลา
            // ที่เลือกไว้ในตัวกรอง เพราะคำถามที่มันตอบคือ "วันนี้ตรวจไปหรือยัง"
            // ซึ่งเป็นคนละคำถามกับสรุปย้อนหลังของทั้งเดือน
            'todayChecklist' => $this->routineChecklist($filters),
            'routineSummary' => $this->routineSummary($logs),
            'chartData' => [
                /*
                 * กราฟใบเดียวของหน้านี้ — งานของ "วันนี้" รายคน แยกทำแล้ว/ยังไม่เสร็จ
                 *
                 * เป็นข้อมูลชุดเดียวกับที่การ์ดด้านล่างแสดง เรียงลำดับเดียวกัน และ
                 * นับหน่วยเดียวกัน (รายการ) กราฟจึงเป็นภาพรวมของกระดานนั้นจริง ๆ
                 * ไม่ใช่ตัวเลขคนละชุดที่บังเอิญอยู่หน้าเดียวกัน — กดจากแท่งไหนก็หา
                 * การ์ดใบนั้นเจอโดยไม่ต้องแปลงหน่วยในหัว
                 *
                 * ของเดิมเป็นอัตราสะสมทั้งช่วง ซึ่งตอบคนละคำถามกับกระดานที่ถามว่า
                 * "วันนี้ใครทำอะไรไปแล้วบ้าง" และอ่านเทียบกันไม่ได้เพราะคนละหน่วย
                 */
                'todayMembers' => [
                    'labels' => $routineCompliance->pluck('name')->all(),
                    'done' => $routineCompliance->pluck('today_done')->all(),
                    'pending' => $routineCompliance
                        ->map(fn (array $person): int => max(0, $person['today_total'] - $person['today_done']))
                        ->all(),
                    'highlight' => $routineCompliance
                        ->map(fn (array $person): int => (int) ($person['id'] === $filters['owner_id']))
                        ->all(),
                ],
                // ชั่วโมงงานตามประเภทต่อวัน เปรียบเทียบงานประจำกับงานนอกสถานที่
                'daily' => [
                    'labels' => $dailySummary->pluck('label')->all(),
                    'routine' => $dailySummary->pluck('routine_hours')->all(),
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
            ],
        ];
    }

    /**
     * แถวสำหรับไฟล์ CSV
     *
     * @return Collection<int, WorkLog>
     */
    public function exportRows(Request $request, ?int $forcedDepartmentId = null, ?int $forcedOwnerId = null): Collection
    {
        $departments = Department::query()
            ->when($forcedDepartmentId, fn ($query, int $id) => $query->whereKey($id))
            ->orderBy('department_name')
            ->get();

        $owners = $this->ownerOptions($forcedDepartmentId, $forcedOwnerId);
        // CSV ต้องเคารพตัวกรองชุดเดียวกับหน้าจอทุกตัว รวมทั้งมุมมองงานประจำ
        // ไม่งั้นไฟล์ที่โหลดออกไปจะไม่ตรงกับตัวเลขที่หัวหน้าเห็นตอนกดปุ่ม
        $filters = $this->normalizeFilters(
            $request,
            $departments,
            WorkLogCategory::query()->selectable()->get(),
            $owners,
            $this->routineOptions($forcedDepartmentId, $forcedOwnerId),
            $this->projectOptions($forcedDepartmentId, $forcedOwnerId),
            $forcedDepartmentId,
            $forcedOwnerId
        );

        return $this->filteredLogs($filters)->sortBy([
            fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '',
            fn (WorkLog $log): int => $log->started_at?->getTimestamp() ?? 0,
        ])->values();
    }

    /**
     * ตารางการตรวจงานประจำ — ตรวจอะไรไปแล้วบ้าง วันไหน และใครตรวจ
     *
     * ทำหน้าที่สองอย่างในตารางเดียว:
     *
     * 1. ตอบคำถามของเช้าวันนี้ — "เหลืออะไรที่ยังไม่ได้ตรวจ" ผ่านตัวนับของวันนี้
     *    ที่หัวตาราง และแถวของวันนี้ที่ถูกดันขึ้นบนสุด
     * 2. เป็นหลักฐานย้อนหลัง — เวลามีคนขอดูว่าที่ผ่านมาทำอะไรไปบ้าง ตารางนี้คือ
     *    สิ่งที่หยิบให้ดูได้ทันที จึงต้องมีคอลัมน์วันที่ ไม่ใช่แสดงแค่ของวันนี้
     *
     * นับเฉพาะรายการที่มาจากแม่แบบ (source = template) เพราะ "ตรวจแล้วหรือยัง"
     * มีความหมายกับงานที่ถูกกำหนดไว้ล่วงหน้าเท่านั้น งานที่บันทึกครั้งเดียว
     * ไม่มีสถานะ "ยังไม่ได้ตรวจ" ให้ติดตาม
     *
     * แถวมาจากช่วงเวลาที่เลือกในตัวกรอง ยกเว้นตัวนับที่หัวตารางซึ่งเป็นของวันนี้
     * เสมอ (คำถามของวันนี้ไม่ควรเปลี่ยนไปตามช่วงที่เลือกดูย้อนหลัง)
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function routineChecklist(array $filters): array
    {
        $today = TodayWorkspace::businessNow()->startOfDay();
        $todayKey = $today->format('Y-m-d');

        $logs = WorkLog::query()
            ->with(['user:id,name,department_id', 'user.department:id,department_name', 'category', 'template'])
            ->where('source', 'template')
            // ช่วงเดียวกับตัวกรองของทั้งรายงาน แต่รวมวันนี้เสมอ เพราะตัวนับที่หัว
            // ตารางเป็นของวันนี้ ถ้าไม่ดึงมาด้วยตัวเลขจะเป็นศูนย์ทั้งที่มีงานอยู่
            ->where(fn (Builder $scoped) => $scoped
                ->where(fn (Builder $range) => $range
                    ->whereDate('work_date', '>=', $filters['start_date'])
                    ->whereDate('work_date', '<=', $filters['end_date']))
                ->orWhereDate('work_date', $todayKey))
            ->when($filters['owner_id'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['kind'], fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['status'] && $filters['status'] !== 'overdue', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['status'] === 'overdue', fn (Builder $query) => $query
                ->whereIn('status', ['open', 'in_progress'])
                ->whereNotNull('planned_end_at')
                ->where('planned_end_at', '<', TodayWorkspace::businessNow()->utc()))
            ->when($filters['category_id'], fn (Builder $query, int $id) => $query->where('work_log_category_id', $id))
            ->when($filters['department_id'], fn (Builder $query, int $id) => $query
                ->where(fn (Builder $scoped) => $scoped
                    ->where('department_id', $id)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner->where('department_id', $id)))))
            ->get();

        $rows = $logs
            ->map(function (WorkLog $log) use ($todayKey): array {
                $date = $log->work_date;
                $isDone = $log->status === 'done';
                $now = TodayWorkspace::businessNow()->utc();
                $isOverdue = in_array($log->status, ['open', 'in_progress'], true)
                    && $log->planned_end_at !== null
                    && $now->greaterThan($log->planned_end_at);
                $displayStatus = $isOverdue ? 'overdue' : $log->status;

                return [
                    'id' => $log->id,
                    'date' => $date?->format('Y-m-d'),
                    'date_label' => $date === null ? '—' : TodayWorkspace::dateRangeLabel($date, $date),
                    'is_today' => $date?->format('Y-m-d') === $todayKey,
                    'title' => $log->title,
                    'owner' => $log->user?->name ?? 'ไม่ทราบผู้รับผิดชอบ',
                    'category' => $log->category?->name,
                    // ช่วงเวลาที่ตั้งไว้ว่าต้องเข้าไปทำ เช่น "08:30 - 08:50"
                    'window' => $log->template?->plannedWindowLabel(),
                    'is_done' => $isDone,
                    'status' => $displayStatus,
                    'status_label' => WorkLogDesign::status($displayStatus)['label'],
                    'reason' => $log->skip_reason ?: ($log->late_completion_reason ?: $log->late_start_reason),
                    // เวลาที่กดยืนยัน ใช้ ended_at ซึ่งเป็นเวลาที่งานถูกปิดจริง
                    'done_at' => $isDone && $log->ended_at !== null
                        ? TodayWorkspace::businessNow($log->ended_at)->format('H:i')
                        : null,
                ];
            })
            ->sortBy([
                // วันล่าสุดอยู่บนสุด — เรียงจากน้อยไปมากด้วยค่าติดลบของวันที่
                // แทนการเรียงสองรอบ ซึ่งอ่านยากและขึ้นกับความเสถียรของการเรียง
                fn (array $row): int => -(int) str_replace('-', '', (string) $row['date']),
                // ในวันเดียวกัน สิ่งที่ยังไม่ตรวจมาก่อนเสมอ เพราะเป็นแถวเดียว
                // ในตารางที่ยังต้องลงมือต่อ
                fn (array $row): int => $row['is_done'] ? 1 : 0,
                fn (array $row): string => $row['window'] ?? 'zz',
                fn (array $row): string => $row['owner'],
            ])
            ->values();

        $todayRows = $rows->where('is_today', true);
        $todayDone = $todayRows->where('is_done', true)->count();

        return [
            'date_label' => TodayWorkspace::dateRangeLabel($today, $today),
            'rows' => $rows,
            'total' => $rows->count(),
            'today_total' => $todayRows->count(),
            'today_done' => $todayDone,
            'today_pending' => $todayRows->count() - $todayDone,
            'today_running' => $todayRows->where('status', 'in_progress')->count(),
            'today_overdue' => $todayRows->where('status', 'overdue')->count(),
            'today_skipped' => $todayRows->where('status', 'skipped')->count(),
            // วันนี้ตรวจครบหรือยัง — ใช้ตัดสินสีของตัวนับที่หัวตาราง
            'is_complete' => $todayRows->isNotEmpty() && $todayDone === $todayRows->count(),
            // จำนวนแถวต่อหนึ่งหน้าของตาราง ใช้ทั้ง Blade และ table-pager.js
            'page_size' => self::CHECKLIST_PAGE_SIZE,
        ];
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
            // ขอบเขตของพนักงานทั่วไป — บังคับจากฝั่งเซิร์ฟเวอร์ ไม่ใช่ค่าจาก query string
            ->when($filters['owner_id'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['kind'], fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['status'] && $filters['status'] !== 'overdue', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['status'] === 'overdue', fn (Builder $query) => $query
                ->whereIn('status', ['open', 'in_progress'])
                ->whereNotNull('planned_end_at')
                ->where('planned_end_at', '<', TodayWorkspace::businessNow()->utc()))
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
            ->when($filters['routine_id'], fn (Builder $query, int $id) => $query->where('work_log_template_id', $id))
            ->when($filters['project_id'], fn (Builder $query, int $id) => $query->where('work_order_list_id', $id))
            ->when($filters['routine_focus'], fn (Builder $query, string $focus) => $this->applyRoutineFocus($query, $focus))
            ->get();
    }

    /**
     * ตัวกรอง "มุมมองงานประจำ"
     *
     * ทุกเงื่อนไขจำกัดเฉพาะรายการที่มาจากแม่แบบ เพราะงานที่ผู้ใช้บันทึกเองไม่มีเวลาตามแผน
     * ให้เทียบ การเอามารวมจะทำให้ตัวเลข "เริ่มช้า" หรือ "ยังไม่ปิด" อ่านผิดทันที
     */
    private function applyRoutineFocus(Builder $query, string $focus): Builder
    {
        $now = TodayWorkspace::businessNow()->utc();

        return $query
            ->whereNotNull('work_log_template_id')
            ->when($focus === 'on_time', fn (Builder $scoped) => $scoped
                ->where('status', 'done')
                ->whereNull('late_start_reason')
                ->whereNull('late_completion_reason'))
            ->when($focus === 'late_start', fn (Builder $scoped) => $scoped->whereNotNull('late_start_reason'))
            ->when($focus === 'late_completion', fn (Builder $scoped) => $scoped->whereNotNull('late_completion_reason'))
            ->when($focus === 'skipped', fn (Builder $scoped) => $scoped->where('status', 'skipped'))
            // "ยังไม่ปิด" คือรายการที่เลยเวลาตามแผนแล้วแต่ยังไม่ถูกกดเสร็จหรือกดไม่ได้ทำ
            ->when($focus === 'unclosed', fn (Builder $scoped) => $scoped
                ->whereIn('status', ['open', 'in_progress'])
                ->where(fn (Builder $passed) => $passed
                    ->whereNull('planned_end_at')
                    ->orWhere('planned_end_at', '<', $now)));
    }

    /**
     * สรุปงานประจำของช่วงที่เลือก
     *
     * นับจากรายการที่มาจากแม่แบบเท่านั้น และใช้เหตุผลที่ผู้ใช้เลือกไว้เป็นตัวชี้ขาด
     * ว่าเริ่มช้าหรือเสร็จเกินเวลา ไม่ใช่การคำนวณเวลาซ้ำที่นี่ เพื่อให้ตัวเลขในรายงาน
     * ตรงกับสิ่งที่ผู้ใช้เห็นและยืนยันไว้บนการ์ดในหน้าบันทึกงานประจำวัน
     *
     * @return array<string, mixed>
     */
    /**
     * กระดานของหัวหน้าแผนก — "วันนี้ใครทำอะไรไปแล้วบ้าง"
     *
     * หัวหน้าเปิดหน้านี้ตอนเช้าเพื่อเช็คว่าลูกทีมเริ่มงานของวันนี้แล้วหรือยัง คำถามจึงเป็น
     * เรื่อง "วันนี้" เป็นหลัก ส่วนช่วงเวลาที่เลือกไว้เป็นบริบทว่า "ที่ผ่านมาทำได้แค่ไหน"
     *
     * นับงานทั้งสองชนิด (งานประจำ และงานนอกสถานที่) ไม่ใช่เฉพาะงานที่มาจากแม่แบบ
     * ของเดิมนับเฉพาะแม่แบบ คนที่ลงแต่งานนอกสถานที่จึงขึ้นศูนย์ทั้งแถวทั้งที่ทำงานอยู่จริง
     *
     * @param  Collection<int, WorkLog>  $logs  บันทึกในช่วงที่เลือก
     * @param  Collection<int, User>  $owners  ทุกคนในขอบเขต ไม่ใช่เฉพาะคนที่มีบันทึก
     * @return Collection<int, array<string, mixed>>
     */
    private function memberDailyBoard(Collection $logs, Collection $owners, array $filters): Collection
    {
        $today = $this->todayLogs($filters)->groupBy('user_id');
        $period = $logs->groupBy('user_id');

        /*
         * ตั้งต้นจาก "ทุกคนในขอบเขต" ไม่ใช่จากบันทึกที่มีอยู่
         *
         * ถ้าไล่จากบันทึก คนที่ยังไม่ได้ลงงานเลยจะหายไปจากรายชื่อทั้งคน ซึ่งเป็นคนที่
         * หัวหน้าต้องการเห็นมากที่สุด เพราะ "ยังไม่ได้ลงอะไรเลย" คือคำตอบของคำถาม
         */
        return $owners
            ->map(function (User $owner) use ($today, $period): array {
                $todayLogs = $today->get($owner->id, collect());
                $periodLogs = $period->get($owner->id, collect());
                $periodDone = $periodLogs->where('status', 'done')->count();
                $periodSkipped = $periodLogs->where('status', 'skipped')->count();
                $periodTotal = $periodLogs->count();

                return [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'department' => $owner->department?->department_name ?? '—',
                    'today_total' => $todayLogs->count(),
                    'today_done' => $todayLogs->where('status', 'done')->count(),
                    'today_pending' => $todayLogs
                        ->whereIn('status', ['open', 'in_progress'])
                        ->count(),
                    // เวลาที่ลงแรงไปจริง ไม่ใช่แค่จำนวนรายการ — งานห้ารายการสั้น ๆ
                    // กับงานรายการเดียวที่กินทั้งเช้า ไม่ควรอ่านว่าเท่ากัน
                    'today_minutes_label' => WorkLogDesign::durationLabel($this->minutesOf($todayLogs)),
                    // รายการของวันนี้ทีละตัว หัวหน้าจึงตอบได้ว่า "ค้างอยู่รายการไหน" โดยไม่ต้องกดเข้าไป
                    'today_items' => $todayLogs
                        ->sortBy(fn (WorkLog $log): string => $log->planned_start_at?->toDateTimeString() ?? '')
                        ->map(fn (WorkLog $log): array => [
                            'title' => $log->title,
                            'minutes_label' => WorkLogDesign::durationLabel($log->duration_minutes),
                            'kind' => $log->kind,
                            'kind_label' => WorkLogDesign::KINDS[$log->kind]['label'] ?? $log->kind,
                            'status' => $log->status,
                            'status_label' => WorkLogDesign::status($log->status)['label'],
                            'is_done' => $log->status === 'done',
                        ])
                        ->values()
                        ->all(),
                    'period_total' => $periodTotal,
                    'period_done' => $periodDone,
                    'period_minutes_label' => WorkLogDesign::durationLabel($this->minutesOf($periodLogs)),
                    'period_minutes' => $this->minutesOf($periodLogs),
                    /*
                     * รายละเอียดเบื้องหลังตัวเลขเวลา — งานชื่อเดียวกันทำไปกี่ครั้ง รวมกี่ชั่วโมง
                     *
                     * ตัวเลขรวมอย่างเดียวตอบได้แค่ "เยอะหรือน้อย" แต่คำถามถัดไปของหัวหน้า
                     * คือ "เวลาหมดไปกับอะไร" จึงต้องกางให้ดูได้ในที่เดียวกัน ไม่ใช่ต้องเปิดหน้าใหม่
                     */
                    'time_breakdown' => $this->timeBreakdown($periodLogs),
                    // อัตราเดียวกับ routineSummary(): ปิดรายการแล้วถือว่าตอบแล้ว
                    // ไม่ว่าจะทำเสร็จหรือระบุว่าไม่ได้ทำ ทั้งสองอย่างคือการรายงานผล
                    'rate' => $periodTotal > 0
                        ? (int) round((($periodDone + $periodSkipped) / $periodTotal) * 100)
                        : 0,
                ];
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * บันทึกของ "วันนี้" ตามขอบเขตเดียวกับรายงาน แต่ไม่ผูกกับช่วงเวลาที่เลือก
     *
     * คำถาม "วันนี้ใครทำแล้วบ้าง" เป็นคนละคำถามกับสรุปย้อนหลัง ถ้าใช้ช่วงเวลาเดียวกัน
     * การเลือกดู "เดือนที่แล้ว" จะทำให้คอลัมน์ของวันนี้กลายเป็นศูนย์ทั้งกระดาน
     *
     * @return Collection<int, WorkLog>
     */
    private function todayLogs(array $filters): Collection
    {
        $today = TodayWorkspace::businessNow()->format('Y-m-d');

        return WorkLog::query()
            ->with(['user:id,name,department_id'])
            ->whereDate('work_date', $today)
            ->when($filters['owner_id'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['department_id'], fn (Builder $query, int $id) => $query
                ->where(fn (Builder $scoped) => $scoped
                    ->where('department_id', $id)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner->where('department_id', $id)))))
            ->get();
    }

    /**
     * งานประจำรายวันของคนคนเดียว — หนึ่งแถวต่อหนึ่งวันที่มีงานประจำจริง
     *
     * ไม่เติมวันว่างให้ครบช่วงเหมือน dailySummary() เพราะตารางนี้แบ่งหน้าละสิบแถว
     * วันหยุดที่ไม่มีงานประจำเลยจะกินโควตาหน้าไปเปล่า ๆ และไม่ได้ตอบอะไร
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function routineDays(Collection $logs, ?int $ownerId): Collection
    {
        return $logs
            /*
             * ไม่เลือกคน = รวมทั้งขอบเขต ซึ่งเป็นข้อมูลของกราฟแนวโน้มบนหน้ารายชื่อ
             * ส่วนตารางรายวันยังแสดงเฉพาะตอนเลือกคนแล้ว (ตัดสินที่ Blade)
             * นับงานทั้งสองชนิด ไม่ใช่เฉพาะที่มาจากแม่แบบ ให้ตรงกับกระดานของหัวหน้า
             */
            ->filter(fn (WorkLog $log): bool => $ownerId === null || (int) $log->user_id === $ownerId)
            ->groupBy(fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '')
            ->reject(fn (Collection $group, string $key): bool => $key === '')
            ->map(function (Collection $group, string $key): array {
                $total = $group->count();
                $done = $group->where('status', 'done')->count();
                $skipped = $group->where('status', 'skipped')->count();

                return [
                    'date' => $key,
                    'label' => CarbonImmutable::parse($key, self::BUSINESS_TIMEZONE)
                        ->locale('th')->translatedFormat('j M Y'),
                    'minutes_label' => WorkLogDesign::durationLabel($this->minutesOf($group)),
                    'total' => $total,
                    'done' => $done,
                    'skipped' => $skipped,
                    'pending' => max(0, $total - $done - $skipped),
                ];
            })
            ->sortByDesc('date')
            ->values();
    }

    private function routineSummary(Collection $logs): array
    {
        $now = TodayWorkspace::businessNow();
        $routines = $logs->filter(fn (WorkLog $log): bool => $log->work_log_template_id !== null);
        $total = $routines->count();
        $done = $routines->where('status', 'done');
        $lateStart = $routines->filter(fn (WorkLog $log): bool => $log->late_start_reason !== null);
        $lateCompletion = $routines->filter(fn (WorkLog $log): bool => $log->late_completion_reason !== null);
        $skipped = $routines->where('status', 'skipped');
        $unclosed = $routines->filter(fn (WorkLog $log): bool => in_array($log->status, ['open', 'in_progress'], true)
            && ($log->planned_end_at === null || $now->greaterThan($log->planned_end_at)));
        $onTime = $done->filter(fn (WorkLog $log): bool => $log->late_start_reason === null
            && $log->late_completion_reason === null);
        $doneMinutes = $this->minutesOf($done);

        return [
            'total' => $total,
            'on_time' => $onTime->count(),
            'late_start' => $lateStart->count(),
            'late_completion' => $lateCompletion->count(),
            'skipped' => $skipped->count(),
            'unclosed' => $unclosed->count(),
            'average_minutes' => $done->count() > 0 ? (int) round($doneMinutes / $done->count()) : 0,
            // "อัตราการทำครบ" นับรายการที่ถูกปิดจริง ไม่ว่าจะเสร็จหรือระบุว่าไม่ได้ทำ
            // เพราะทั้งสองอย่างคือการที่ผู้ปฏิบัติงานตอบแล้วว่าเกิดอะไรขึ้นกับรายการนั้น
            'completion_rate' => $total > 0
                ? (int) round((($done->count() + $skipped->count()) / $total) * 100)
                : 0,
        ];
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
        $field = $this->minutesOf($group->where('kind', 'field'));

        return [
            'key' => $key,
            'label' => $label,
            'minutes' => $routine + $field,
            'hours' => $this->hours($routine + $field),
            'routine_hours' => $this->hours($routine),
            'field_hours' => $this->hours($field),
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
            ->take(10);
    }

    /**
     * เวลาที่ใช้ไปแยกตามชื่องาน เรียงจากงานที่กินเวลามากที่สุด
     *
     * รวมตามชื่อ ไม่ใช่ตามแม่แบบ เพราะงานนอกสถานที่ไม่มีแม่แบบให้ยึด และสิ่งที่หัวหน้า
     * จำได้คือชื่องาน จำกัดไว้ไม่เกินสิบบรรทัดเพื่อให้ยังอ่านจบได้ในกล่องเดียว
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    private function timeBreakdown(Collection $logs): array
    {
        return $logs
            ->groupBy(fn (WorkLog $log): string => $log->title ?? '—')
            ->map(fn (Collection $group, string $title): array => [
                'title' => $title,
                'times' => $group->count(),
                'minutes' => $this->minutesOf($group),
                'minutes_label' => WorkLogDesign::durationLabel($this->minutesOf($group)),
            ])
            ->sortByDesc('minutes')
            ->take(10)
            ->values()
            ->all();
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
        Collection $owners,
        Collection $routines,
        Collection $projects,
        ?int $forcedDepartmentId,
        ?int $forcedOwnerId = null
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

        $ownerId = $forcedOwnerId ?: $request->integer('owner');
        $ownerId = $owners->contains('id', $ownerId) ? (int) $ownerId : null;

        $status = $request->string('status')->toString();
        $status = array_key_exists($status, WorkLogDesign::STATUSES) ? $status : null;

        // มุมมองงานประจำ — คำถามที่หัวหน้าถามจริงคือ "ใครเริ่มช้า ใครเกินเวลา ใครไม่ได้ทำ"
        // ซึ่งตอบด้วยคอลัมน์สถานะอย่างเดียวไม่ได้ เพราะเป็นการเทียบเวลาจริงกับเวลาตามแผน
        $routineFocus = $request->string('routine_focus')->toString();
        $routineFocus = array_key_exists($routineFocus, self::ROUTINE_FOCUSES) ? $routineFocus : null;

        $routineId = $request->integer('routine');
        $routineId = $routines->contains('id', $routineId) ? (int) $routineId : null;

        $projectId = $request->integer('project');
        $projectId = $projects->contains('id', $projectId) ? (int) $projectId : null;

        return [
            'period' => $period,
            'period_label' => self::PERIOD_LABELS[$period],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'department_id' => $departmentId,
            // ไม่มีทางตั้งค่านี้จาก request ได้เลย พนักงานทั่วไปจึงเปลี่ยนไปดู
            // ของคนอื่นด้วยการแก้ URL ไม่ได้
            'owner_id' => $ownerId,
            'kind' => $kind,
            'kind_label' => $kind === null ? 'ทุกประเภท' : WorkLogDesign::KINDS[$kind]['label'],
            'category_id' => $categoryId,
            'status' => $status,
            'routine_focus' => $routineFocus,
            'routine_focus_label' => $routineFocus === null ? 'งานประจำทั้งหมด' : self::ROUTINE_FOCUSES[$routineFocus],
            'routine_id' => $routineId,
            'project_id' => $projectId,
        ];
    }

    private function ownerOptions(?int $forcedDepartmentId, ?int $forcedOwnerId): Collection
    {
        return User::query()
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($forcedDepartmentId, fn (Builder $query, int $id) => $query->where('department_id', $id))
            ->when($forcedOwnerId, fn (Builder $query, int $id) => $query->whereKey($id))
            ->orderBy('name')
            // โหลดแผนกมาพร้อมกัน คอลัมน์ "แผนก" ของตารางรายชื่อจึงไม่ยิงคิวรีทีละแถว
            ->with('department:id,department_name')
            ->get(['id', 'name', 'department_id']);
    }

    /**
     * แม่แบบงานประจำที่อยู่ในขอบเขตของผู้ดูรายงาน
     *
     * ใช้เป็นตัวเลือกของตัวกรอง "งานประจำ" — ต้องจำกัดด้วยขอบเขตเดียวกับรายการงาน
     * ไม่งั้นชื่อแม่แบบของแผนกอื่นจะรั่วออกมาในกล่องตัวเลือก ทั้งที่ข้อมูลถูกกรองแล้ว
     *
     * @return Collection<int, WorkLogTemplate>
     */
    private function routineOptions(?int $forcedDepartmentId, ?int $forcedOwnerId): Collection
    {
        return WorkLogTemplate::query()
            ->when($forcedOwnerId, fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when(
                $forcedDepartmentId && ! $forcedOwnerId,
                fn (Builder $query) => $query->whereHas('user', fn (Builder $owner) => $owner->where('department_id', $forcedDepartmentId))
            )
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    /**
     * โปรเจกต์ที่ปรากฏในบันทึกงานประจำวันของขอบเขตนี้
     *
     * ดึงจากบันทึกจริง ไม่ใช่รายชื่อโปรเจกต์ทั้งระบบ เพื่อไม่ให้ตัวเลือกยาวเป็นร้อยรายการ
     * โดยที่เกือบทั้งหมดกรองแล้วได้ผลลัพธ์ว่าง
     *
     * @return Collection<int, WorkOrderList>
     */
    private function projectOptions(?int $forcedDepartmentId, ?int $forcedOwnerId): Collection
    {
        $projectIds = WorkLog::query()
            ->whereNotNull('work_order_list_id')
            ->when($forcedOwnerId, fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when(
                $forcedDepartmentId && ! $forcedOwnerId,
                fn (Builder $query) => $query->where(fn (Builder $scoped) => $scoped
                    ->where('department_id', $forcedDepartmentId)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner->where('department_id', $forcedDepartmentId))))
            )
            ->distinct()
            ->pluck('work_order_list_id');

        return WorkOrderList::query()
            ->whereIn('id', $projectIds)
            ->orderBy('name')
            ->get(['id', 'name']);
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
