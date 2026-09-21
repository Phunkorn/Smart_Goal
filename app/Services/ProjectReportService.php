<?php

namespace App\Services;

use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CrossDepartmentWork;
use App\Support\ReportMetrics;
use App\Support\ReportMonth;
use App\Support\ReportPeriod;
use App\Support\ReportTaskTree;
use App\Support\TaskTeamSummary;
use App\Support\WorkBoardDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * รายงานโปรเจกต์ประจำเดือน — รายบุคคล หรือภาพรวมของทุกคนในขอบเขตที่ผู้ดูดูแล
 *
 * แถวของรายงานมีสองบทบาท (row.role):
 * - รับผิดชอบ: งานที่อนุมัติแล้วซึ่งคนในขอบเขตเป็นผู้รับผิดชอบ (WorkOrder::scopeAssignedToAny)
 * - ร่วมทำ: งานที่คนในขอบเขตเป็นผู้ร่วมงานที่ตอบรับแล้ว แต่ไม่มีใครในขอบเขตรับผิดชอบ (WorkOrder::scopeJoinedByAny)
 * ทั้งสองชุดนับงานที่สร้างหรือปิดในช่วงที่เลือก และยุบงานย่อยเข้าใต้งานแม่ (ReportTaskTree::collapse)
 * งานหนึ่งใบเป็นได้บทบาทเดียว — รับผิดชอบมาก่อนร่วมทำ ภาพรวมแผนกจึงไม่มีแถวซ้ำ
 *
 * รายชื่อคนที่ส่งเข้ามาต้องผ่านการตรวจสิทธิ์ที่ ReportController แล้ว คลาสนี้ไม่ตัดสินสิทธิ์เอง
 *
 * KPI เดิมทั้งหกใบและกราฟนับเฉพาะงานที่รับผิดชอบ งานที่ร่วมทำมี KPI ของตัวเองใบเดียว
 * งานหนึ่งใบจึงไม่ถูกนับเป็นผลงานเสร็จของหลายคน ตัวกรองในการ์ดตัวกรองมีผลกับตารางและ CSV เท่านั้น
 * ตารางบนหน้าจอกับ CSV อ่านจากแถวชุดเดียวกัน ตัวเลขจึงตรงกันโดยโครงสร้าง
 */
final class ProjectReportService
{
    /** จำนวนงานในตารางหน้าหลัก ส่วนที่เหลือดูได้ที่หน้า "ดูรายละเอียดทั้งหมด" */
    public const PREVIEW_LIMIT = 10;

    public const DETAIL_PER_PAGE = 20;

    public const DEFAULT_SORT = 'date_desc';

    public const SORTS = [
        'date_desc' => 'วันที่ (ใหม่สุดก่อน)',
        'date_asc' => 'วันที่ (เก่าสุดก่อน)',
        'topic' => 'ชื่องาน (ก–ฮ)',
        'status' => 'สถานะ',
    ];

    /** ค่าตัวกรองโปรเจกต์ของงานที่ไม่ได้อยู่ในโปรเจกต์ใด */
    public const NO_PROJECT = 'none';

    public const SCOPE_CROSS = 'cross';

    public const SCOPE_INTERNAL = 'internal';

    private const SCOPE_LABELS = [
        self::SCOPE_CROSS => 'ข้ามแผนก',
        self::SCOPE_INTERNAL => 'ในแผนก',
    ];

    public const ROLE_OWNED = 'owned';

    public const ROLE_JOINED = 'joined';

    /** ป้ายบทบาทในคอลัมน์ของตาราง */
    private const ROLE_LABELS = [
        self::ROLE_OWNED => 'รับผิดชอบ',
        self::ROLE_JOINED => 'ร่วมทำ',
    ];

    /** ป้ายของตัวกรองบทบาท ("ทั้งหมด" เป็นค่าว่างของ select) */
    private const ROLE_FILTER_LABELS = [
        self::ROLE_OWNED => 'ที่รับผิดชอบ',
        self::ROLE_JOINED => 'ที่ร่วมทำ',
    ];

    /** ลำดับแถวของกราฟแท่งสถานะ — งานที่ปิดแล้วขึ้นก่อน งานที่ต้องตามไว้ท้าย สีอยู่ที่ project-chart-config.js */
    private const STATUS_CHART_ORDER = ['done', 'doing', 'review', 'paused', 'late'];

    /** จำนวนงานล่าช้าในการ์ด "งานล่าช้าที่ต้องติดตาม" */
    public const LATE_LIMIT = 5;

    /** จำนวนคนสูงสุดในโดนัทงานที่ปิดได้รายคน ที่เหลือรวมเป็น "อื่น ๆ" (ชิ้นเกินห้าสีแยกกันไม่ออก) */
    public const BREAKDOWN_MEMBER_LIMIT = 5;

    /**
     * สีของโดนัทงานที่ปิดได้รายคน ตามลำดับชิ้น — ไม่มีเขียว และไม่ใช้แดงซึ่งสงวนไว้สื่อ "ล่าช้า"
     * ผ่าน scripts/validate_palette.js ของ dataviz skill ครบทุกเกณฑ์ (light surface)
     * "อื่น ๆ" เป็นเทาและมีป้ายชื่อในตารางข้างวงเสมอ
     */
    public const MEMBER_COLORS = ['#1d4ed8', '#ea580c', '#0891b2', '#a21caf', '#92400e'];

    public const OTHERS_COLOR = '#64748b';

