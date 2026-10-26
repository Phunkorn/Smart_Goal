<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketLocation;
use App\Models\User;
use App\Support\ReportMonth;
use App\Support\ReportPeriod;
use App\Support\TicketAgentAccess;
use App\Support\TicketDesign;
use App\Support\TicketSla;
use App\Support\TodayWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * รายงาน Smart Ticket — KPI ของทีม รายบุคคล และ SLA
 *
 * นิยามหลัก (แสดงในหน้ารายงานด้วย — ส่วน "วิธีคำนวณ")
 * ---------------------------------------------------------------
 * ชุด Ticket ของรายงาน = Ticket ที่ "แจ้งเข้ามา" (tickets.created_at) ภายในช่วงวันที่ตามเวลากรุงเทพ
 *   และผ่านตัวกรองสถานะ ช่องทาง แผนก สาขา เจ้าหน้าที่ — ตัวเลขสรุป กราฟ และตารางใช้ชุดเดียวกันทั้งหมด
 *   (ช่องค้นหา q กรองเฉพาะรายการ Ticket ในตาราง) ยอดจึงไม่ขัดกัน และ Ticket หนึ่งใบนับครั้งเดียวในยอดทีมเสมอ (ไม่ขึ้นกับจำนวนผู้เข้าร่วมหรือการ reopen)
 * สถานะ = สถานะปัจจุบันของแต่ละใบ
 * เวลาตอบรับ = first_responded_at − created_at (ตั้งครั้งเดียวตอนรับงานครั้งแรก ไม่เปลี่ยนเมื่อ reopen)
 *   ใบที่ยังไม่ถูกรับไม่อยู่ในค่าเฉลี่ย (ไม่ถือเป็น 0 นาที)
 * เวลาแก้ไข (elapsed) = closed_at ครั้งล่าสุด − created_at ของใบที่ปิดอยู่ตอนนี้ เป็นเวลาปฏิทิน
 *   ไม่ใช่เวลาปฏิบัติงานจริง (ระบบยังไม่บันทึกเวลาทำงาน) ใบที่ reopen แล้วยังเปิดอยู่ไม่อยู่ในค่าเฉลี่ย
 * ปิดงานภายในช่วง = ใบที่ปิดอยู่ตอนนี้และ closed_at อยู่ในช่วงวันที่ ไม่ว่าแจ้งเมื่อใด — แยกจากยอดหลัก
 *
 * ประสิทธิภาพ: KPI ทีมคำนวณจากคิวรีคอลัมน์แคบครั้งเดียว (ไม่โหลด model) เพราะการลบเวลาระหว่าง
 * timestamp ต่างกันระหว่าง MySQL กับ SQLite ส่วนรายบุคคลรวมผลด้วย SQL (COUNT DISTINCT)
 */
class TicketReportService
{
    public const PEOPLE_PER_PAGE = 15;

    public const TICKETS_PER_PAGE = 20;

    /**
     * โดนัทแผนกแสดงอันดับแรกเท่านี้ (ชุดสีของกราฟรายงานที่ตรวจแล้วมี 5 สี) — แผนกที่เหลือรวมเป็นชิ้น "อื่น ๆ"
     * ผลรวมทุกชิ้นจึงเท่ากับ Ticket ทั้งหมดเสมอ
     */
    public const CHART_DEPARTMENTS = 5;

    /** ช่วงที่ยาวกว่านี้แสดงแนวโน้มรายเดือนแทนรายวัน — แท่งรายวันเกินนี้แคบจนอ่านไม่ออก */
    public const DAILY_TREND_MAX_DAYS = 62;

    public const DEPARTMENT_PUBLIC = 'public';

    public const DEPARTMENT_NONE = 'none';

    /**
     * ตัวตนทางประวัติของผู้เข้าร่วม/ผู้ปิดงาน — ref ไม่ถูกล้างเมื่อบัญชีถูกลบถาวร (migration 2026_10_23_000008)
     * coalesce กับ FK ไว้ เผื่อแถวที่ถูกเขียนโดยเส้นทางที่ไม่ได้ตั้ง ref
     */
    private const PARTICIPANT_REF = 'coalesce(ticket_participants.user_ref_id, ticket_participants.user_id)';

