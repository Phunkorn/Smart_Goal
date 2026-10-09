<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\ComputerCheckSession;
use App\Models\ComputerCheckWorkstation;
use App\Services\ComputerCheckReportService;
use App\Services\ComputerCheckService;
use App\Services\WorkLogParticipantService;
use App\Support\ComputerCheckDesign;
use App\Support\TodayWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * ตรวจเช็กคอมพิวเตอร์ประจำวัน — ผังห้อง Call Center แบบกดได้
 *
 * เข้าได้จาก "บันทึกงานประจำวัน › เพิ่มงาน › ตรวจเช็กคอมพิวเตอร์ประจำวัน"
 * หน้านี้แทนแบบฟอร์มกระดาษ IT26-0-01 ทั้งใบ ตั้งแต่เดินตรวจจนได้แบบตรวจ A4
 *
 * หน้าเดียวใช้ได้ทั้งพนักงาน หัวหน้าแผนก และ admin ความต่างของสิทธิ์ส่งไปที่
 * Blade ผ่าน $capabilities ที่คำนวณจาก policy ฝั่ง server ไม่ใช่การซ่อนปุ่ม
 * ด้วยเงื่อนไข role ใน Blade หรือ JavaScript ตามกติกาใน CLAUDE.md
 */
class ComputerCheckController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly ComputerCheckService $checks,
        private readonly ComputerCheckReportService $reports,
        private readonly WorkLogParticipantService $participants,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', ComputerCheckSession::class);

        $viewer = Auth::user();
        $today = TodayWorkspace::businessNow();

        $session = $this->resolveSession($request, $viewer);

        // ผังถูกวาดจากแถวผลของรอบเสมอ รอบที่ยังไม่เปิดจึงวาดจากผังปัจจุบัน
        // โดยให้ทุกเครื่องเป็น "ยังไม่ตรวจ" เพื่อให้ผู้ใช้เห็นห้องก่อนกดเริ่ม
        $stations = $session === null
            ? $this->previewStations()
            : $this->reports->stations($this->reports->resultsOf($session));

        $results = $session === null ? collect() : $this->reports->resultsOf($session);
        $summary = ComputerCheckService::summarize($results);

        $capabilities = [
            'canCreate' => Gate::allows('create', ComputerCheckSession::class),
            'canEdit' => $session !== null && Gate::allows('update', $session),
            'canComplete' => $session !== null && Gate::allows('complete', $session),
        ];

        return view('daily-logs.computer-check.index', [
            'session' => $session,
            'stations' => $stations,
            'results' => $results,
            'summary' => $summary,
            'issues' => $session === null ? [] : $this->reports->issues($results),
            'header' => $session === null ? null : $this->reports->header($session),
            'history' => $this->reports->historyRows($this->historySessions($viewer)),
            'capabilities' => $capabilities,
            'design' => ComputerCheckDesign::forClient(),
            'checkerOptions' => $this->participants->candidatesForOwner($viewer),
            'viewer' => $viewer,
            'dateValue' => $request->date('date')?->format('Y-m-d') ?? $today->format('Y-m-d'),
            'shiftValue' => $this->defaultShift($request, $today),
            'minDate' => $today->copy()->subDays(ComputerCheckDesign::MAX_BACKFILL_DAYS)->format('Y-m-d'),
            'maxDate' => $today->format('Y-m-d'),
            'activeTab' => in_array($request->query('tab'), ['plan', 'list', 'summary', 'history'], true)
                ? $request->query('tab')
                : 'plan',
            // URL ถูกสร้างจากฝั่งเซิร์ฟเวอร์เสมอ ฝั่ง client ไม่ประกอบเส้นทางเอง
            // __ID__ / __STATION__ เป็นตัวยึดตำแหน่งที่ JavaScript แทนที่ตอนเรียก
            'routes' => [
                'start' => route('daily-logs.computer-check.store'),
                'saveResult' => route('daily-logs.computer-check.results.update', [
                    'session' => '__ID__',
                    'workstation' => '__STATION__',
                ]),
                'markNormal' => route('daily-logs.computer-check.mark-normal', ['session' => '__ID__']),
                'complete' => route('daily-logs.computer-check.complete', ['session' => '__ID__']),
                'report' => route('daily-logs.computer-check.report', ['session' => '__ID__']),
                'index' => route('daily-logs.computer-check.index'),
            ],
        ]);
    }

    /**
     * เปิดรอบตรวจของวัน+กะที่เลือก (หรือเข้าต่อรอบเดิมถ้ามีอยู่แล้ว)
     */
    public function store(Request $request)
    {
        Gate::authorize('create', ComputerCheckSession::class);

        $actor = Auth::user();

        // ตรวจรูปแบบที่นี่ ส่วนกติกา "ย้อนหลังได้ไม่เกินกี่วัน" และ "ผู้ตรวจคนที่ 2
        // ต้องเป็นใครได้บ้าง" อยู่ที่ ComputerCheckService ซึ่งเป็นกฎทางธุรกิจ
        $data = $request->validate([
            'check_date' => ['required', 'date_format:Y-m-d'],
            'shift' => ['required', Rule::in(ComputerCheckDesign::shiftKeys())],
            'checker_2_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $session = $this->checks->start(
            $actor,
            $data['check_date'],
            $data['shift'],
            $data['checker_2_id'] ?? null
        );

        return $this->jsonOrBack(
            $request,
            true,
            'เปิดรอบตรวจแล้ว',
            200,
            [
                'session_id' => $session->id,
                'redirect' => route('daily-logs.computer-check.index', ['session' => $session->id]),
            ]
        );
    }

    /**
     * บันทึกผลของหนึ่งเครื่อง
     */
    public function saveResult(Request $request, ComputerCheckSession $session, ComputerCheckWorkstation $workstation)
    {
        Gate::authorize('update', $session);

        $rules = ['note' => ['nullable', 'string', 'max:'.ComputerCheckDesign::MAX_NOTE_LENGTH]];

        foreach (ComputerCheckDesign::deviceKeys() as $device) {
            $rules[$device] = ['required', Rule::in(ComputerCheckDesign::deviceStatusKeys())];
        }

        $data = $request->validate($rules);

        $this->checks->saveResult(
            $session,
            $workstation,
            Auth::user(),
            $data,
            $data['note'] ?? null
        );

        return $this->jsonOrBack($request, true, 'บันทึกผลเรียบร้อย', 200, $this->statePayload($session));
    }

    /**
     * ตั้งหลายเครื่องให้ "ปกติทั้งหมด" พร้อมกัน
     *
     * ไม่มีเส้นทางคู่กันสำหรับ "มีปัญหา" โดยตั้งใจ — ดูเหตุผลที่
     * ComputerCheckService::markNormal()
     */
    public function markNormal(Request $request, ComputerCheckSession $session)
    {
        Gate::authorize('update', $session);

        $data = $request->validate([
            'workstations' => ['required', 'array', 'min:1'],
            'workstations.*' => ['integer'],
        ]);

        $count = $this->checks->markNormal($session, Auth::user(), $data['workstations']);

        return $this->jsonOrBack(
            $request,
            true,
            sprintf('ตั้งเป็นปกติแล้ว %d เครื่อง', $count),
            200,
            $this->statePayload($session)
        );
    }

    /**
     * ปิดรอบและสร้างบันทึกงานประจำวันหนึ่งรายการ
     */
    public function complete(Request $request, ComputerCheckSession $session)
    {
        Gate::authorize('complete', $session);

        $session = $this->checks->complete($session, Auth::user());

        return $this->jsonOrBack(
            $request,
            true,
            'บันทึกผลการตรวจเรียบร้อย',
            200,
            [
                ...$this->statePayload($session),
                'work_log_id' => $session->work_log_id,
                'report_url' => route('daily-logs.computer-check.report', ['session' => $session->id]),
            ]
        );
    }

    /**
     * แบบตรวจ A4 — สร้างจากข้อมูลที่บันทึกไว้จริง ไม่ใช่ภาพหน้าจอ
     *
     * ใช้ layout ของตัวเองแทน layouts.app เพราะหน้านี้ต้องพิมพ์ลงกระดาษและถูก
     * ฝังใน iframe ของกล่องพรีวิว เมนูข้าง แถบบน และสคริปต์ของหน้าปกติไม่ควรติดไปด้วย
     *
     * ?embed=1 คือกรณีที่ถูกฝังใน iframe — ซ่อนแถบปุ่มด้านบนเพราะกล่องพรีวิว
     * มีปุ่มของตัวเองอยู่แล้ว
     */
    public function report(Request $request, ComputerCheckSession $session)
    {
        Gate::authorize('view', $session);

        return view('daily-logs.computer-check.report', [
            ...$this->reports->build($session),
            'embedded' => $request->boolean('embed'),
        ]);
    }

    /**
     * รอบที่กำลังเปิดดูอยู่
     *
     * ?session= ระบุรอบตรงตัว (ใช้ตอนเปิดจากประวัติ) ถ้าไม่ระบุจะหารอบของวัน
     * และกะที่เลือกไว้ ซึ่งตรงกับพฤติกรรมของกระดาษ: หยิบใบของรอบนี้มาตรวจต่อ
     */
    private function resolveSession(Request $request, $viewer): ?ComputerCheckSession
    {
        $sessionId = $request->integer('session');

        if ($sessionId > 0) {
            $session = ComputerCheckSession::query()
                ->with(['checkerOne:id,name', 'checkerTwo:id,name'])
                ->find($sessionId);

            if ($session === null || ! Gate::forUser($viewer)->allows('view', $session)) {
                return null;
            }

            return $session;
        }

        $date = $request->date('date')?->format('Y-m-d') ?? TodayWorkspace::businessNow()->format('Y-m-d');
        $shift = $request->query('shift');

        if (! in_array($shift, ComputerCheckDesign::shiftKeys(), true)) {
            return null;
        }

        $session = ComputerCheckSession::query()
            ->with(['checkerOne:id,name', 'checkerTwo:id,name'])
            ->whereDate('check_date', $date)
            ->where('shift', $shift)
            ->first();

        return $session !== null && Gate::forUser($viewer)->allows('view', $session) ? $session : null;
    }

    /**
     * ผังตั้งต้นก่อนเปิดรอบ — ทุกเครื่องเป็น "ยังไม่ตรวจ"
     *
     * ใช้รูปแบบเดียวกับ ComputerCheckReportService::stations() เป๊ะ ๆ เพื่อให้
     * Blade ของผังมีทางเดินเส้นเดียว ไม่ต้องมีสาขาพิเศษสำหรับหน้าก่อนเริ่มตรวจ
     *
     * @return array<int, array<string, mixed>>
     */
    private function previewStations(): array
    {
        $pending = ComputerCheckDesign::workstationStatus('pending');

        return ComputerCheckWorkstation::query()
            ->active()
            ->get()
            ->map(static fn (ComputerCheckWorkstation $workstation): array => [
                'id' => (int) $workstation->id,
                'code' => (string) $workstation->code,
                'zone' => (string) $workstation->zone,
                'x' => (int) $workstation->position_x,
                'y' => (int) $workstation->position_y,
                'width' => (int) $workstation->width,
                'height' => (int) $workstation->height,
                'status' => 'pending',
                'status_label' => $pending['label'],
                'status_icon' => $pending['icon'],
                'devices' => array_fill_keys(ComputerCheckDesign::deviceKeys(), 'pending'),
                'note' => null,
                'checked_by' => null,
                'checked_at' => null,
            ])
            ->all();
    }

    /**
     * ประวัติที่ผู้ใช้คนนี้เปิดดูได้
     *
     * กรองด้วย policy ทีละใบแทนการเขียนเงื่อนไขสิทธิ์ซ้ำเป็น SQL เพราะกติกามี
     * แหล่งเดียวคือ ComputerCheckSessionPolicy จำนวนรอบต่อวันมีแค่สองใบ
     * การดึง 60 ใบล่าสุดมากรองจึงไม่ใช่ปัญหาด้านประสิทธิภาพ
     */
    private function historySessions($viewer)
    {
        return ComputerCheckSession::query()
            ->with(['results', 'checkerOne:id,name', 'checkerTwo:id,name'])
            ->recentFirst()
            ->limit(60)
            ->get()
            ->filter(fn (ComputerCheckSession $session): bool => Gate::forUser($viewer)->allows('view', $session))
            ->values();
    }

    /**
     * สถานะล่าสุดของทั้งรอบ ส่งกลับทุกครั้งที่มีการเขียน เพื่อให้หน้าจอวาดตัวเลข
     * สรุปจากค่าของเซิร์ฟเวอร์ ไม่ใช่คำนวณเองแล้วค่อย ๆ เพี้ยนออกจากฐานข้อมูล
     *
     * @return array<string, mixed>
     */
    private function statePayload(ComputerCheckSession $session): array
    {
        $results = $this->reports->resultsOf($session);

        return [
            'stations' => $this->reports->stations($results),
            'summary' => ComputerCheckService::summarize($results),
            'issues' => $this->reports->issues($results),
            'session_status' => $session->status,
        ];
    }

    /**
     * กะเริ่มต้นตามเวลาจริง — ก่อนเที่ยงคือรอบเช้า
     */
    private function defaultShift(Request $request, $today): string
    {
        $requested = $request->query('shift');

        if (in_array($requested, ComputerCheckDesign::shiftKeys(), true)) {
            return $requested;
        }

        return $today->hour < 12 ? 'AM' : 'PM';
    }
}