    /** เรียงตามสถานะ: งานที่ต้องตามก่อน งานที่ปิดแล้วไว้ท้าย */
    private const STATUS_ORDER = ['late', 'review', 'doing', 'paused', 'done'];

    private const SEARCH_LIMIT = 100;

    /**
     * @param  Collection<int, User>  $members  คนที่ผู้ดูเลือกดูได้ ตรวจสิทธิ์แล้ว
     * @param  User|null  $owner  คนที่เลือก — null คือภาพรวมของทุกคนใน $members
     * @return array<string, mixed>
     */
    public function build(Request $request, Collection $members, ?User $owner): array
    {
        // เดือนตามตัวเลือกเดิม หรือช่วงวันที่ที่กำหนดเอง ค่าที่ไม่ถูกต้องกลับไปเป็นรายเดือน
        $period = ReportPeriod::fromRequest($request);
        $subjects = $owner !== null ? collect([$owner]) : $members->values();
        $now = CarbonImmutable::now(ReportMetrics::BUSINESS_TIMEZONE);
        ['owned' => $jobs, 'joined' => $joinedJobs] = $this->periodJobs($subjects, $period);

        $ownedRows = $jobs->map(fn (WorkOrder $job): array => $this->presentJob($job, $subjects, $owner, $now, self::ROLE_OWNED))->values();
        $joinedRows = $joinedJobs->map(fn (WorkOrder $job): array => $this->presentJob($job, $subjects, $owner, $now, self::ROLE_JOINED))->values();
        $rows = $ownedRows->concat($joinedRows)->values();
        $filterOptions = $this->filterOptions($rows);
        $filters = $this->normalizeFilters($request, $filterOptions);
        $taskRows = $this->sortRows($this->filterRows($rows, $filters), $filters['sort']);
        // กราฟติดตามโปรเจกต์ที่เลือก แต่ยังนับเฉพาะงานที่รับผิดชอบตามนิยามเดิม
        // ตัวกรองอื่นเป็นตัวกรองตารางและ CSV จึงไม่เปลี่ยนความหมายของกราฟ
        $chartRows = $filters['project']
            ? $ownedRows->where('project.key', $filters['project'])->values()
            : $ownedRows;

        // KPI เดิมและกราฟนับเฉพาะงานที่รับผิดชอบ (ownedRows) — ตัวเลขเหมือนก่อนมีงานที่ร่วมทำทุกประการ
        $completedJobs = $jobs->filter(fn (WorkOrder $job): bool => ReportMetrics::isCompleted($job)
            && $job->job_completed_at !== null
            && $period->contains($job->job_completed_at))->count();
        $lateJobs = $ownedRows->where('status.key', 'late')->count();
        $activeJobs = $ownedRows->reject(fn (array $row): bool => $row['status']['key'] === 'done')->count();

        $query = $this->queryFor($owner, $period, $filters);

        return [
            'owner' => $owner,
            'isTeamView' => $owner === null,
            // ค่าของดร็อปดาวน์ช่วงเวลา: 'Y-m' ของเดือน หรือ 'custom' เมื่อกำหนดช่วงเอง
            'monthKey' => $period->selectValue(),
            'monthLabel' => $period->label(),
            // ต่อท้ายคำได้ทันที เช่น "งานใน".$periodPhrase → "งานในเดือนกันยายน 2569" / "งานในช่วง 1 ก.ย. – 15 ก.ย. 2569"
            'periodPhrase' => $period->phrase(),
            'periodQuery' => $period->query(),
            'periodFileKey' => $period->fileKey(),
            'periodFrom' => $period->fromValue(),
            'periodTo' => $period->toValue(),
            'isCustomPeriod' => $period->isCustom,
            'trendTitle' => $period->trendTitle(),
            // แสดงทางเลือกกำหนดช่วงเองไว้บนสุดให้ค้นพบง่าย แต่ selected ยังมาจากช่วงปัจจุบันตามเดิม
            'monthOptions' => [ReportPeriod::CUSTOM => 'กำหนดช่วงวันที่เอง…'] + ReportMonth::options(),
            'totalJobs' => $jobs->count(),
            'kpis' => [
                ['key' => 'projects', 'label' => 'โปรเจกต์ที่มีงานรับผิดชอบ', 'value' => $jobs->pluck('work_order_list_id')->filter()->unique()->count(), 'unit' => 'โปรเจกต์', 'icon' => 'bi-folder2-open', 'tone' => 'blue'],
                ['key' => 'owned', 'label' => 'งานที่รับผิดชอบ', 'value' => $jobs->count(), 'unit' => 'งาน', 'icon' => 'bi-person-check', 'tone' => 'indigo'],
                ['key' => 'joined', 'label' => 'งานที่ร่วมทำ', 'value' => $joinedJobs->count(), 'unit' => 'งาน', 'icon' => 'bi-people', 'tone' => 'teal'],
                ['key' => 'subtasks', 'label' => 'งานย่อยทั้งหมด', 'value' => $ownedRows->sum(fn (array $row): int => count($row['subtasks'])), 'unit' => 'รายการ', 'icon' => 'bi-diagram-3', 'tone' => 'violet'],
                ['key' => 'cross', 'label' => 'งานข้ามแผนก', 'value' => $ownedRows->where('scope.key', self::SCOPE_CROSS)->count(), 'unit' => 'งาน', 'icon' => 'bi-arrow-left-right', 'tone' => 'orange'],
                ['key' => 'active', 'label' => 'กำลังดำเนินการ', 'value' => $activeJobs, 'unit' => 'งาน', 'icon' => 'bi-hourglass-split', 'tone' => 'sky', 'note' => $lateJobs > 0 ? 'ในจำนวนนี้ล่าช้า '.number_format($lateJobs).' งาน' : null],
                ['key' => 'completed', 'label' => 'เสร็จสิ้นแล้ว', 'value' => $completedJobs, 'unit' => 'งาน', 'icon' => 'bi-check2-circle', 'tone' => 'green'],
            ],
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'chartProjectLabel' => $filters['project'] ? $filterOptions['projects'][$filters['project']] : null,
            'hasActiveFilters' => collect(['project', 'status', 'scope', 'role', 'q'])
                ->contains(fn (string $key): bool => $filters[$key] !== null),
            'query' => $query,
            'taskRows' => $taskRows,
            'chartData' => $this->chartData($chartRows, $subjects, $owner, $period, $now, $filters['project']),
        ];
    }