    private const CLOSER_REF = 'coalesce(tickets.closed_by_ref_id, tickets.closed_by)';

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $period = ReportPeriod::fromRequest($request);
        $options = $this->options();
        $filters = $this->filters($request, $options);
        // ช่องค้นหาในรายการ Ticket กรองเฉพาะรายการ — ไม่มีผลกับกราฟและตัวเลขสรุป (ชุด Ticket ของรายงานคงเดิม)
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $query = array_filter([...$period->query(), ...$filters, 'q' => $search], fn ($value) => $value !== '');
        // สองตารางแบ่งหน้าแยกกัน — เปลี่ยนหน้าตารางหนึ่งต้องไม่รีเซ็ตอีกตาราง
        $pages = array_filter([
            'page' => max(1, (int) $request->query('page', 1)),
            'people_page' => max(1, (int) $request->query('people_page', 1)),
        ], fn (int $page) => $page > 1);

        $rows = $this->ticketsQuery($period, $filters)
            ->toBase()
            ->select(['tickets.id', 'tickets.status', 'tickets.source', 'tickets.created_at', 'tickets.first_responded_at', 'tickets.closed_at',
                'tickets.reopen_count', 'tickets.requester_department_id', 'tickets.requester_department_name', 'tickets.location_id', 'tickets.location_name'])
            ->orderBy('tickets.id')
            ->get();

        $durations = $this->durations($rows);
        $sla = $this->sla($rows);
        $byDepartment = $this->byDepartment($rows);
        $byLocation = $this->byLocation($rows);
        $trend = $this->trend($rows, $period);

