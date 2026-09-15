<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\OperationalReportScope;
use App\Support\ReportMonth;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * รายงานปฏิบัติงานประจำเดือน — ภาพรวมทีมและรายบุคคล
 *
 * แยกออกจากรายงานผลงานโครงการโดยเด็ดขาด ตัวเลขจากบันทึกงานประจำวันต้องไม่ไหลเข้าไป
 * ใน KPI ของโครงการ (ดู test_project_report_numbers_do_not_change_when_work_logs_exist)
 *
 * ทุกส่วนของรายงาน — KPI กราฟ ตาราง หน้า detail และ CSV — อ่านจากบันทึกของเดือนและคน
 * ที่ถูกตัดสินใน scope() ครั้งเดียว หน้าจอกับไฟล์ที่ดาวน์โหลดจึงตรงกันโดยโครงสร้าง
 *
 * เรื่องฐานข้อมูล: work_date เป็นวันของกรุงเทพอยู่แล้ว การกรองเดือนจึงเทียบสตริงวันที่ได้
 * ตรง ๆ ไม่ต้องใช้ฟังก์ชันวันที่ของ SQL ซึ่งเขียนไม่เหมือนกันระหว่าง SQLite กับ MySQL
 */
final class OperationalWorkloadReportService
{
    public const BUSINESS_TIMEZONE = TodayWorkspace::BUSINESS_TIMEZONE;

    /** จำนวนแถวของการ์ด Top ในหน้า Overview */
    public const OVERVIEW_LIMIT = 5;

    public const DAILY_HEADERS = ['วันที่', 'รายการงาน', 'ประเภทงาน', 'หมวดงาน', 'สถานะ', 'ชั่วโมง', 'หมายเหตุ'];

    public const FREQUENT_HEADERS = ['อันดับ', 'ลักษณะงาน', 'จำนวนครั้ง', 'เวลาที่ใช้'];

    public const DELAY_HEADERS = ['วันที่', 'รายการงาน', 'ประเภทงาน', 'หมวดงาน', 'ประเภทเหตุผล', 'เหตุผล', 'สถานะ'];

    /**
     * ประเภทของเหตุการณ์ล่าช้า — มาจากคอลัมน์เหตุผลที่ผู้ปฏิบัติงานระบุไว้จริง
     * ยกเว้น overdue ซึ่งเป็นงานประจำที่เลยเวลาแล้วแต่ยังไม่มีใครตอบว่าเกิดอะไรขึ้น
     */
    public const DELAY_TYPES = [
        'late_start' => 'เริ่มช้า',
        'late_completion' => 'เสร็จเกินเวลา',
        'skipped' => 'ไม่ได้ทำ',
        'overdue' => 'เกินกำหนด',
        // ปิดรอบ 17:00 — แยกสามกรณี ไม่รวมกับ "ไม่ได้ทำ" และไม่รวมกันเอง
        'not_started' => 'ไม่ได้เริ่ม',
        'unfinished' => 'เริ่มแล้วไม่กดเสร็จ',
        'absent' => 'ไม่มา',
    ];

    public const UNSPECIFIED_REASON = 'ยังไม่ระบุเหตุผล';

    /**
     * สถานะงานประจำของวันนี้รายคน บนภาพรวมทีม — เรียงจากสิ่งที่หัวหน้าต้องดูก่อน
     */
    public const TODAY_STATUSES = TodayOperationalStatus::STATUSES;

    /**
     * สีของสถานะในตาราง — เขียว/ส้ม/แดงใช้สื่อความหมายเท่านั้น
     * รอเริ่มเป็นสีกลาง และกำลังทำเป็นสีน้ำเงินของระบบ
     */
    private const STATUS_TONES = [
        'done' => 'green',
        'in_progress' => 'blue',
        'open' => 'slate',
        'skipped' => 'amber',
        'cancelled' => 'slate',
        'overdue' => 'red',
        'not_started' => 'red',
        'unfinished' => 'amber',
        'absent' => 'red',
    ];

    private const WEEKDAY_SHORT = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

    /** @var array<string, Collection<int, WorkLog>> */
    private array $logCache = [];