    /**
     * แบ่งหน้าแถวที่กรองและเรียงแล้ว — หน้า "ดูรายละเอียดทั้งหมด"
     *
     * หน้าที่ขอเกินจำนวนจริงถูกดึงกลับมาหน้าสุดท้าย ลิงก์เปลี่ยนหน้าพก query ที่ตรวจแล้วเท่านั้น
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, int|string>  $query
     */
    public function paginate(Collection $rows, Request $request, string $routeName, array $query): LengthAwarePaginator
    {
        $lastPage = max(1, (int) ceil($rows->count() / self::DETAIL_PER_PAGE));
        $page = min(max(1, $request->integer('page', 1)), $lastPage);

        return new LengthAwarePaginator(
            $rows->forPage($page, self::DETAIL_PER_PAGE)->values(),
            $rows->count(),
            self::DETAIL_PER_PAGE,
            $page,
            ['path' => route($routeName), 'query' => $query],
        );
    }

    /**
     * เติมหลักฐานผลงาน (ไฟล์แนบของงานและของงานย่อย) ให้แถวในหน้าปัจจุบัน
     *
     * ทำเฉพาะแถวที่แสดงอยู่ ไม่ใช่ทั้งเดือน เพราะต้องตรวจสิทธิ์ทีละงาน
     * ลิงก์ไฟล์ออกให้เฉพาะงานที่ผู้ดูผ่าน WorkOrderPolicy::view ซึ่งเป็นนโยบายเดียวกับ
     * MediaController::taskAttachment งานที่ผู้ดูไม่มีสิทธิ์จะไม่เห็นแม้ชื่อไฟล์ (locked = true)
     *
     * ไฟล์ในคอมเมนต์ไม่นับเป็นหลักฐานของรายงานนี้ตามที่เจ้าของระบบกำหนด
     *
     * แถวได้ตัวนับสำหรับช่องไอคอน (evidence.counts, child_counts, subtask_summary)
     * รายชื่อไฟล์เต็มแสดงในกล่องรายละเอียดของแต่ละงานเท่านั้น
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function withEvidence(Collection $rows, User $viewer): Collection
    {
        $ids = $rows->flatMap(fn (array $row): array => [$row['id'], ...array_column($row['children'], 'id')])
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return $rows;
        }

        $tasks = WorkOrder::query()
            // ความสัมพันธ์ที่ WorkOrderPolicy::view ใช้ตัดสิน (รวมงานแม่ของงานย่อย) โหลดไว้ครั้งเดียว
            ->with([
                'user:id,department_id',
                'creator:id,department_id',
                'leader:id,department_id',
                'collaborators:id,department_id',
                'parent.user:id,department_id',
                'parent.creator:id,department_id',
                'parent.leader:id,department_id',
                'parent.collaborators:id,department_id',
                'images:id,job_id,original_name,file_type,created_at',
            ])
            ->whereIn('job_id', $ids)
            ->get()
            ->keyBy('job_id');
        $gate = Gate::forUser($viewer);

        $evidence = function (int $id) use ($tasks, $gate): array {
            $task = $tasks->get($id);

            if ($task === null || ! $gate->allows('view', $task)) {
                return ['files' => [], 'locked' => true, 'counts' => ['documents' => 0, 'images' => 0]];
            }

            $files = $task->images
                ->map(fn (JobImage $file): array => [
                    'name' => $file->original_name ?: 'ไฟล์แนบ',
                    'url' => route('media.task-attachments.show', $file),
                    'is_image' => str_starts_with((string) $file->file_type, 'image/'),
                ])
                ->values();

            return [
                'files' => $files->all(),
                'locked' => false,
                'counts' => [
                    'documents' => $files->where('is_image', false)->count(),
                    'images' => $files->where('is_image', true)->count(),
                ],
            ];
        };

        return $rows->map(function (array $row) use ($evidence): array {
            $children = array_map(
                fn (array $child): array => [...$child, 'evidence' => $evidence($child['id'])],
                $row['children']
            );
            $childEvidence = array_column($children, 'evidence');

            return [
                ...$row,
                'evidence' => $evidence($row['id']),
                'children' => $children,
                'subtask_summary' => [
                    'total' => count($children),
                    'done' => count(array_filter($children, fn (array $child): bool => $child['status']['key'] === 'done')),
                ],
                'child_counts' => [
                    'documents' => array_sum(array_column(array_column($childEvidence, 'counts'), 'documents')),
                    'images' => array_sum(array_column(array_column($childEvidence, 'counts'), 'images')),
                    'locked' => count(array_filter($childEvidence, fn (array $item): bool => $item['locked'])),
                ],
            ];
        })->values();
    }

    /**
     * กราฟของหน้า — ตามโปรเจกต์ที่เลือก แต่นับเฉพาะงานที่รับผิดชอบเหมือนเดิม
     *
     * ทุกมุมมอง (2 ใบ):
     * trend: แนวโน้มรายเดือนตั้งแต่มกราคมถึงเดือนที่เลือก (ปีเดียวกัน) งานที่ได้รับเทียบงานที่เสร็จ
     *   แต่ละเดือนนับด้วยนิยามเดียวกับรายงานประจำเดือนนั้นทุกประการ (monthJobs) ตัวเลขของเดือนที่เลือก
     *   จึงตรงกับ KPI "เสร็จสิ้นแล้ว" และจำนวนงานที่สร้างในเดือนนั้น
     * status: จำนวนงานแต่ละสถานะของเดือนที่เลือก (แท่งแนวนอน + จำนวนและเปอร์เซ็นต์ท้ายแท่ง)
     *
     * เฉพาะภาพรวมแผนก (อีก 2 ใบ รายบุคคลได้ null):
     * late: งานล่าช้าที่เลยกำหนดนานที่สุด LATE_LIMIT รายการ
     * breakdown: งานที่แต่ละคนปิดได้ในเดือนที่เลือก
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, User>  $subjects
     * @return array{trend: array<string, mixed>, status: array<string, mixed>, late: array<string, mixed>|null, breakdown: array<string, mixed>|null}
     */
    private function chartData(Collection $rows, Collection $subjects, ?User $owner, ReportPeriod $period, CarbonInterface $now, ?string $project): array
    {
        $statuses = collect(self::STATUS_CHART_ORDER)
            ->mapWithKeys(fn (string $key): array => [$key => WorkBoardDesign::statusMeta($key)]);

        return [
            'trend' => $this->monthlyTrend($subjects, $period, $project),
            'status' => [
                'keys' => $statuses->keys()->all(),
                'labels' => $statuses->pluck('label')->values()->all(),
                'values' => $statuses->keys()->map(fn (string $key): int => $rows->where('status.key', $key)->count())->all(),
                'total' => $rows->count(),
            ],
            'late' => $owner === null ? $this->lateFollowUps($rows, $now) : null,
            'breakdown' => $owner === null ? $this->completedByMember($rows, $subjects, $period) : null,
        ];
    }