        return [
            'period' => $period,
            'periodPhrase' => $period->phrase(),
            'monthKey' => $period->selectValue(),
            'monthOptions' => [ReportPeriod::CUSTOM => 'กำหนดช่วงวันที่เอง…'] + ReportMonth::options(),
            'isCustomPeriod' => $period->isCustom,
            'periodFrom' => $period->fromValue(),
            'periodTo' => $period->toValue(),
            'filters' => $filters,
            'search' => $search,
            'query' => $query,
            'options' => $options,
            'kpis' => $this->kpis($rows, $durations, $sla),
            'sla' => $sla,
            'watch' => $this->watch($rows, $sla),
            'durations' => $durations,
            'closedInPeriod' => $this->closedInPeriod($period, $filters),
            'bySource' => $this->bySource($rows),
            'byDepartment' => $byDepartment,
            'byLocation' => $byLocation,
            'trend' => $trend,
            // กราฟ 2 ใบ — ตัวเลขชุดเดียวกับตาราง (ตารางคือข้อมูลหลัก กราฟล้มก็ยังอ่านได้ครบ)
            'chartData' => [
                'trend' => ['labels' => $trend['labels'], 'values' => $trend['values'], 'unit' => $trend['unit']],
                'departments' => $this->departmentChart($byDepartment),
            ],
            'people' => $this->people($period, $filters, $request, [...$query, ...array_intersect_key($pages, ['page' => true])]),
            'tickets' => $this->ticketsQuery($period, $filters)
                ->when($search !== '', function (Builder $tickets) use ($search): void {
                    // ไม่ escape % และ _ — เหมือนคิวงาน (TicketQueryService::filtered) การ escape ด้วย backslash ใช้ไม่ได้กับ SQLite
                    $term = '%'.$search.'%';
                    $tickets->where(fn (Builder $match) => $match
                        ->where('tickets.ticket_number', 'like', $term)
                        ->orWhere('tickets.description', 'like', $term)
                        ->orWhere('tickets.requester_name', 'like', $term));
                })
                ->select(['id', 'ticket_number', 'description', 'status', 'source', 'requester_name', 'requester_department_name',
                    'location_name', 'coordinator_id', 'closed_by_ref_id', 'created_at', 'first_responded_at', 'closed_at', 'reopen_count'])
                // ผู้เข้าร่วมทุกคน (รวมที่ออกแล้ว) จาก snapshot — คิวรีเดียวต่อหน้า ผู้ประสานงานและผู้ปิดหาได้จากชุดนี้
                ->with(['participants' => fn ($participants) => $participants
                    ->select(['id', 'ticket_id', 'user_id', 'user_ref_id', 'user_name', 'left_at'])
                    ->orderBy('joined_at')])
                ->latest('created_at')->orderByDesc('id')
                ->paginate(self::TICKETS_PER_PAGE, ['*'], 'page')
                ->onEachSide(1)
                ->appends([...$query, ...array_intersect_key($pages, ['people_page' => true])]),
            'total' => $rows->count(),
        ];
    }

    /* ---------------------------------------------------------------
     * ตัวกรอง
     * ------------------------------------------------------------- */

    /** @return array{statuses: array<string, string>, departments: Collection, locations: Collection, agents: Collection} */
    private function options(): array
    {
        // ทุกคนที่เคยเข้าร่วม (รวมบัญชีที่ลบถาวรแล้วแต่ยังมีตัวตนทางประวัติ) + agent ปัจจุบัน
        $refs = DB::table('ticket_participants')
            ->selectRaw(self::PARTICIPANT_REF.' as ref')
            ->whereRaw(self::PARTICIPANT_REF.' is not null')
            ->distinct()
            ->pluck('ref')
            ->merge(TicketAgentAccess::agentsQuery()->pluck('id'));

        $agents = collect($this->identities($refs))
            ->map(fn (array $identity, int $ref) => [
                'id' => $ref,
                'name' => $identity['name'],
                'label' => $identity['name'].($identity['state'] ? ' ('.$identity['state'].')' : '').' · '.($identity['department'] ?? 'ไม่ระบุแผนก'),
            ])
            ->sortBy('name')
            ->values();

        return [
            'statuses' => collect(TicketDesign::statuses())->map(fn ($design) => $design['label'])->all(),
            'sources' => collect(TicketDesign::sources())->map(fn ($design) => $design['label'])->all(),
            'departments' => Department::query()->orderBy('department_name')->get(['id', 'department_name']),
            'locations' => TicketLocation::query()->ordered()->get(['id', 'name']),
            'agents' => $agents,
        ];
    }

    /**
     * ค่าที่ไม่รู้จักถูกทิ้ง — การแก้ URL ได้อย่างมากแค่รายงานที่ไม่กรอง
     *
     * @return array{status: string, source: string, department: string, location: string, agent: string}
     */
    private function filters(Request $request, array $options): array
    {
        $status = (string) $request->query('status', '');
        $source = (string) $request->query('source', '');
        $department = (string) $request->query('department', '');
        $location = (string) $request->query('location', '');
        $agent = (string) $request->query('agent', '');

        return [
            'status' => array_key_exists($status, $options['statuses']) ? $status : '',
            'source' => array_key_exists($source, $options['sources']) ? $source : '',
            'department' => in_array($department, [self::DEPARTMENT_PUBLIC, self::DEPARTMENT_NONE], true)
                || $options['departments']->contains('id', (int) $department) && ctype_digit($department) ? $department : '',
            'location' => $location === TicketQueryService::LOCATION_OTHER
                || $options['locations']->contains('id', (int) $location) && ctype_digit($location) ? $location : '',
            'agent' => ctype_digit($agent) && $options['agents']->contains('id', (int) $agent) ? $agent : '',
        ];
    }

    /** Ticket ที่แจ้งในช่วง (เวลากรุงเทพ → UTC ตอนคิวรี) และผ่านตัวกรอง */
    private function ticketsQuery(ReportPeriod $period, array $filters, bool $byCreatedDate = true): Builder
    {
        return Ticket::query()
            ->when($byCreatedDate, fn (Builder $query) => $query->whereBetween('tickets.created_at', [$period->start->utc(), $period->end->utc()]))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('tickets.status', $filters['status']))
            ->when($filters['source'] !== '', fn (Builder $query) => $query->where('tickets.source', $filters['source']))
            ->when($filters['department'] === self::DEPARTMENT_PUBLIC, fn (Builder $query) => $query->where('tickets.source', Ticket::SOURCE_PUBLIC))
            ->when($filters['department'] === self::DEPARTMENT_NONE, fn (Builder $query) => $query
                ->where('tickets.source', Ticket::SOURCE_INTERNAL)->whereNull('tickets.requester_department_id'))
            ->when(ctype_digit($filters['department']), fn (Builder $query) => $query->where('tickets.requester_department_id', (int) $filters['department']))
            ->when($filters['location'] === TicketQueryService::LOCATION_OTHER, fn (Builder $query) => $query->whereNull('tickets.location_id'))
            ->when(ctype_digit($filters['location']), fn (Builder $query) => $query->where('tickets.location_id', (int) $filters['location']))
            ->when($filters['agent'] !== '', fn (Builder $query) => $query->whereExists(fn (QueryBuilder $participant) => $participant
                ->selectRaw('1')->from('ticket_participants')
                ->whereColumn('ticket_participants.ticket_id', 'tickets.id')
                ->whereRaw(self::PARTICIPANT_REF.' = ?', [(int) $filters['agent']])));
    }

    /* ---------------------------------------------------------------
     * KPI ของทีม — Ticket ละ 1 เสมอ
     * ------------------------------------------------------------- */

    /**
     * @return array{response: array{average: float|null, count: int}, resolution: array{average: float|null, count: int}}
     */
    private function durations(Collection $rows): array
    {
        $response = $rows->filter(fn ($row) => $row->first_responded_at !== null)
            ->map(fn ($row) => $this->minutesBetween($row->created_at, $row->first_responded_at));
        $resolution = $rows->filter(fn ($row) => $row->status === Ticket::STATUS_CLOSED && $row->closed_at !== null)
            ->map(fn ($row) => $this->minutesBetween($row->created_at, $row->closed_at));

        return [
            'response' => ['average' => $response->isEmpty() ? null : $response->avg(), 'count' => $response->count()],
            'resolution' => ['average' => $resolution->isEmpty() ? null : $resolution->avg(), 'count' => $resolution->count()],
        ];
    }

    /**
     * SLA — แสดงเฉพาะเมื่อกำหนดเกณฑ์แล้ว
     *
     * ตอบรับ:  ผ่าน = รับงานภายในเกณฑ์ · ไม่ผ่าน = รับงานช้ากว่าเกณฑ์ หรือยังไม่รับและเลยเกณฑ์แล้ว
     *          รอผล = ยังไม่รับแต่ยังไม่เลยเกณฑ์ (ไม่อยู่ในตัวหาร)
     * แก้ไข:   ผ่าน = ปิดอยู่และปิดภายในเกณฑ์ (closed_at ครั้งล่าสุด) · ไม่ผ่าน = ปิดช้ากว่าเกณฑ์ หรือยังไม่ปิดและเลยเกณฑ์แล้ว
     *          รอผล = ยังไม่ปิดและยังไม่เลยเกณฑ์ · งานที่ต้องติดตาม (follow_up) ไม่หยุดนาฬิกา
     *          ใบที่ reopen แล้วยังเปิดอยู่ถือเป็น "ยังไม่ปิด"
     *
     * @return array<string, mixed>
     */
    private function sla(Collection $rows): array
    {
        $now = CarbonImmutable::now();
        $result = [];

        foreach ([
            'response' => [TicketSla::responseMinutes(), fn ($row) => $row->first_responded_at],
            'resolution' => [TicketSla::resolutionMinutes(), fn ($row) => $row->status === Ticket::STATUS_CLOSED ? $row->closed_at : null],
        ] as $key => [$targetMinutes, $finishedAt]) {
            if ($targetMinutes === null) {
                $result[$key] = ['configured' => false];

                continue;
            }

            $met = $missed = $pending = 0;
            foreach ($rows as $row) {
                $end = $finishedAt($row);
                $elapsed = $this->minutesBetween($row->created_at, $end ?? $now);

                if ($end !== null) {
                    $elapsed <= $targetMinutes ? $met++ : $missed++;
                } elseif ($elapsed > $targetMinutes) {
                    $missed++;
                } else {
                    $pending++;
                }
            }

            $measured = $met + $missed;
            $result[$key] = [
                'configured' => true,
                'target_minutes' => $targetMinutes,
                'met' => $met,
                'missed' => $missed,
                'pending' => $pending,
                'target_label' => self::formatMinutes($targetMinutes),
                'percent' => $measured > 0 ? round($met / $measured * 100, 1) : null,
            ];
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function kpis(Collection $rows, array $durations, array $sla): array
    {
        $statusCount = fn (string $status) => $rows->where('status', $status)->count();
        $duration = fn (array $item, string $subject) => [
            'value' => $item['average'] === null ? '—' : self::formatMinutes($item['average']),
            'note' => $item['average'] === null ? 'ยังไม่มี '.$subject : 'เฉลี่ยจาก '.number_format($item['count']).' Ticket',
        ];
        $response = $duration($durations['response'], 'Ticket ที่รับงานแล้ว');
        $resolution = $duration($durations['resolution'], 'Ticket ที่ปิดแล้ว');
        // การ์ดสถานะไม่มีคำอธิบายซ้ำ ๆ — ความหมาย "สถานะปัจจุบัน" อยู่ในส่วนวิธีคำนวณแล้ว
        $cards = [
            ['key' => 'total', 'label' => 'Ticket ทั้งหมด', 'value' => number_format($rows->count()), 'note' => 'แจ้งเข้ามาในช่วงนี้', 'icon' => 'bi-inbox', 'tone' => 'accent'],
            ['key' => 'new', 'label' => 'รอรับงาน', 'value' => number_format($statusCount(Ticket::STATUS_NEW)), 'note' => null, 'icon' => 'bi-hourglass', 'tone' => 'accent'],
            ['key' => 'active', 'label' => 'กำลังดำเนินการ', 'value' => number_format($statusCount(Ticket::STATUS_ACTIVE)), 'note' => null, 'icon' => 'bi-play-circle', 'tone' => 'blue'],
            ['key' => 'follow_up', 'label' => 'งานที่ต้องติดตาม', 'value' => number_format($statusCount(Ticket::STATUS_FOLLOW_UP)), 'note' => null, 'icon' => 'bi-hourglass-split', 'tone' => 'amber'],
            ['key' => 'closed', 'label' => 'ปิดงานแล้ว', 'value' => number_format($statusCount(Ticket::STATUS_CLOSED)), 'note' => null, 'icon' => 'bi-check-circle', 'tone' => 'green'],
            ['key' => 'response', 'label' => 'เวลาตอบรับเฉลี่ย', ...$response, 'icon' => 'bi-stopwatch', 'tone' => 'blue'],
            ['key' => 'resolution', 'label' => 'เวลาแก้ไขเฉลี่ย', ...$resolution, 'icon' => 'bi-clock-history', 'tone' => 'blue'],
        ];

        // การ์ด SLA มีเฉพาะเกณฑ์ที่ตั้งไว้ — ไม่ตั้ง = ไม่มีการ์ด ไม่มีตัวเลขให้เข้าใจผิด
        foreach (['response' => 'SLA ตอบรับ', 'resolution' => 'SLA ปิดงาน'] as $key => $label) {
            $item = $sla[$key];
            if (! $item['configured']) {
                continue;
            }

            $cards[] = [
                'key' => 'sla_'.$key,
                'label' => $label.' ('.$item['target_label'].')',
                'value' => $item['percent'] === null ? '—' : self::formatPercent($item['percent']),
                'note' => $item['percent'] === null
                    ? 'ยังไม่มี Ticket ที่วัดผลได้'
                    : 'ผ่าน '.number_format($item['met']).' จาก '.number_format($item['met'] + $item['missed']).' Ticket',
                'icon' => 'bi-speedometer2',
                'tone' => 'gray',
            ];
        }

        return $cards;
    }

    /**
     * Ticket ที่ต้องดูแล — นับจากชุดเดียวกับการ์ด (Ticket ละ 1 ในแต่ละข้อ ข้อต่าง ๆ ซ้อนกันได้)
     *
     * @return array<int, array{key: string, label: string, count: int|null, formula: string}>
     */
    private function watch(Collection $rows, array $sla): array
    {
        $now = CarbonImmutable::now();
        $open = $rows->where('status', '!=', Ticket::STATUS_CLOSED);
        $resolution = $sla['resolution'];

        return [
            ['key' => 'unaccepted', 'label' => 'ยังไม่รับงาน', 'count' => $rows->where('status', Ticket::STATUS_NEW)->count(),
                'formula' => 'สถานะปัจจุบัน = รอรับงาน (ยังไม่มี first_responded_at)'],
            ['key' => 'open', 'label' => 'ยังไม่ปิด', 'count' => $open->count(),
                'formula' => 'สถานะปัจจุบัน ≠ ปิดงาน (รอรับงาน + กำลังดำเนินการ + งานที่ต้องติดตาม)'],
            ['key' => 'overdue', 'label' => 'ยังไม่ปิดและเกิน SLA',
                'count' => $resolution['configured']
                    ? $open->filter(fn ($row) => $this->minutesBetween($row->created_at, $now) > $resolution['target_minutes'])->count()
                    : null,
                'formula' => $resolution['configured']
                    ? 'ยังไม่ปิด และ เวลาตอนนี้ − created_at > '.$resolution['target_label']
                    : 'ต้องตั้งเกณฑ์ SLA ปิดงานก่อน จึงจะนับได้'],
            ['key' => 'reopened', 'label' => 'เคยเปิดงานใหม่', 'count' => $rows->filter(fn ($row) => (int) $row->reopen_count > 0)->count(),
                'formula' => 'reopen_count > 0 (นับ Ticket ละ 1 ไม่ว่าเปิดใหม่กี่ครั้ง)'],
            ['key' => 'follow_up', 'label' => 'งานที่ต้องติดตาม', 'count' => $rows->where('status', Ticket::STATUS_FOLLOW_UP)->count(),
                'formula' => 'สถานะปัจจุบัน = งานที่ต้องติดตาม'],
        ];
    }

    private static function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1), '0'), '.').'%';
    }

    /** ปิดงานภายในช่วง (นับตามวันที่ปิด ไม่ว่าแจ้งเมื่อใด) — ตัวกรองอื่นยังมีผล ยกเว้นสถานะ */
    private function closedInPeriod(ReportPeriod $period, array $filters): int
    {
        return $this->ticketsQuery($period, ['status' => ''] + $filters, byCreatedDate: false)
            ->where('tickets.status', Ticket::STATUS_CLOSED)
            ->whereBetween('tickets.closed_at', [$period->start->utc(), $period->end->utc()])
            ->count();
    }

    /**
     * จำนวน Ticket ต่อช่องทางที่แจ้ง (tickets.source) — ทุกช่องทางแสดงเสมอ แม้เป็น 0 · ผลรวม = Ticket ทั้งหมด
     *
     * @return array<string, array{label: string, icon: string, count: int}>
     */
    private function bySource(Collection $rows): array
    {
        $counts = $rows->countBy('source');

        return collect(TicketDesign::sources())
            ->map(fn (array $design, string $source) => [...$design, 'count' => (int) ($counts[$source] ?? 0)])
            ->all();
    }

    /**
     * จัดกลุ่มตามแผนกผู้แจ้งจาก snapshot ณ เวลาแจ้ง — เปลี่ยนชื่อแผนกภายหลังไม่แก้รายงานย้อนหลัง
     *
     * @return array<int, array{label: string, count: int, percent: float}>
     */
    private function byDepartment(Collection $rows): array
    {
        return $this->ranked($rows, function ($row): array {
            if ($row->source === Ticket::SOURCE_PUBLIC) {
                return ['public', 'บุคคลภายนอก'];
            }

            return [
                'department:'.($row->requester_department_id ?? 'none').':'.$row->requester_department_name,
                $row->requester_department_name ?? 'ไม่ระบุแผนก',
            ];
        });
    }

    /**
     * ข้อมูลโดนัทแผนก: อันดับแรก CHART_DEPARTMENTS ชิ้น + "อื่น ๆ (n แผนก)" รวมจำนวนจริงของแผนกที่เหลือ
     *
     * @param  array<int, array{label: string, count: int, percent: float}>  $byDepartment  เรียงจากมากไปน้อยแล้ว
     * @return array{labels: array<int, string>, values: array<int, int>, other: bool}
     */
    private function departmentChart(array $byDepartment): array
    {
        $top = array_slice($byDepartment, 0, self::CHART_DEPARTMENTS);
        $rest = array_slice($byDepartment, self::CHART_DEPARTMENTS);
        $labels = array_column($top, 'label');
        $values = array_column($top, 'count');

        if ($rest !== []) {
            $labels[] = 'อื่น ๆ ('.count($rest).' แผนก)';
            $values[] = array_sum(array_column($rest, 'count'));
        }

        return ['labels' => $labels, 'values' => $values, 'other' => $rest !== []];
    }

    /** สาขาจาก snapshot (location_name) — "อื่น ๆ" ที่พิมพ์เองแยกตามข้อความ */
    private function byLocation(Collection $rows): array
    {
        return $this->ranked($rows, fn ($row): array => [
            'location:'.($row->location_id ?? 'other').':'.$row->location_name,
            $row->location_name.($row->location_id === null ? ' (ระบุเอง)' : ''),
        ]);
    }

    /** @return array<int, array{label: string, count: int, percent: float}> */
    private function ranked(Collection $rows, callable $keyOf): array
    {
        $total = max($rows->count(), 1);
        $groups = [];

        foreach ($rows as $row) {
            [$key, $label] = $keyOf($row);
            $groups[$key] ??= ['label' => $label, 'count' => 0];
            $groups[$key]['count']++;
        }

        return collect($groups)
            ->map(fn (array $group) => [...$group, 'percent' => round($group['count'] / $total * 100, 1)])
            ->sortBy([['count', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Ticket ที่แจ้งต่อวัน (ช่วงสั้น) หรือต่อเดือน (ช่วงยาว) ตามวันที่เวลากรุงเทพ — ผลรวมทุกช่อง = Ticket ทั้งหมด
     *
     * @return array{labels: array<int, string>, values: array<int, int>, unit: string, peak: array{label: string, count: int}|null}
     */
    private function trend(Collection $rows, ReportPeriod $period): array
    {
        $daily = (int) round($period->start->diffInDays($period->end)) + 1 <= self::DAILY_TREND_MAX_DAYS;
        $buckets = [];

        if ($daily) {
            for ($day = $period->start; $day->lte($period->end); $day = $day->addDay()) {
                $buckets[$day->format('Y-m-d')] = ['label' => $day->format('j').' '.$day->locale('th')->translatedFormat('M'), 'count' => 0];
            }
        } else {
            foreach ($period->trendBuckets() as $bucket) {
                if ($bucket['end']->lt($period->start)) {
                    continue;
                }
                $buckets[$bucket['key']] = ['label' => $bucket['label'], 'count' => 0];
            }
        }

        foreach ($rows as $row) {
            $local = TodayWorkspace::businessNow(CarbonImmutable::parse($row->created_at, 'UTC'));
            $key = $local->format($daily ? 'Y-m-d' : 'Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        $peak = collect($buckets)->sortByDesc('count')->first();

        return [
            'labels' => array_column($buckets, 'label'),
            'values' => array_column($buckets, 'count'),
            'unit' => $daily ? 'วัน' : 'เดือน',
            'peak' => $peak && $peak['count'] > 0 ? $peak : null,
        ];
    }

    /* ---------------------------------------------------------------
     * รายบุคคล — COUNT DISTINCT ต่อ Ticket ไม่ใช่จำนวน activity
     * ------------------------------------------------------------- */

    /**
     * ตัวตนของแต่ละแถว = ตัวตนทางประวัติ (user_ref_id / closed_by_ref_id) — ไม่ใช่ชื่อ
     * - ชื่อซ้ำกันคนละบัญชี → คนละแถว
     * - คนเดียวกันหลาย Ticket → แถวเดียว แม้บัญชีถูกลบถาวรไปแล้ว (ref ไม่ถูกล้าง)
     * - ปิดงาน นับจาก closed_by_ref_id จึงยังรู้ผู้ปิดหลังบัญชีถูกลบถาวร
     * แถวเก่าที่ถูกลบถาวรก่อนมี ref (ไม่มีทั้ง ref และ user_id) ไม่มีข้อมูลใดยืนยันตัวตนได้
     * จึงแยกทีละการเข้าร่วม และ "ปิดงาน" เป็น null (แสดง "—") ไม่เดาด้วยชื่อ
     */
    private function people(ReportPeriod $period, array $filters, Request $request, array $query): LengthAwarePaginator
    {
        $ticketIds = $this->ticketsQuery($period, $filters)->select('tickets.id');

        $participation = DB::table('ticket_participants')
            ->join('tickets', 'tickets.id', '=', 'ticket_participants.ticket_id')
            ->whereIn('ticket_participants.ticket_id', $ticketIds)
            ->whereRaw(self::PARTICIPANT_REF.' is not null')
            ->groupByRaw(self::PARTICIPANT_REF)
            ->selectRaw(self::PARTICIPANT_REF.' as ref, count(distinct ticket_participants.ticket_id) as participated')
            ->selectRaw('count(distinct case when ticket_participants.left_at is null and tickets.status <> ? then ticket_participants.ticket_id end) as still_involved', [Ticket::STATUS_CLOSED])
            ->get()
            ->keyBy('ref');

        $closed = $this->ticketsQuery($period, $filters)->toBase()
            ->where('tickets.status', Ticket::STATUS_CLOSED)
            ->whereRaw(self::CLOSER_REF.' is not null')
            ->groupByRaw(self::CLOSER_REF)
            ->selectRaw(self::CLOSER_REF.' as ref, count(*) as closed')
            ->pluck('closed', 'ref');

        $refs = $participation->keys()->merge($closed->keys());
        // agent ปัจจุบันที่ยังไม่มีส่วนร่วมในช่วงนี้ แสดงเป็น 0 ให้เห็นทั้งทีม (เมื่อไม่ได้กรองเจ้าหน้าที่)
        if ($filters['agent'] === '') {
            $refs = $refs->merge(TicketAgentAccess::agentsQuery()->pluck('id'));
        }

        $rows = collect($this->identities($refs))->map(fn (array $identity, int $ref) => [
            'key' => 'user:'.$ref,
            ...$identity,
            'participated' => (int) ($participation[$ref]->participated ?? 0),
            'closed' => (int) ($closed[$ref] ?? 0),
            'still_involved' => (int) ($participation[$ref]->still_involved ?? 0),
        ])->values();

        $unidentified = DB::table('ticket_participants')
            ->join('tickets', 'tickets.id', '=', 'ticket_participants.ticket_id')
            ->whereIn('ticket_participants.ticket_id', $ticketIds)
            ->whereRaw(self::PARTICIPANT_REF.' is null')
            ->orderBy('ticket_participants.id')
            ->get(['ticket_participants.id', 'ticket_participants.user_name', 'ticket_participants.left_at', 'tickets.status'])
            ->map(fn ($row) => [
                'key' => 'participant:'.$row->id,
                'name' => $row->user_name,
                'department' => null,
                'state' => 'บัญชีถูกลบถาวร',
                'participated' => 1,
                // ไม่มีตัวตนทางประวัติ → ระบุไม่ได้ว่าปิดงานไหน — ไม่แสดงเป็น 0
                'closed' => null,
                'still_involved' => $row->left_at === null && $row->status !== Ticket::STATUS_CLOSED ? 1 : 0,
            ]);

        $all = $rows->concat($unidentified)
            // กรองเจ้าหน้าที่ = ดูคนนั้นคนเดียว (ชุด Ticket ถูกกรองแล้ว แต่เพื่อนร่วมงานในใบเดียวกันไม่ควรโผล่)
            ->when($filters['agent'] !== '', fn (Collection $all) => $all->where('key', 'user:'.$filters['agent']))
            ->sort(fn (array $a, array $b) => [$b['participated'], $b['closed'] ?? -1, $a['name']]
                <=> [$a['participated'], $a['closed'] ?? -1, $b['name']])
            ->values();

        $page = max(1, (int) $request->query('people_page', 1));

        return (new LengthAwarePaginator(
            $all->forPage($page, self::PEOPLE_PER_PAGE)->values(),
            $all->count(),
            self::PEOPLE_PER_PAGE,
            $page,
            ['path' => $request->url(), 'pageName' => 'people_page'],
        ))->onEachSide(1)->appends($query);
    }

    /**
     * ชื่อ แผนก และสถานะบัญชีของตัวตนทางประวัติ — สองคิวรีเสมอ ไม่ขึ้นกับจำนวนคน
     *
     * บัญชียังอยู่ (รวมลบชั่วคราว) → อ่านจาก users
     * บัญชีถูกลบถาวร → ชื่อ snapshot ล่าสุดในประวัติการเข้าร่วมของ ref นั้น แผนกไม่ทราบ
     *
     * @param  Collection<int, int|string>  $refs
     * @return array<int, array{name: string, department: string|null, state: string|null}>
     */
    private function identities(Collection $refs): array
    {
        $refs = $refs->map(fn ($ref) => (int) $ref)->unique()->values();
        $users = User::withTrashed()->with('department')->whereIn('id', $refs)
            ->get(['id', 'name', 'department_id', 'is_active', 'deleted_at'])->keyBy('id');
        $missing = $refs->diff($users->keys())->values();

        // เรียงจากเก่าไปใหม่ — pluck เก็บค่าหลังสุดต่อ key จึงได้ชื่อล่าสุด
        $snapshots = $missing->isEmpty() ? collect() : DB::table('ticket_participants')
            ->whereIn('user_ref_id', $missing)
            ->orderBy('id')
            ->pluck('user_name', 'user_ref_id');

        return $refs->mapWithKeys(function (int $ref) use ($users, $snapshots): array {
            $user = $users->get($ref);

            return [$ref => $user
                ? [
                    'name' => $user->name,
                    'department' => $user->department?->department_name,
                    'state' => $user->trashed() ? 'ลบบัญชีแล้ว' : (! $user->is_active ? 'ปิดใช้งาน' : null),
                ]
                : [
                    'name' => (string) ($snapshots[$ref] ?? 'ไม่ทราบชื่อ'),
                    'department' => null,
                    'state' => 'บัญชีถูกลบถาวร',
                ]];
        })->all();
    }

    /* ---------------------------------------------------------------
     * เวลา
     * ------------------------------------------------------------- */

    private function minutesBetween(mixed $from, mixed $to): float
    {
        $start = $from instanceof CarbonImmutable ? $from : CarbonImmutable::parse((string) $from, 'UTC');
        $end = $to instanceof CarbonImmutable ? $to : CarbonImmutable::parse((string) $to, 'UTC');

        return max(0.0, ($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    /** ระยะเวลาสำหรับแสดงผล: "45 นาที", "2 ชม. 15 นาที", "3 วัน 4 ชม." — null = "—" */
    public static function formatMinutes(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $minutes = (int) round($minutes);
        if ($minutes < 60) {
            return $minutes.' นาที';
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;

        return $days > 0
            ? $days.' วัน'.($hours > 0 ? ' '.$hours.' ชม.' : '')
            : $hours.' ชม.'.($rest > 0 ? ' '.$rest.' นาที' : '');
    }

    /** ระยะเวลาของ Ticket หนึ่งใบในตาราง — ไม่มีข้อมูล = null (แสดง "—") ไม่ใช่ 0 */
    public static function durationOf(Ticket $ticket, string $field): ?string
    {
        $end = $field === 'closed_at' && $ticket->status !== Ticket::STATUS_CLOSED ? null : $ticket->{$field};

        if ($end === null || $ticket->created_at === null) {
            return null;
        }

        return self::formatMinutes(max(0, ($end->getTimestamp() - $ticket->created_at->getTimestamp()) / 60));
    }
}