    public function __construct(private readonly TodayOperationalStatus $today) {}

    /**
     * ตัดสินเดือนและคนที่รายงานนี้ครอบคลุม — ที่เดียวที่อ่าน month/owner จาก request
     *
     * @param  int|null  $forcedDepartmentId  หัวหน้าแผนก: เลือกได้เฉพาะคนในแผนกตัวเอง
     * @param  int|null  $forcedOwnerId  พนักงานทั่วไป: เห็นเฉพาะของตัวเองเสมอ
     * @param  bool  $allowTeam  หน้า Overview เท่านั้น: ยังไม่เลือกคน = ภาพรวมทีม
     */
    public function scope(
        Request $request,
        User $viewer,
        ?int $forcedDepartmentId,
        ?int $forcedOwnerId,
        bool $allowTeam = false
    ): OperationalReportScope {
        $monthOptions = ReportMonth::options();
        $month = ReportMonth::resolve($request->string('month')->toString());

        $owners = $this->ownerOptions($viewer, $forcedDepartmentId, $forcedOwnerId);
        $canChooseOwner = $forcedOwnerId === null;

        // พนักงานทั่วไปไม่มีทางตั้ง owner จาก URL ได้เลย ส่วนหัวหน้า/admin ต้องเลือกคน
        // ที่อยู่ในรายชื่อของตัวเองเท่านั้น
        $owner = $owners->firstWhere('id', $forcedOwnerId ?? $request->integer('owner'));

        // หัวหน้า/admin ที่เปิดหน้า Overview โดยยังไม่เลือกคน (หรือส่งคนนอกขอบเขตมา)
        // เห็นภาพรวมทีมก่อน หน้าอื่นทั้งหมดตกกลับเป็นรายงานของตัวเอง
        if ($owner === null && $allowTeam && $canChooseOwner) {
            return new OperationalReportScope($month, null, $owners, $forcedDepartmentId, true, false, $monthOptions);
        }

        $owner ??= $owners->firstWhere('id', $viewer->id) ?? $viewer;

        return new OperationalReportScope(
            month: $month,
            owner: $owner,
            owners: $owners,
            departmentId: $forcedDepartmentId,
            canChooseOwner: $canChooseOwner,
            isOwnReport: (int) $owner->id === (int) $viewer->id,
            monthOptions: $monthOptions,
        );
    }