    /**
     * งานล่าช้าที่ต้องติดตาม — เรียงจากเลยกำหนดนานที่สุด
     *
     * ใช้สถานะ "ล่าช้า" ชุดเดียวกับตารางและกราฟสถานะ (ReportMetrics::statusKey) จำนวนวันนับตามวันของกรุงเทพ
     * งานที่ถูกตั้งสถานะล่าช้าโดยไม่มีกำหนดส่งยังนับรวม แต่ไม่มีจำนวนวันให้แสดง
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function lateFollowUps(Collection $rows, CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->setTimezone(ReportMetrics::BUSINESS_TIMEZONE)->startOfDay();
        $late = $rows->where('status.key', 'late')->map(fn (array $row): array => [
            'id' => $row['id'],
            'topic' => $row['topic'],
            'project' => $row['project']['name'],
            'assignee' => $row['assignee']['name'] ?? 'ไม่ระบุผู้รับผิดชอบ',
            'due_label' => $row['due_at'] ? $row['due_at']->locale('th')->translatedFormat('j M').' '.($row['due_at']->year + 543) : null,
            'days' => $row['due_at']
                ? max(1, (int) floor(abs(CarbonImmutable::instance($row['due_at'])->startOfDay()->diffInDays($today))))
                : 0,
        ]);

        return [
            'total' => $late->count(),
            'items' => $late
                ->sortBy([fn (array $a, array $b): int => $b['days'] <=> $a['days'] ?: $a['id'] <=> $b['id']])
                ->take(self::LATE_LIMIT)
                ->values()
                ->all(),
        ];
    }

    /**
     * ภาพรวมแผนก — งานที่แต่ละคนปิดได้ในเดือนที่เลือก เรียงคนที่ปิดได้มากก่อน
     *
     * นับตามผู้รับผิดชอบหลักคนเดียวต่องาน (ไม่ fan-out ตามผู้ร่วมงาน) และใช้วันปิดงานของกรุงเทพ
     * นิยามเดียวกับ KPI "เสร็จสิ้นแล้ว" ผลรวมของทุกแท่งจึงไม่เกินตัวเลขในการ์ด
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, User>  $subjects
     *                                           แสดงเป็นโดนัท: BREAKDOWN_MEMBER_LIMIT คนแรก ที่เหลือรวมเป็นชิ้น "อื่น ๆ"
     * @return array{labels: list<string>, values: list<int>, colors: list<string>, total: int}
     */
    private function completedByMember(Collection $rows, Collection $subjects, ReportPeriod $period): array
    {
        $subjectIds = $subjects->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $members = $rows
            ->filter(fn (array $row): bool => $row['status']['key'] === 'done'
                && $row['completed_at'] !== null
                && $period->contains($row['completed_at'])
                && $row['assignee'] !== null
                && in_array($row['assignee']['id'], $subjectIds, true))
            ->groupBy(fn (array $row): int => $row['assignee']['id'])
            ->sortBy([fn (Collection $a, Collection $b): int => $b->count() <=> $a->count()
                ?: strcmp($a->first()['assignee']['name'], $b->first()['assignee']['name'])])
            ->values();
        $shown = $members->take(self::BREAKDOWN_MEMBER_LIMIT);

        $labels = $shown->map(fn (Collection $items): string => $items->first()['assignee']['name'])->all();
        $values = $shown->map(fn (Collection $items): int => $items->count())->all();
        $colors = array_slice(self::MEMBER_COLORS, 0, count($labels));

        if ($members->count() > self::BREAKDOWN_MEMBER_LIMIT) {
            $rest = $members->slice(self::BREAKDOWN_MEMBER_LIMIT);
            $labels[] = 'อื่น ๆ ('.$rest->count().' คน)';
            $values[] = $rest->sum(fn (Collection $items): int => $items->count());
            $colors[] = self::OTHERS_COLOR;
        }

        return ['labels' => $labels, 'values' => $values, 'colors' => $colors, 'total' => array_sum($values)];
    }

