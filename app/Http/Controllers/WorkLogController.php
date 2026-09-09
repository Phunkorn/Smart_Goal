<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\RoutineAttentionService;
use App\Services\WorkLogParticipantService;
use App\Services\WorkLogQueryService;
use App\Services\WorkLogRoutineMaterializer;
use App\Services\WorkLogService;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use App\Support\WorkLogSummary;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * หน้า "บันทึกงานประจำวัน" — ไทม์ไลน์งานปฏิบัติการรายวันของคนหนึ่งคน
 *
 * หน้าเดียวใช้ได้ทั้งพนักงาน หัวหน้าแผนก และ admin ความต่างของสิทธิ์ส่งไปที่ Blade
 * ผ่านตัวแปร $capabilities ที่คำนวณจาก policy ฝั่ง server ไม่ใช่การซ่อนปุ่มด้วย
 * เงื่อนไข role ใน Blade หรือ JavaScript ตามกติกาใน CLAUDE.md
 */
class WorkLogController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly WorkLogService $logs,
        private readonly WorkLogQueryService $query,
        private readonly WorkLogRoutineMaterializer $routines,
        private readonly WorkLogParticipantService $participants,
        private readonly RoutineAttentionService $routineAttention,
    ) {}

    public function routineStatus(Request $request)
    {
        $viewer = Auth::user();
        $owner = $this->resolveOwner($request, $viewer);
        Gate::authorize('viewDay', [WorkLog::class, $owner]);

        $attention = $this->routineAttention->summary($owner, $owner->is($viewer));
        $logs = $this->query->dayFor($owner, TodayWorkspace::businessNow())
            ->whereNotNull('work_log_template_id')
            ->map(fn (WorkLog $log): array => WorkLogPresenter::forClient($log));

        return response()->json([
            'attention' => collect($attention)->except('items')->all(),
            'items' => $logs->values(),
            'fingerprint' => sha1($logs->map(fn (array $log): array => [
                $log['id'], $log['status'], $log['display_status'], $log['started_at'], $log['ended_at'],
            ])->toJson()),
        ]);
    }

    public function index(Request $request)
    {
        $viewer = Auth::user();
        Gate::authorize('viewAny', WorkLog::class);

        $owner = $this->resolveOwner($request, $viewer);
        Gate::authorize('viewDay', [WorkLog::class, $owner]);

        // เก็บกวาดตัวจับเวลาที่ค้างอยู่จากรุ่นก่อนหน้า
        //
        // ระบบจับเวลาถูกถอดออกไปแล้ว แต่ฐานข้อมูลที่ใช้งานอยู่ก่อนหน้ายังมีแถวที่
        // ค้างสถานะ "กำลังจับเวลา" ได้ ถ้าไม่ปิดให้ รายการนั้นจะค้างอยู่ตลอดไป
        // โดยไม่มีปุ่มไหนในหน้าจอปิดมันได้อีก
        // (รูปแบบเดียวกับที่ AuditController::index() เรียก TrashRetention::purgeExpired())
        $this->logs->closeLeftoverTimers($owner);

        $businessDay = $this->query->resolveBusinessDay($request->query('date'));

        // สร้างรายการงานประจำของวันนี้ให้อัตโนมัติ ด้วยเหตุผลเดียวกับข้างบน
        // คือไม่พึ่ง cron เป็นแหล่งความจริง (ดู WorkLogRoutineMaterializer)
        //
        // ทำเฉพาะ "วันนี้" เท่านั้น วันที่ผ่านไปแล้วสร้างรายการเพื่อเริ่มงานย้อนหลัง
        // ไม่ได้เด็ดขาด เหลือทางเดียวคือระบุเหตุผลที่ไม่ได้ทำ (missRoutine)
        if ($businessDay->isSameDay(TodayWorkspace::businessNow())) {
            $this->routines->materializeToday($owner);
        }

        $dayLogs = $this->query->dayFor($owner, $businessDay);

        return view('daily-logs.index', [
            'owner' => $owner,
            'isOwnDay' => $owner->id === $viewer->id,
            'businessDay' => $businessDay,
            'dateValue' => $businessDay->format('Y-m-d'),
            'dateLabel' => TodayWorkspace::dateRangeLabel($businessDay, $businessDay),
            'isToday' => $businessDay->isSameDay(TodayWorkspace::businessNow()),
            'previousDate' => $businessDay->copy()->subDay()->format('Y-m-d'),
            'nextDate' => $this->nextDateOrNull($businessDay),
            // ขอบเขตของปฏิทินในหัวหน้าจอ — เลือกได้ถึงวันนี้ และย้อนหลังได้ไกล
            // เท่าที่ระบบยอมให้กรอกข้อมูลย้อนหลัง (WorkLogDesign::MAX_BACKFILL_DAYS)
            'minDate' => TodayWorkspace::businessNow()
                ->startOfDay()
                ->subDays(WorkLogDesign::MAX_BACKFILL_DAYS)
                ->format('Y-m-d'),
            'maxDate' => TodayWorkspace::businessNow()->format('Y-m-d'),
            'logs' => $dayLogs,
            'presentedLogs' => $dayLogs->map(fn (WorkLog $log): array => WorkLogPresenter::forClient($log)),
            'routineFingerprint' => sha1($dayLogs->whereNotNull('work_log_template_id')->map(fn (WorkLog $log): array => [
                $log->id,
                $log->status,
                WorkLogPresenter::forClient($log)['display_status'],
                $log->started_at?->toIso8601String(),
                $log->ended_at?->toIso8601String(),
            ])->toJson()),
            'summary' => WorkLogSummary::fromLogs($dayLogs),
            'members' => $this->query->visibleMembersFor($viewer),
            'categories' => WorkLogCategory::query()->selectable()->get(),
            'projects' => $this->projectOptions($owner),
            'tasks' => $this->taskOptions($owner),
            // เพื่อนร่วมแผนกที่เพิ่มเป็นผู้ร่วมงานได้ — เงื่อนไขเดียวกับผู้ร่วมงาน
            // ของงานโครงการ (แผนกเดียวกัน บัญชีเปิดใช้งาน role = user)
            'participantOptions' => $this->participants->candidatesForOwner($owner),
            // งานประจำถูกจัดการใน modal ของหน้านี้ ไม่มีหน้าแยกอีกต่อไป
            'routineTemplates' => $this->routineTemplatesOf($owner),
            'sharedRoutines' => $this->sharedRoutinesFor($owner),
            'weekdays' => WorkLogWeekdays::WEEKDAYS,
            'defaultMask' => WorkLogWeekdays::WORKWEEK,
            'capabilities' => $this->capabilities($viewer, $owner, $businessDay),
            'design' => WorkLogDesign::forClient(),
            // งานประจำของวันย้อนหลังที่ไม่มีรายการเลย แสดงเป็นรายการจาง ๆ พร้อมปุ่ม
            // "ระบุเหตุผล" ปุ่มนั้นบันทึกว่าไม่ได้ทำ ไม่ใช่สร้างงานย้อนหลังให้ทำต่อ
            'pendingRoutines' => $owner->id === $viewer->id
                ? $this->routines->pendingRoutinesFor($owner, $businessDay)
                : collect(),
            // สร้าง URL จากฝั่ง server เสมอ ฝั่ง client ไม่ประกอบเส้นทางเอง
            // __ID__ เป็นตัวยึดตำแหน่งที่ JavaScript แทนที่ด้วย id จริงตอนเรียก
            'routes' => [
                'store' => route('daily-logs.store'),
                'update' => route('daily-logs.update', ['workLog' => '__ID__']),
                'destroy' => route('daily-logs.destroy', ['workLog' => '__ID__']),
                'complete' => route('daily-logs.complete', ['workLog' => '__ID__']),
                'start' => route('daily-logs.start', ['workLog' => '__ID__']),
                'skip' => route('daily-logs.skip', ['workLog' => '__ID__']),
                'reopen' => route('daily-logs.reopen', ['workLog' => '__ID__']),
                // ระบุเหตุผลที่ไม่ได้ทำงานประจำของวันย้อนหลัง __TEMPLATE__ เป็น
                // ตัวยึดตำแหน่งแบบเดียวกับ __ID__ ของบันทึกงาน
                'routineMissed' => route('daily-logs.routines.missed', ['template' => '__TEMPLATE__']),
                'routineStatus' => route('daily-logs.routine-status', $owner->is($viewer) ? [] : ['user' => $owner->id]),
                'attachmentStore' => route('daily-logs.attachments.store', ['workLog' => '__ID__']),
                'attachmentDestroy' => route('daily-logs.attachments.destroy', [
                    'workLog' => '__ID__',
                    'attachment' => '__ATTACHMENT__',
                ]),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', WorkLog::class);

        $actor = Auth::user();
        $data = $request->validate($this->validationRules());

        $log = $this->logs->create($actor, $actor, $data);
        $this->participants->sync($log, $actor, $request->input('participants', []));

        return $this->jsonOrBack(
            $request,
            true,
            'บันทึกงานเรียบร้อย',
            200,
            $this->mutationPayload($actor, $log)
        );
    }

    public function update(Request $request, WorkLog $workLog)
    {
        Gate::authorize('update', $workLog);

        $actor = Auth::user();
        $data = $request->validate($this->validationRules());

        $workLog = $this->logs->update($workLog, $actor, $data);
        $this->participants->sync($workLog, $actor, $request->input('participants', []));

        return $this->jsonOrBack(
            $request,
            true,
            'แก้ไขบันทึกงานเรียบร้อย',
            200,
            $this->mutationPayload($actor, $workLog)
        );
    }

    /**
     * ยืนยันว่าทำรายการนี้เสร็จแล้ว
     *
     * เส้นทางหลักของงานประจำ — ระบบวางรายการของวันไว้ให้ตามที่ตั้งค่า เจ้าของ
     * เข้าไปทำจริงแล้วกลับมากดยืนยันหนึ่งครั้ง รายการจึงย้ายไปกลุ่ม "ทำแล้ว"
     * ตัวจับเวลายังมีอยู่สำหรับงานที่ต้องรู้เวลาจริง แต่ไม่ใช่ทางเดียวที่จะปิดงานได้
     */
    public function complete(Request $request, WorkLog $workLog)
    {
        Gate::authorize('update', $workLog);

        $actor = Auth::user();
        $data = $request->validate(['late_completion_reason' => ['nullable', 'string', 'max:500']]);
        $workLog = $this->logs->markDone($workLog, $actor, $data['late_completion_reason'] ?? null);

        return $this->jsonOrBack(
            $request,
            true,
            'บันทึกว่าทำเสร็จแล้ว',
            200,
            $this->mutationPayload($actor, $workLog)
        );
    }

    public function start(Request $request, WorkLog $workLog)
    {
        Gate::authorize('update', $workLog);

        $actor = Auth::user();
        $data = $request->validate(['late_start_reason' => ['nullable', 'string', 'max:500']]);
        $workLog = $this->logs->startRoutine($workLog, $actor, $data['late_start_reason'] ?? null);

        return $this->jsonOrBack($request, true, 'เริ่มงานประจำแล้ว', 200, $this->mutationPayload($actor, $workLog));
    }

    public function skip(Request $request, WorkLog $workLog)
    {
        Gate::authorize('update', $workLog);

        $actor = Auth::user();
        $data = $request->validate(['skip_reason' => ['required', 'string', 'max:500']]);
        $workLog = $this->logs->skipRoutine($workLog, $actor, $data['skip_reason']);

        return $this->jsonOrBack($request, true, 'บันทึกเหตุผลที่ไม่ได้ทำวันนี้แล้ว', 200, $this->mutationPayload($actor, $workLog));
    }

    /**
     * ระบุเหตุผลที่ไม่ได้ทำงานประจำของวันที่ผ่านมา
     *
     * วันที่ผ่านไปแล้วสร้างรายการเพื่อ "เริ่มงาน" ไม่ได้เด็ดขาด สิ่งที่ทำได้คือ
     * บันทึกว่าวันนั้นไม่ได้ทำเพราะอะไร (ลืม ลา ขาด วันหยุด) ซึ่งลงเป็นรายการ
     * สถานะ "ไม่ได้ทำ" ทันทีโดยไม่ต้องกดอะไรต่ออีก
     */
    public function missRoutine(Request $request, WorkLogTemplate $template)
    {
        Gate::authorize('create', WorkLog::class);

        $actor = Auth::user();
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $log = $this->routines->recordMissed(
            $actor,
            $template,
            $this->query->resolveBusinessDay($data['date']),
            $data['reason']
        );

        return $this->jsonOrBack(
            $request,
            true,
            'บันทึกเหตุผลที่ไม่ได้ทำงานประจำวันนั้นแล้ว',
            200,
            $this->mutationPayload($actor, $log)
        );
    }

    /**
     * ยกเลิกการยืนยัน — กดผิดรายการแล้วต้องแก้กลับได้
     */
    public function reopen(Request $request, WorkLog $workLog)
    {
        Gate::authorize('update', $workLog);

        $actor = Auth::user();
        $workLog = $this->logs->reopen($workLog, $actor);

        return $this->jsonOrBack(
            $request,
            true,
            'ย้ายกลับไปรายการที่ต้องทำแล้ว',
            200,
            $this->mutationPayload($actor, $workLog)
        );
    }

    public function destroy(Request $request, WorkLog $workLog)
    {
        Gate::authorize('delete', $workLog);

        $actor = Auth::user();
        $day = $workLog->work_date;

        $this->logs->delete($workLog, $actor);

        return $this->jsonOrBack(
            $request,
            true,
            'ลบบันทึกงานเรียบร้อย',
            200,
            ['summary' => $this->summaryFor($actor, $day), 'deleted_id' => $workLog->id]
        );
    }

    /**
     * งานประจำที่ผู้ใช้คนนี้เป็นเจ้าของ
     *
     * @return Collection<int, WorkLogTemplate>
     */
    private function routineTemplatesOf(User $owner): Collection
    {
        return WorkLogTemplate::query()
            ->with('participants:id,name')
            ->where('user_id', $owner->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * งานประจำที่เพื่อนร่วมแผนกตั้งไว้แล้วใส่ชื่อผู้ใช้คนนี้เป็นผู้ร่วมงาน
     *
     * ต้องเห็นได้เหมือนกัน ไม่งั้นจะไม่มีทางรู้ว่าตัวเองมีงานอะไรต้องทำทุกเช้าบ้าง
     *
     * @return Collection<int, WorkLogTemplate>
     */
    private function sharedRoutinesFor(User $owner): Collection
    {
        return WorkLogTemplate::query()
            ->with('user:id,name')
            ->whereHas('participants', fn ($person) => $person->where('users.id', $owner->id))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * เจ้าของไทม์ไลน์ที่กำลังเปิดดู — ตัวเองเป็นค่าเริ่มต้น
     *
     * การส่ง ?user= ที่ไม่มีอยู่จริงถือเป็น 404 ส่วนการไม่มีสิทธิ์ดูเป็นหน้าที่ของ
     * policy ที่ผู้เรียกต้องตรวจต่อ เพื่อให้ข้อความ error สื่อความหมายต่างกัน
     */
    private function resolveOwner(Request $request, User $viewer): User
    {
        $requestedId = $request->integer('user');

        if ($requestedId === 0 || $requestedId === $viewer->id) {
            return $viewer;
        }

        return User::query()->findOrFail($requestedId);
    }

    /**
     * ความสามารถที่ Blade ใช้ตัดสินว่าจะแสดงปุ่มอะไร
     *
     * ทุกค่ามาจาก policy ฝั่ง server เสมอ Blade และ JavaScript ห้ามตัดสินสิทธิ์เอง
     */
    private function capabilities(User $viewer, User $owner, CarbonInterface $businessDay): array
    {
        $isOwnDay = $viewer->id === $owner->id;

        return [
            'canCreate' => $isOwnDay && Gate::forUser($viewer)->allows('create', WorkLog::class),
            'canEdit' => $isOwnDay,
            'canViewOthers' => $this->query->visibleMembersFor($viewer)->isNotEmpty(),
            'isReadOnly' => ! $isOwnDay,
        ];
    }

    /**
     * ข้อมูลที่ส่งกลับหลังแก้ไขสำเร็จ ให้หน้าจออัปเดตเองโดยไม่ต้อง reload
     *
     * ส่ง HTML ของแถวมาจาก server ด้วย เพื่อไม่ให้ JavaScript ต้องมีเทมเพลต
     * ของแถวเป็นชุดที่สอง ซึ่งจะเพี้ยนออกจาก Blade ทันทีที่ดีไซน์เปลี่ยน
     */
    private function mutationPayload(User $owner, WorkLog $log): array
    {
        $log->loadMissing(['category', 'project', 'task', 'attachments', 'user', 'participants', 'template.user']);

        return [
            'log' => WorkLogPresenter::forClient($log),
            'summary' => $this->summaryFor($owner, $log->work_date),
            'html' => view('daily-logs.components.log-card', [
                'log' => $log,
                'presented' => WorkLogPresenter::forClient($log),
                'capabilities' => ['canEdit' => true, 'isReadOnly' => false],
            ])->render(),
        ];
    }

    private function summaryFor(User $owner, ?CarbonInterface $day): array
    {
        if ($day === null) {
            return WorkLogSummary::fromLogs(collect());
        }

        return WorkLogSummary::fromLogs($this->query->dayFor($owner, $day));
    }

    private function nextDateOrNull(CarbonInterface $businessDay): ?string
    {
        $next = $businessDay->copy()->addDay();

        return $next->greaterThan(TodayWorkspace::businessNow()->startOfDay())
            ? null
            : $next->format('Y-m-d');
    }

    /**
     * @return Collection<int, WorkOrderList>
     */
    private function projectOptions(User $owner): Collection
    {
        return WorkOrderList::query()
            ->where('user_id', $owner->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    private function taskOptions(User $owner): Collection
    {
        return WorkOrder::query()
            ->where('user_id', $owner->id)
            ->whereNull('deleted_at')
            ->orderByDesc('job_id')
            ->limit(100)
            ->get(['job_id', 'job_topic', 'work_order_list_id']);
    }

    /**
     * กติกา validation ของฟอร์มบันทึกงาน
     *
     * ความสัมพันธ์ระหว่างช่วงเวลากับจำนวนนาทีถูกตรวจใน WorkLogService เพราะ
     * เป็นกฎทางธุรกิจที่ต้องบังคับเหมือนกันทุกทางเข้า ไม่ใช่แค่ทางฟอร์มนี้
     */
    private function validationRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'string', 'in:'.implode(',', WorkLogDesign::kindKeys())],
            'work_log_category_id' => ['nullable', 'integer', 'exists:work_log_categories,id'],
            'work_order_list_id' => ['nullable', 'integer', 'exists:work_order_lists,id'],
            'job_id' => ['nullable', 'integer', 'exists:work_orders,job_id'],
            'work_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:'.WorkLogDesign::MIN_DURATION_MINUTES, 'max:'.WorkLogDesign::MAX_DURATION_MINUTES],
            'details' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'requester_name' => ['nullable', 'string', 'max:120'],
            // ตรวจแค่รูปแบบตรงนี้ ส่วนกติกาว่าใครเพิ่มได้บ้าง (แผนกเดียวกัน)
            // อยู่ที่ WorkLogParticipantService ซึ่งเป็นแหล่งความจริงเดียว
            'participants' => ['nullable', 'array', 'max:20'],
            'participants.*' => ['integer', 'exists:users,id'],
        ];
    }
}