    /**
     * ภาพรวมทีม — วันนี้แต่ละคนทำงานประจำหรือยัง และภาพรวมของเดือนที่เลือก
     *
     * @return array<string, mixed>
     */
    public function teamOverview(OperationalReportScope $scope): array
    {
        $members = $scope->owners
            ->filter(fn (User $member): bool => $member->role === 'user')
            ->values();
        $ids = $members->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $logs = $this->logsFor($ids, $scope->departmentId, $scope->month);
        $previousLogs = $this->logsFor($ids, $scope->departmentId, $scope->month->subMonthNoOverflow());
        $isCurrentMonth = $scope->monthKey() === $this->currentMonth()->format('Y-m');
        // สถานะวันนี้มาจาก TodayOperationalStatus ตัวเดียวกับบอร์ดทีม ตัวเลขสองหน้าจึงตรงกัน
        $todayByMember = $isCurrentMonth ? $this->today->forMembers($members, $scope->departmentId) : collect();
        $byMember = $logs->groupBy('user_id');

        $people = $members
            ->map(function (User $member) use ($byMember, $todayByMember, $isCurrentMonth): array {
                $values = $this->kpiValues($byMember->get($member->id, collect()));
                $today = $todayByMember->get($member->id);

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'department' => $member->department?->department_name ?? 'ไม่ระบุแผนก',
                    'hours_text' => $this->hoursText($values['minutes']),
                    'count' => $byMember->get($member->id, collect())->count(),
                    'close_rate' => $values['close_rate'] === null ? '—' : $values['close_rate'].'%',
                    'pending' => $values['pending'],
                    'today' => $isCurrentMonth ? $today['routine'] : null,
                    // รายการงานของวันนี้สำหรับกล่อง "ดูงาน" — ทุกประเภทงาน รวมงานประจำที่ยังไม่เริ่ม
                    'today_items' => $isCurrentMonth ? $this->todayItems($today['logs'], $today['unopened']) : collect(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => $this->todayRank($a) <=> $this->todayRank($b),
                fn (array $a, array $b): int => strcmp($a['name'], $b['name']),
            ])
            ->values();

        return [
            'scope' => $scope,
            'kpis' => $this->kpis($logs, $previousLogs),
            'workTypes' => $this->workTypes($logs),
            'totalHoursText' => $this->hoursText($this->minutesOf($logs)),
            'chartData' => $this->chartData($scope, $logs),
            'people' => $people,
            'isCurrentMonth' => $isCurrentMonth,
            'todaySummary' => collect(self::TODAY_STATUSES)
                ->map(fn (array $meta, string $key): array => [
                    ...$meta,
                    'key' => $key,
                    'count' => $people->filter(fn (array $person): bool => ($person['today']['key'] ?? null) === $key)->count(),
                ])
                ->values(),
            'teamLabel' => $scope->departmentId === null
                ? 'ทุกแผนก'
                : ($members->first()?->department?->department_name ?? 'แผนกของคุณ'),
        ];
    }

    /**
     * ข้อมูลทั้งหมดของหน้า Overview รายบุคคล
     *
     * @return array<string, mixed>
     */
    public function overview(OperationalReportScope $scope): array
    {
        $logs = $this->logs($scope);
        $previousLogs = $this->logsFor([(int) $scope->owner->id], $scope->departmentId, $scope->month->subMonthNoOverflow());
        $week = $this->overviewWeek($scope, $logs);
        $frequent = $this->frequentWork($scope);
        $delayGroups = $this->delayReasonGroups($scope);

        return [
            'scope' => $scope,
            'kpis' => $this->kpis($logs, $previousLogs),
            'workTypes' => $this->workTypes($logs),
            'totalHoursText' => $this->hoursText($this->minutesOf($logs)),
            'chartData' => $this->chartData($scope, $logs),
            'frequentWork' => $frequent->take(self::OVERVIEW_LIMIT)->values(),
            'frequentTotal' => $frequent->count(),
            'delayGroups' => $delayGroups->take(self::OVERVIEW_LIMIT)->values(),
            'delayGroupTotal' => $delayGroups->count(),
            'week' => $week,
            'weekRows' => $week === null
                ? collect()
                : $this->rowsFor($logs->filter(fn (WorkLog $log): bool => $this->isInWeek($log, $week))),
            'dailyTotal' => $logs->count(),
        ];
    }

    /**
     * ตารางสรุปรายวันทั้งเดือน — ระดับรายการงาน ไม่ใช่ตัวเลขรวมต่อวัน
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyRows(OperationalReportScope $scope): Collection
    {
        return $this->rowsFor($this->logs($scope));
    }

    /**
     * งานที่ทำบ่อยที่สุด — จัดกลุ่มด้วยชื่องานที่ตัดช่องว่างหัวท้ายแล้ว
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function frequentWork(OperationalReportScope $scope): Collection
    {
        return $this->logs($scope)
            ->groupBy(fn (WorkLog $log): string => trim((string) $log->title))
            ->map(fn (Collection $group, string $title): array => [
                'title' => $title === '' ? 'ไม่ระบุชื่องาน' : $title,
                'count' => $group->count(),
                'minutes' => $this->minutesOf($group),
                'hours_text' => $this->hoursText($this->minutesOf($group)),
            ])
            ->sortBy([
                ['count', 'desc'],
                ['minutes', 'desc'],
                ['title', 'asc'],
            ])
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1, ...$row]);
    }

    /**
     * เหตุการณ์ล่าช้า/เกินกำหนดทีละรายการ — ย้อนกลับไปหางานและวันที่ได้เสมอ
     *
     * บันทึกหนึ่งรายการให้ได้มากกว่าหนึ่งเหตุการณ์ เช่นเริ่มช้าแล้วยังเสร็จเกินเวลาอีก
     * เพราะเป็นสองคำตอบที่ผู้ปฏิบัติงานระบุแยกกันจริง
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function delayEvents(OperationalReportScope $scope): Collection
    {
        return $this->logs($scope)->flatMap(function (WorkLog $log): array {
            $row = $this->rowFor($log);
            $events = [];

            foreach ([
                'late_start' => $log->late_start_reason,
                'late_completion' => $log->late_completion_reason,
            ] as $type => $reason) {
                if (filled($reason)) {
                    $events[] = $this->delayEvent($row, $type, trim((string) $reason));
                }
            }

            $status = $row['status'];

            // ประเภทเหตุการณ์มาจากสถานะจริง เหตุผลมาจากคอลัมน์ของสถานะนั้น
            // ยังไม่มีเหตุผลก็ต้องโผล่ ไม่เช่นนั้นคนที่ไม่ตอบอะไรเลยจะดูดีกว่าคนที่ตอบตามจริง
            if ($status === 'skipped' && filled($log->skip_reason)) {
                $events[] = $this->delayEvent($row, 'skipped', trim((string) $log->skip_reason));
            } elseif (in_array($status, ['not_started', 'absent'], true)) {
                $events[] = $this->delayEvent($row, $status, filled($log->skip_reason) ? trim((string) $log->skip_reason) : self::UNSPECIFIED_REASON);
            } elseif ($status === 'unfinished') {
                $events[] = $this->delayEvent($row, 'unfinished', filled($log->unfinished_reason) ? trim((string) $log->unfinished_reason) : self::UNSPECIFIED_REASON);
            } elseif ($status === 'overdue' && blank($log->late_completion_reason)) {
                $events[] = $this->delayEvent($row, 'overdue', self::UNSPECIFIED_REASON);
            }

            return $events;
        })
            ->sortBy('date')
            ->values();
    }

    /**
     * เหตุผลจัดกลุ่มตามข้อความ พร้อมจำนวนครั้ง
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function delayReasonGroups(OperationalReportScope $scope): Collection
    {
        return $this->delayEvents($scope)
            ->groupBy('reason')
            ->map(fn (Collection $group, string $reason): array => [
                'reason' => $reason,
                'count' => $group->count(),
                'types' => $group->pluck('type_label')->unique()->values()->all(),
            ])
            ->sortBy([
                ['count', 'desc'],
                ['reason', 'asc'],
            ])
            ->values();
    }

    /**
     * ข้อมูลของไฟล์ CSV หนึ่งไฟล์ — แถวชุดเดียวกับหน้า detail ของรายงานนั้น
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, int|string>>}
     */
    public function csvTable(string $report, OperationalReportScope $scope): array
    {
        return match ($report) {
            'daily' => [
                'headers' => self::DAILY_HEADERS,
                'rows' => $this->dailyRows($scope)->map(fn (array $row): array => [
                    $row['date_label'], $row['title'], $row['kind_label'], $row['category'],
                    $row['status_label'], $row['hours'], $row['note'],
                ])->all(),
            ],
            'frequent' => [
                'headers' => self::FREQUENT_HEADERS,
                'rows' => $this->frequentWork($scope)->map(fn (array $row): array => [
                    $row['rank'], $row['title'], $row['count'], $row['hours_text'],
                ])->all(),
            ],
            'delays' => [
                'headers' => self::DELAY_HEADERS,
                'rows' => $this->delayEvents($scope)->map(fn (array $row): array => [
                    $row['date_label'], $row['title'], $row['kind_label'], $row['category'],
                    $row['type_label'], $row['reason'], $row['status_label'],
                ])->all(),
            ],
        };
    }

    /** ลำดับของแถวบนภาพรวมทีม — คนที่ต้องตามก่อนอยู่บนสุด เดือนย้อนหลังเรียงตามชื่อ */
    private function todayRank(array $person): int
    {
        return $person['today'] === null
            ? 0
            : (int) array_search($person['today']['key'], array_keys(self::TODAY_STATUSES), true);
    }

    /**
     * แถวของกล่อง "ดูงาน" — รูปแบบเดียวกับตารางสรุปรายวัน ต่อท้ายด้วยงานประจำที่ยังไม่เริ่ม
     *
     * @param  Collection<int, WorkLog>  $logs
     * @param  Collection<int, WorkLogTemplate>  $unopened
     * @return Collection<int, array<string, mixed>>
     */
    private function todayItems(Collection $logs, Collection $unopened): Collection
    {
        return $this->rowsFor($logs)->concat($unopened->map(fn (WorkLogTemplate $template): array => [
            'id' => null,
            'title' => (string) $template->title,
            'kind_label' => WorkLogDesign::kind($template->kind)['label'],
            'category' => $template->category?->name ?? 'ไม่ระบุหมวด',
            'status' => 'waiting',
            'status_label' => self::TODAY_STATUSES['waiting']['label'],
            'status_tone' => self::TODAY_STATUSES['waiting']['tone'],
            'hours' => '—',
            'note' => '—',
        ]))->values();
    }

    /**
     * @param  Collection<int, WorkLog>  $logs
     * @return array<string, mixed>
     */
    private function chartData(OperationalReportScope $scope, Collection $logs): array
    {
        $workTypes = $this->workTypes($logs);

        return [
            'daily' => $this->dailyHours($scope, $logs),
            'workTypes' => [
                'labels' => $workTypes->pluck('label')->all(),
                'values' => $workTypes->pluck('hours')->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, WorkLog>  $logs
     * @param  Collection<int, WorkLog>  $previousLogs
     * @return array<string, array<string, mixed>>
     */
    private function kpis(Collection $logs, Collection $previousLogs): array
    {
        $current = $this->kpiValues($logs);
        $previous = $this->kpiValues($previousLogs);

        return [
            'hours' => [
                'label' => 'ชั่วโมงรวม',
                'value' => $this->hoursText($current['minutes']),
                'trend' => $this->trend($current['minutes'], $previous['minutes'], 'percent', true),
            ],
            'routine' => [
                'label' => 'งานประจำ',
                'value' => number_format($current['routine']).' ครั้ง',
                'trend' => $this->trend($current['routine'], $previous['routine'], 'percent', true),
            ],
            'field' => [
                'label' => 'งานนอกสถานที่',
                'value' => number_format($current['field']).' ครั้ง',
                'trend' => $this->trend($current['field'], $previous['field'], 'percent', true),
            ],
            'close_rate' => [
                'label' => 'อัตราปิดรายการ',
                'value' => $current['close_rate'] === null ? '—' : $current['close_rate'].'%',
                'trend' => $this->trend($current['close_rate'], $previous['close_rate'], 'points', true),
            ],
            'on_time' => [
                'label' => 'ตรงเวลา',
                'value' => $current['on_time_rate'] === null ? '—' : $current['on_time_rate'].'%',
                'trend' => $this->trend($current['on_time_rate'], $previous['on_time_rate'], 'points', true),
            ],
            'pending' => [
                'label' => 'งานค้าง/เกินเวลา',
                'value' => number_format($current['pending']).' รายการ',
                'trend' => $this->trend($current['pending'], $previous['pending'], 'percent', false),
            ],
        ];
    }

    /**
     * นิยามของ KPI แต่ละตัว
     *
     * - งานประจำ/งานนอกสถานที่ นับตาม "ประเภทงาน" (kind) ชุดเดียวกับกราฟและตาราง
     * - อัตราปิดรายการ = (เสร็จ + ระบุว่าไม่ได้ทำ) / ทั้งหมด เพราะทั้งสองคือการรายงานผลแล้ว
     * - ตรงเวลาและงานค้าง นับเฉพาะงานประจำจากแม่แบบ เพราะมีเวลาตามแผนให้เทียบ
     *   งานที่บันทึกเองโดยไม่ระบุเวลาจะค้างสถานะรอเริ่มตลอดไป นับรวมไม่ได้
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return array<string, int|null>
     */
    private function kpiValues(Collection $logs): array
    {
        $total = $logs->count();
        // ปิดรอบแล้วและมีเหตุผลแล้ว นับว่ารายงานผลแล้วเหมือน "ไม่ได้ทำ" ส่วนที่ยังไม่มีเหตุผลนับเป็นงานค้าง
        $closed = $logs->filter(fn (WorkLog $log): bool => in_array($log->status, ['done', 'skipped'], true)
            || (in_array(WorkLogPresenter::displayStatus($log), WorkLogDesign::EXPLANATION_STATUSES, true)
                && ! WorkLogPresenter::requiresExplanation($log)))->count();
        $routines = $logs->filter(fn (WorkLog $log): bool => $log->work_log_template_id !== null);
        $onTime = $routines->filter(fn (WorkLog $log): bool => $log->status === 'done'
            && blank($log->late_start_reason)
            && blank($log->late_completion_reason));

        return [
            'minutes' => $this->minutesOf($logs),
            'routine' => $logs->where('kind', 'routine')->count(),
            'field' => $logs->where('kind', 'field')->count(),
            'close_rate' => $total > 0 ? (int) round(($closed / $total) * 100) : null,
            'on_time_rate' => $routines->isNotEmpty() ? (int) round(($onTime->count() / $routines->count()) * 100) : null,
            'pending' => $routines->filter(
                fn (WorkLog $log): bool => WorkLogPresenter::displayStatus($log) === 'overdue'
                    || WorkLogPresenter::requiresExplanation($log)
            )->count(),
        ];
    }

    /**
     * เทียบกับเดือนก่อน — ไม่แสดงเมื่อไม่มีฐานให้เทียบ แทนที่จะแสดง "เพิ่มขึ้นไม่จำกัด"
     *
     * @param  'percent'|'points'  $mode  percent = เปลี่ยนไปกี่ % ของเดือนก่อน, points = ต่างกันกี่จุดของอัตรา
     * @return array{direction: string, text: string, tone: string}|null
     */
    private function trend(?int $current, ?int $previous, string $mode, bool $higherIsBetter): ?array
    {
        if ($current === null || $previous === null || ($mode === 'percent' && $previous === 0)) {
            return null;
        }

        $difference = $mode === 'percent'
            ? (int) round((($current - $previous) / $previous) * 100)
            : $current - $previous;

        return [
            'direction' => $difference > 0 ? 'up' : ($difference < 0 ? 'down' : 'flat'),
            'text' => abs($difference).'%',
            'tone' => $difference === 0 ? 'neutral' : (($difference > 0) === $higherIsBetter ? 'good' : 'bad'),
        ];
    }

    /**
     * @param  Collection<int, WorkLog>  $logs
     * @return Collection<int, array<string, mixed>>
     */
    private function workTypes(Collection $logs): Collection
    {
        $total = $this->minutesOf($logs);

        return collect(WorkLogDesign::KINDS)
            ->map(function (array $meta, string $key) use ($logs, $total): array {
                $minutes = $this->minutesOf($logs->where('kind', $key));

                return [
                    'key' => $key,
                    'label' => $meta['label'],
                    'minutes' => $minutes,
                    'hours' => round($minutes / 60, 1),
                    'hours_text' => $this->hoursText($minutes),
                    'share' => $total > 0 ? (int) round(($minutes / $total) * 100) : 0,
                ];
            })
            ->values();
    }

    /**
     * ชั่วโมงงานรายวันของทั้งเดือน แยกงานประจำกับงานนอกสถานที่
     *
     * เดินครบทุกวันของเดือน วันที่ไม่มีงานจึงเป็นช่องว่างบนกราฟ ซึ่งบอกอะไรได้เท่ากับแท่งสูง
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return array<string, array<int, float|string>>
     */
    private function dailyHours(OperationalReportScope $scope, Collection $logs): array
    {
        $byDay = $logs->groupBy(fn (WorkLog $log): string => $log->work_date?->format('Y-m-d') ?? '');
        $data = ['labels' => [], 'titles' => [], 'routine' => [], 'field' => []];

        for ($day = $scope->month->startOfMonth(); $day->lessThanOrEqualTo($scope->month->endOfMonth()); $day = $day->addDay()) {
            $group = $byDay->get($day->format('Y-m-d'), collect());

            $data['labels'][] = (string) $day->day;
            // translatedFormat แปลชื่อเดือนตาม locale ส่วน format() คืนภาษาอังกฤษเสมอ
            $data['titles'][] = $day->locale('th')->translatedFormat('j M').' '.($day->year + 543);
            $data['routine'][] = round($this->minutesOf($group->where('kind', 'routine')) / 60, 1);
            $data['field'][] = round($this->minutesOf($group->where('kind', 'field')) / 60, 1);
        }

        return $data;
    }

    /**
     * สัปดาห์ของตาราง "สรุปรายวัน" บน Overview — จันทร์ถึงเสาร์ ตัดให้อยู่ในเดือน
     *
     * เดือนปัจจุบันใช้สัปดาห์ของวันนี้ เดือนย้อนหลังใช้สัปดาห์ของวันล่าสุดที่มีข้อมูล
     * (ข้ามวันอาทิตย์ เพราะไม่อยู่ในช่วงที่แสดง)
     *
     * @param  Collection<int, WorkLog>  $logs
     * @return array{start: string, end: string, label: string}|null
     */
    private function overviewWeek(OperationalReportScope $scope, Collection $logs): ?array
    {
        $today = CarbonImmutable::parse(TodayWorkspace::businessNow()->format('Y-m-d'), self::BUSINESS_TIMEZONE);

        if ($scope->monthKey() === $today->format('Y-m')) {
            $anchor = $today;
        } else {
            $dates = $logs->map(fn (WorkLog $log): ?string => $log->work_date?->format('Y-m-d'))
                ->filter()
                ->unique()
                ->sort()
                ->values();

            if ($dates->isEmpty()) {
                return null;
            }

            $weekdays = $dates->reject(
                fn (string $date): bool => CarbonImmutable::parse($date, self::BUSINESS_TIMEZONE)->isSunday()
            );
            $anchor = CarbonImmutable::parse($weekdays->last() ?? $dates->last(), self::BUSINESS_TIMEZONE);
        }

        $monday = $anchor->startOfWeek(CarbonInterface::MONDAY);
        $start = $monday->max($scope->month->startOfMonth());
        $end = $monday->addDays(5)->min($scope->month->endOfMonth()->startOfDay());

        if ($start->greaterThan($end)) {
            return null;
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $start->locale('th')->translatedFormat('j M').' – '
                .$end->locale('th')->translatedFormat('j M').' '.($end->year + 543),
        ];
    }

    /**
     * @param  array{start: string, end: string}  $week
     */
    private function isInWeek(WorkLog $log, array $week): bool
    {
        $date = $log->work_date;

        return $date !== null
            && ! $date->isSunday()
            && $date->format('Y-m-d') >= $week['start']
            && $date->format('Y-m-d') <= $week['end'];
    }

    /**
     * @param  Collection<int, WorkLog>  $logs
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsFor(Collection $logs): Collection
    {
        return $logs->map(fn (WorkLog $log): array => $this->rowFor($log))->values();
    }

    /**
     * หนึ่งแถวของตารางสรุปรายวัน — รูปแบบเดียวกันทั้ง Overview หน้าฉบับเต็ม และ CSV
     *
     * @return array<string, mixed>
     */
    private function rowFor(WorkLog $log): array
    {
        $status = WorkLogPresenter::displayStatus($log);
        $date = $log->work_date === null ? null : CarbonImmutable::parse($log->work_date->format('Y-m-d'), self::BUSINESS_TIMEZONE);

        return [
            'id' => $log->id,
            'date' => $date?->toDateString(),
            'date_label' => $date === null
                ? '—'
                : $date->locale('th')->translatedFormat('j M').' '.($date->year + 543).' ('.self::WEEKDAY_SHORT[$date->dayOfWeek].')',
            'title' => (string) $log->title,
            'kind_label' => WorkLogDesign::kind($log->kind)['label'],
            'category' => $log->category?->name ?? 'ไม่ระบุหมวด',
            'status' => $status,
            'status_label' => WorkLogDesign::status($status)['label'],
            'status_tone' => self::STATUS_TONES[$status] ?? 'slate',
            'hours' => $log->duration_minutes === null ? '—' : number_format($log->duration_minutes / 60, 1),
            // เหตุผลมาก่อนรายละเอียด เพราะเป็นคำอธิบายของสถานะที่เห็นอยู่ในแถวเดียวกัน
            // ค่าว่างเป็น "—" ทั้งบนหน้าจอและใน CSV สองช่องทางจึงตรงกันทุกตัวอักษร
            'note' => trim((string) ($log->skip_reason ?: ($log->unfinished_reason ?: ($log->late_completion_reason ?: ($log->late_start_reason ?: ($log->details ?? '')))))) ?: '—',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function delayEvent(array $row, string $type, string $reason): array
    {
        return [
            ...$row,
            'type' => $type,
            'type_label' => self::DELAY_TYPES[$type],
            'reason' => $reason === '' ? self::UNSPECIFIED_REASON : $reason,
        ];
    }

    /**
     * บันทึกของคนและเดือนใน scope รายบุคคล
     *
     * @return Collection<int, WorkLog>
     */
    private function logs(OperationalReportScope $scope): Collection
    {
        return $this->logsFor([(int) $scope->owner->id], $scope->departmentId, $scope->month);
    }

    /**
     * บันทึกของกลุ่มคนในเดือนหนึ่ง — query ครั้งเดียวต่อชุดคน/เดือนในหนึ่ง request
     *
     * @param  array<int, int>  $userIds  ผ่านการตรวจขอบเขตใน scope() แล้ว ไม่ใช่ค่าจาก query string
     * @return Collection<int, WorkLog>
     */
    private function logsFor(array $userIds, ?int $departmentId, CarbonImmutable $month): Collection
    {
        $key = implode(':', [md5(implode(',', $userIds)), $departmentId ?? 0, $month->format('Y-m')]);

        return $this->logCache[$key] ??= $this->scopedLogQuery($userIds, $departmentId)
            ->with(['category:id,name'])
            ->whereDate('work_date', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('work_date', '<=', $month->endOfMonth()->toDateString())
            ->orderBy('work_date')
            ->orderBy('planned_start_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function scopedLogQuery(array $userIds, ?int $departmentId): Builder
    {
        // หัวหน้าแผนกเห็นเฉพาะบันทึกที่เป็นของแผนกตัวเอง — กติกาเดียวกับสถานะวันนี้บนบอร์ดทีม
        return TodayOperationalStatus::scopedQuery($userIds, $departmentId);
    }

    /**
     * คนที่ผู้ดูเลือกดูรายงานได้
     *
     * - พนักงานทั่วไป: ตัวเองคนเดียว
     * - หัวหน้าแผนก: พนักงานที่ active ในแผนกตัวเอง (รวมตัวเอง)
     * - admin: พนักงานที่ active ทุกแผนก และตัวเอง
     *
     * @return Collection<int, User>
     */
    private function ownerOptions(User $viewer, ?int $forcedDepartmentId, ?int $forcedOwnerId): Collection
    {
        if ($forcedOwnerId !== null) {
            return collect([$viewer->loadMissing('department:id,department_name')]);
        }

        $members = User::query()
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($forcedDepartmentId, fn (Builder $query, int $id) => $query->where('department_id', $id))
            ->with('department:id,department_name')
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'department_id']);

        if (! $members->contains('id', $viewer->id)) {
            $members->prepend($viewer->loadMissing('department:id,department_name'));
        }

        return $members->values();
    }

    private function currentMonth(): CarbonImmutable
    {
        return ReportMonth::current();
    }

    /**
     * @param  Collection<int, WorkLog>  $logs
     */
    private function minutesOf(Collection $logs): int
    {
        return (int) $logs->sum(fn (WorkLog $log): int => (int) ($log->duration_minutes ?? 0));
    }

    /** "128 ชม." หรือ "1.5 ชม." — ตัดทศนิยมเมื่อเป็นจำนวนเต็ม */
    private function hoursText(int $minutes): string
    {
        $hours = round($minutes / 60, 1);

        return (fmod($hours, 1.0) === 0.0 ? number_format($hours) : number_format($hours, 1)).' ชม.';
    }
}