    /**
     * งานที่ได้รับและงานที่เสร็จของแต่ละช่องเดือน — ช่องมาจาก ReportPeriod::trendBuckets()
     * (รายเดือน: มกราคม → เดือนที่เลือก · ช่วงกำหนดเอง: เฉพาะเดือนที่ช่วงครอบคลุม ตัดขอบให้อยู่ในช่วง)
     *
     * คิวรีครั้งเดียวทั้งช่วง แล้วแบ่งเป็นรายเดือนในหน่วยความจำ ทุกเดือนใช้กติกาเดียวกับ monthJobs():
     * งานที่อนุมัติแล้วซึ่งคนในขอบเขตเป็นผู้รับผิดชอบ สร้างหรือปิดในเดือนนั้น และยุบงานย่อยใต้งานแม่
     * ภายในชุดของเดือนนั้นเอง (ไม่ยุบข้ามเดือน) ตัวเลขจึงเท่ากับการเปิดรายงานของเดือนนั้นตรง ๆ
     *
     * @param  Collection<int, User>  $subjects
     * @return array{keys: list<string>, labels: list<string>, created: list<int>, completed: list<int>, selected: int, all_selected: bool}
     */
    private function monthlyTrend(Collection $subjects, ReportPeriod $period, ?string $project = null): array
    {
        $buckets = $period->trendBuckets();
        $from = $buckets[0]['start']->utc();
        $to = $buckets[array_key_last($buckets)]['end']->utc();
        $jobs = WorkOrder::query()
            ->select(['job_id', 'parent_job_id', 'job_status', 'created_at', 'job_completed_at'])
            ->assignedToAny($subjects->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->when($project, fn (Builder $query, string $project) => $project === self::NO_PROJECT
                ? $query->whereNull('work_order_list_id')
                : $query->where('work_order_list_id', (int) $project))
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('created_at', [$from, $to])
                    ->orWhere(function ($completed) use ($from, $to): void {
                        $completed->where('job_status', 4)
                            ->whereNotNull('job_completed_at')
                            ->whereBetween('job_completed_at', [$from, $to]);
                    });
            })
            ->orderBy('job_id')
            ->get();

        $inBucket = fn (?CarbonInterface $date, array $bucket): bool => $date !== null
            && CarbonImmutable::instance($date)->setTimezone(ReportMetrics::BUSINESS_TIMEZONE)->betweenIncluded($bucket['start'], $bucket['end']);

        // ช่วงกำหนดเอง: ทุกช่องอยู่ในช่วงที่เลือก จึงแสดงเต็มสีทุกแท่ง ไม่มี "เดือนที่เลือก" เดือนเดียว
        $trend = ['keys' => [], 'labels' => [], 'created' => [], 'completed' => [], 'selected' => 0, 'all_selected' => $period->isCustom];

        foreach ($buckets as $bucket) {
            $bucketSet = ReportTaskTree::collapse($jobs->filter(fn (WorkOrder $job): bool => $inBucket($job->created_at, $bucket)
                || (ReportMetrics::isCompleted($job) && $inBucket($job->job_completed_at, $bucket))));

            $trend['keys'][] = $bucket['key'];
            $trend['labels'][] = $bucket['label'];
            $trend['created'][] = $bucketSet->filter(fn (WorkOrder $job): bool => $inBucket($job->created_at, $bucket))->count();
            $trend['completed'][] = $bucketSet->filter(fn (WorkOrder $job): bool => ReportMetrics::isCompleted($job)
                && $inBucket($job->job_completed_at, $bucket))->count();
        }

        $trend['selected'] = count($trend['keys']) - 1;

        return $trend;
    }

    /**
     * ตารางของ CSV — แถวชุดเดียวกับตารางบนหน้าจอ (หลังกรองและเรียงแล้ว ไม่แบ่งหน้า)
     *
     * @param  array<string, mixed>  $report  ผลจาก build()
     * @return array{headers: array<int, string>, rows: array<int, array<int, int|string>>}
     */
    public function csvTable(array $report): array
    {
        // กำหนดส่งและวันที่เสร็จไม่อยู่บนตาราง แต่ยังจำเป็นเมื่อนำไฟล์ไปตรวจต่อ
        $headers = ['ลำดับ', 'เลขงาน', 'วันที่', 'หัวข้อโปรเจกต์', 'ชื่องาน', 'งานย่อย', 'ผู้รับผิดชอบ', 'ผู้เข้าร่วม', 'บทบาท', 'หัวหน้าโปรเจกต์', 'มอบหมายโดย', 'ขอบเขตงาน', 'แผนกที่เกี่ยวข้อง', 'สถานะ', 'กำหนดส่ง', 'วันที่เสร็จ'];

        $rows = $report['taskRows']->values()->map(function (array $row, int $index): array {
            $values = [
                $index + 1,
                'IT-'.$row['id'],
                $row['date']?->format('d/m/Y') ?? '',
                $row['project']['name'],
                $row['topic'],
                implode(', ', $row['subtasks']),
                $row['assignee']['name'] ?? '',
                implode(', ', array_column($row['participants'], 'name')),
                $row['role']['detail'] ?? $row['role']['label'],
                $row['leader'] ?? '',
                $row['assigner'] ?? '',
                $row['scope']['label'],
                $row['department']['name'],
                $row['status']['label'],
                $row['due_at']?->format('d/m/Y H:i') ?? '',
                $row['completed_at']?->format('d/m/Y H:i') ?? '',
            ];

            return $values;
        })->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * งานในช่วงที่เลือก แยกเป็นงานที่รับผิดชอบกับงานที่ร่วมทำ — คิวรีครั้งเดียว แล้วแบ่งในหน่วยความจำ
     *
     * รับผิดชอบ: ยุบงานย่อยด้วยกติกาเดิม ตัวเลขจึงเท่ากับรายงานก่อนมีงานที่ร่วมทำ
     * ร่วมทำ: ตัดงานที่คนในขอบเขตรับผิดชอบอยู่แล้ว และงานย่อยที่งานแม่อยู่ในชุดรับผิดชอบ (เห็นอยู่ใต้งานแม่แล้ว)
     *   แล้วยุบกันเองภายในชุด งานที่ถูกเชิญแค่งานย่อยจึงขึ้นเป็นแถวของงานย่อยนั้น
     *
     * @param  Collection<int, User>  $subjects
     * @return array{owned: Collection<int, WorkOrder>, joined: Collection<int, WorkOrder>}
     */
    private function periodJobs(Collection $subjects, ReportPeriod $period): array
    {
        $start = $period->start->utc();
        $end = $period->end->utc();
        $subjectIds = $subjects->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $jobs = WorkOrder::query()
            ->with([
                'user:id,name,department_id,profile_image',
                'user.department:id,department_name',
                'assignee:id,name',
                'leader:id,name',
                'assigner:id,name',
                'creator:id,name,department_id',
                'creator.department:id,department_name',
                'collaborators:id,name,department_id,profile_image',
                // user_id/created_by/leader_user_id และผู้ร่วมงานของงานย่อย ใช้ตัดงานย่อยที่คนนอกแผนกไม่ควรเห็น
                'children:job_id,parent_job_id,job_topic,job_status,job_start_at,job_due_at,job_completed_at,user_id,created_by,leader_user_id',
                'children.collaborators:id',
                'department:id,department_name',
                'taskList:id,name',
            ])
            ->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $owned) => $owned->assignedToAny($subjectIds))
                ->orWhere(fn (Builder $joined) => $joined->joinedByAny($subjectIds)))
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('created_at', [$start, $end])
                    ->orWhere(function ($completed) use ($start, $end): void {
                        $completed->where('job_status', 4)
                            ->whereNotNull('job_completed_at')
                            ->whereBetween('job_completed_at', [$start, $end]);
                    });
            })
            ->orderBy('job_id')
            ->get();

        $isOwned = fn (WorkOrder $job): bool => in_array((int) $job->user_id, $subjectIds, true);
        $ownedIds = $jobs->filter($isOwned)->pluck('job_id')->map(fn ($id): int => (int) $id)->flip();

        return [
            'owned' => ReportTaskTree::collapse($jobs->filter($isOwned)->values()),
            'joined' => ReportTaskTree::collapse($jobs
                ->reject(fn (WorkOrder $job): bool => $ownedIds->has((int) $job->job_id)
                    || ($job->parent_job_id !== null && $ownedIds->has((int) $job->parent_job_id)))
                ->values()),
        ];
    }

    /**
     * @param  Collection<int, User>  $subjects
     * @return array<string, mixed>
     */
    private function presentJob(WorkOrder $job, Collection $subjects, ?User $owner, CarbonInterface $now, string $role): array
    {
        $statusKey = ReportMetrics::statusKey($job, $now);
        $subjectIds = $subjects->pluck('id')->map(fn ($id): int => (int) $id)->all();
        // คนในขอบเขตที่ร่วมทำงานใบนี้ — ภาพรวมแผนกบอกชื่อไว้ในป้ายบทบาท
        $joinedBy = $role === self::ROLE_JOINED
            ? $job->collaborators
                ->filter(fn (User $member): bool => $member->pivot?->status === 'accepted' && in_array((int) $member->id, $subjectIds, true))
                ->values()
            : collect();
        $perspective = $owner ?? ($role === self::ROLE_OWNED
            ? $subjects->firstWhere('id', (int) $job->user_id)
            : $joinedBy->first());

        if ($role === self::ROLE_JOINED && $perspective !== null) {
            // กติกาเดียวกับบอร์ด: ผู้ร่วมงานข้ามแผนกเห็นเฉพาะงานย่อยที่ตัวเองมีส่วนร่วม
            CrossDepartmentWork::restrictChildren(collect([$job]), $perspective);
        }

        $marker = $perspective === null ? null : CrossDepartmentWork::marker($job, $perspective);
        $team = TaskTeamSummary::for($job, (int) ($owner?->id ?? 0));
        $participants = $job->collaborators
            ->filter(fn (User $member): bool => $member->pivot?->status === 'accepted')
            ->map(fn (User $member): array => $this->person($member))
            ->values()
            ->all();

        return [
            'id' => (int) $job->job_id,
            'topic' => $job->job_topic,
            'url' => route('tasks.show', $job->job_id),
            'date' => $job->created_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
            'project' => [
                'key' => $job->work_order_list_id ? (string) $job->work_order_list_id : self::NO_PROJECT,
                'name' => $job->taskList?->name ?? 'งานทั่วไป',
            ],
            'subtasks' => ReportTaskTree::subtaskNames($job),
            'children' => $job->relationLoaded('children')
                ? $job->children->map(fn (WorkOrder $child): array => [
                    'id' => (int) $child->job_id,
                    'topic' => $child->job_topic,
                    'start_at' => $child->job_start_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
                    'due_at' => $child->job_due_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
                    'completed_at' => $child->job_completed_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
                    'status' => ['key' => $key = ReportMetrics::statusKey($child, $now), ...WorkBoardDesign::statusMeta($key)],
                ])->values()->all()
                : [],
            'start_at' => $job->job_start_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
            'assignee' => $job->user ? $this->person($job->user) : null,
            'participants' => $participants,
            'role' => [
                'key' => $role,
                'label' => self::ROLE_LABELS[$role],
                'detail' => $owner === null && $joinedBy->isNotEmpty()
                    ? 'ร่วมทำโดย '.$joinedBy->pluck('name')->implode(', ')
                    : null,
            ],
            'leader' => $team['leader']['name'] ?? null,
            'assigner' => $team['assigner']['name'] ?? null,
            'scope' => [
                'key' => $marker === null ? self::SCOPE_INTERNAL : self::SCOPE_CROSS,
                'label' => self::SCOPE_LABELS[$marker === null ? self::SCOPE_INTERNAL : self::SCOPE_CROSS],
                'detail' => $marker['label'] ?? null,
                'department' => $this->otherDepartment($job, $marker),
            ],
            'department' => [
                'id' => CrossDepartmentWork::destinationDepartmentId($job),
                'name' => CrossDepartmentWork::destinationDepartmentName($job) ?? 'ไม่ระบุแผนก',
            ],
            'status' => ['key' => $statusKey, ...WorkBoardDesign::statusMeta($statusKey)],
            'due_at' => $job->job_due_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
            'completed_at' => $job->job_completed_at?->copy()->timezone(ReportMetrics::BUSINESS_TIMEZONE),
        ];
    }

    /**
     * แผนกฝั่งตรงข้ามของงานข้ามแผนก — null เมื่อเป็นงานในแผนก
     *
     * ไปร่วมงานของแผนกอื่น (joined) คือแผนกปลายทางของงาน
     * ถูกมอบหมายเข้ามาจากแผนกอื่น (assigned) คือแผนกของผู้สร้างงาน
     * ชื่อมาจาก CrossDepartmentWork::marker ชุดเดียวกับป้ายในคอลัมน์ขอบเขตงาน
     *
     * @param  array{kind: string, department: string, label: string}|null  $marker
     * @return array{id: int, name: string}|null
     */
    private function otherDepartment(WorkOrder $job, ?array $marker): ?array
    {
        if ($marker === null) {
            return null;
        }

        $id = $marker['kind'] === CrossDepartmentWork::JOINED
            ? CrossDepartmentWork::destinationDepartmentId($job)
            : ($job->creator?->department_id === null ? null : (int) $job->creator->department_id);

        return $id === null ? null : ['id' => $id, 'name' => $marker['department']];
    }

    /**
     * ตัวเลือก "ขอบเขตงาน" — ในแผนก / งานข้ามแผนกทั้งหมด / ข้ามแผนก · ชื่อแผนก
     *
     * เดิมเป็นรายชื่อแผนกปลายทางล้วน ๆ (เช่น Account, IT) ซึ่งไม่บอกว่าอันไหนคืองานข้ามแผนก
     * ตอนนี้ป้ายเดียวบอกทั้งว่าข้ามแผนกหรือไม่ และข้ามไปแผนกไหน
     * มีเฉพาะตัวเลือกที่มีงานอยู่จริงในช่วงนั้น จึงไม่มีตัวเลือกที่กดแล้วได้ตารางว่างเสมอ
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function scopeOptions(Collection $rows): array
    {
        $options = [];

        if ($rows->contains(fn (array $row): bool => $row['scope']['key'] === self::SCOPE_INTERNAL)) {
            $options[self::SCOPE_INTERNAL] = 'ในแผนก';
        }

        if ($rows->contains(fn (array $row): bool => $row['scope']['key'] === self::SCOPE_CROSS)) {
            $options[self::SCOPE_CROSS] = 'งานข้ามแผนกทั้งหมด';
        }

        $departments = $rows
            ->where('scope.key', self::SCOPE_CROSS)
            ->pluck('scope.department')
            ->filter()
            ->mapWithKeys(fn (array $department): array => [self::SCOPE_CROSS.':'.$department['id'] => 'ข้ามแผนก · '.$department['name']])
            ->sort()
            ->all();

        return $options + $departments;
    }

    /** @param  array<string, mixed>  $row */
    private function matchesScope(array $row, string $scope): bool
    {
        if ($scope === self::SCOPE_INTERNAL || $scope === self::SCOPE_CROSS) {
            return $row['scope']['key'] === $scope;
        }

        return $row['scope']['key'] === self::SCOPE_CROSS
            && $row['scope']['department'] !== null
            && self::SCOPE_CROSS.':'.$row['scope']['department']['id'] === $scope;
    }

    /**
     * @return array{id: int, name: string, initials: string, avatar: string|null}
     */
    private function person(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'initials' => WorkBoardDesign::initials($user->name),
            // MediaController::profile() ตอบเฉพาะไฟล์ใต้ profiles/ จึงไม่สร้างลิงก์ที่จะได้ 404
            'avatar' => str_starts_with((string) $user->profile_image, 'profiles/') ? route('media.profile', $user) : null,
        ];
    }

    /**
     * ตัวเลือกของตัวกรองมาจากงานในเดือนนั้นจริง จึงไม่มีตัวเลือกที่กดแล้วได้ตารางว่างเสมอ
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<string, string>>
     */
    private function filterOptions(Collection $rows): array
    {
        $projects = $rows
            ->reject(fn (array $row): bool => $row['project']['key'] === self::NO_PROJECT)
            ->mapWithKeys(fn (array $row): array => [$row['project']['key'] => $row['project']['name']])
            ->sort()
            ->all();

        if ($rows->contains(fn (array $row): bool => $row['project']['key'] === self::NO_PROJECT)) {
            $projects[self::NO_PROJECT] = 'งานทั่วไป (ไม่อยู่ในโปรเจกต์)';
        }

        return [
            'projects' => $projects,
            'statuses' => array_map(fn (array $meta): string => $meta['label'], WorkBoardDesign::STATUSES),
            'scopes' => $this->scopeOptions($rows),
            // มีเฉพาะบทบาทที่มีงานอยู่จริง เหมือนตัวเลือกอื่นของการ์ดนี้
            'roles' => array_filter(
                self::ROLE_FILTER_LABELS,
                fn (string $role): bool => $rows->contains('role.key', $role),
                ARRAY_FILTER_USE_KEY
            ),
            'sorts' => self::SORTS,
        ];
    }

    /**
     * ค่าที่ไม่อยู่ในตัวเลือกถูกทิ้งทั้งหมด การแก้ URL จึงไม่มีผลอะไรนอกจากไม่กรอง
     *
     * @param  array<string, array<string, string>>  $options
     * @return array{project: string|null, status: string|null, scope: string|null, role: string|null, q: string|null, sort: string}
     */
    private function normalizeFilters(Request $request, array $options): array
    {
        $pick = function (string $key, array $allowed) use ($request): ?string {
            $value = $request->query($key);

            return is_scalar($value) && array_key_exists((string) $value, $allowed) ? (string) $value : null;
        };
        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, self::SEARCH_LIMIT) : '';

        return [
            'project' => $pick('project', $options['projects']),
            'status' => $pick('status', $options['statuses']),
            'scope' => $pick('scope', $options['scopes']),
            'role' => $pick('role', $options['roles']),
            'q' => $search === '' ? null : $search,
            'sort' => $pick('sort', self::SORTS) ?? self::DEFAULT_SORT,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['project'], fn (Collection $items, string $project) => $items->where('project.key', $project))
            ->when($filters['status'], fn (Collection $items, string $status) => $items->where('status.key', $status))
            ->when($filters['scope'], fn (Collection $items, string $scope) => $items
                ->filter(fn (array $row): bool => $this->matchesScope($row, $scope)))
            ->when($filters['role'], fn (Collection $items, string $role) => $items->where('role.key', $role))
            ->when($filters['q'], fn (Collection $items, string $search) => $items
                ->filter(fn (array $row): bool => collect([$row['topic'], $row['project']['name'], ...$row['subtasks']])
                    ->contains(fn ($text): bool => mb_stripos((string) $text, $search) !== false)))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort): Collection
    {
        $timestamp = fn (array $row): int => $row['date']?->getTimestamp() ?? 0;
        $statusRank = fn (array $row): int => ($rank = array_search($row['status']['key'], self::STATUS_ORDER, true)) === false
            ? count(self::STATUS_ORDER)
            : $rank;

        $sorted = match ($sort) {
            'date_asc' => $rows->sortBy([fn (array $a, array $b): int => $timestamp($a) <=> $timestamp($b) ?: $a['id'] <=> $b['id']]),
            'topic' => $rows->sortBy([fn (array $a, array $b): int => strcmp($a['topic'], $b['topic']) ?: $a['id'] <=> $b['id']]),
            'status' => $rows->sortBy([fn (array $a, array $b): int => $statusRank($a) <=> $statusRank($b) ?: $timestamp($b) <=> $timestamp($a)]),
            default => $rows->sortBy([fn (array $a, array $b): int => $timestamp($b) <=> $timestamp($a) ?: $b['id'] <=> $a['id']]),
        };

        return $sorted->values();
    }

    /**
     * query string ของหน้าปัจจุบัน (ไม่รวม page) — ใช้กับปุ่มแบ่งหน้า ฟอร์มเรียงลำดับ และ CSV
     *
     * @return array<string, int|string>
     */
    private function queryFor(?User $owner, ReportPeriod $period, array $filters): array
    {
        return array_filter([
            'owner' => $owner?->id,
            ...$period->query(),
            'project' => $filters['project'],
            'status' => $filters['status'],
            'scope' => $filters['scope'],
            'role' => $filters['role'],
            'q' => $filters['q'],
            'sort' => $filters['sort'] === self::DEFAULT_SORT ? null : $filters['sort'],
        ], fn ($value): bool => $value !== null);
    }
}
