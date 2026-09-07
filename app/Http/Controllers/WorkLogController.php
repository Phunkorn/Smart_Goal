<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\WorkLogParticipantService;
use App\Services\WorkLogQueryService;
use App\Services\WorkLogRoutineMaterializer;
use App\Services\WorkLogService;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use App\Support\WorkLogSummary;
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
    ) {}

    public function index(Request $request)
    {
        $viewer = Auth::user();
        Gate::authorize('viewAny', WorkLog::class);

        $owner = $this->resolveOwner($request, $viewer);
        Gate::authorize('viewDay', [WorkLog::class, $owner]);

        // ปิดตัวจับเวลาที่ถูกลืมค้างข้ามวันก่อนอ่านข้อมูล
        //
        // ทำตอนเปิดหน้าแทนการพึ่ง cron อย่างเดียว เพราะ deploy/README.md ของระบบนี้
        // ไม่มีขั้นตอนตั้ง schedule:run บนเครื่อง production หากพึ่ง cron แล้วไม่มี
        // ผู้ใช้จะเห็นตัวจับเวลาเดินค้างข้ามวันโดยไม่มีอะไรมาแก้ให้
        // (รูปแบบเดียวกับที่ AuditController::index() เรียก TrashRetention::purgeExpired())
        $this->logs->closeStaleTimers($owner);

        $businessDay = $this->query->resolveBusinessDay($request->query('date'));

        // สร้างรายการงานประจำของวันนี้ให้อัตโนมัติ ด้วยเหตุผลเดียวกับข้างบน
        // คือไม่พึ่ง cron เป็นแหล่งความจริง (ดู WorkLogRoutineMaterializer)
        //
        // ทำเฉพาะ "วันนี้" เท่านั้น วันย้อนหลังต้องให้เจ้าของกดสั่งเอง เพราะการเติม
        // รายการค้างให้คนที่เพิ่งกลับจากลา จะกลายเป็นสัญญาณ "ไม่ได้ทำงาน" ปลอม ๆ
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
            'logs' => $dayLogs,
            'presentedLogs' => $dayLogs->map(fn (WorkLog $log): array => WorkLogPresenter::forClient($log)),
            'summary' => WorkLogSummary::fromLogs($dayLogs),
            'runningLog' => $this->query->runningFor($owner),
            'members' => $this->query->visibleMembersFor($viewer),
            'categories' => WorkLogCategory::query()->selectable()->get(),
            'projects' => $this->projectOptions($owner),
            'tasks' => $this->taskOptions($owner),
            // เพื่อนร่วมแผนกที่เพิ่มเป็นผู้ร่วมงานได้ — เงื่อนไขเดียวกับผู้ร่วมงาน
            // ของงานโครงการ (แผนกเดียวกัน บัญชีเปิดใช้งาน role = user)
            'participantOptions' => $this->participants->candidatesForOwner($owner),
            'capabilities' => $this->capabilities($viewer, $owner, $businessDay),
            'design' => WorkLogDesign::forClient(),
            // งานประจำของวันย้อนหลังที่ยังไม่ได้บันทึก แสดงเป็นรายการจาง ๆ พร้อมปุ่ม
            // ให้เจ้าของกดสร้างเองเมื่อต้องการ ระบบไม่สร้างให้อัตโนมัติ
            'pendingRoutines' => $owner->id === $viewer->id
                ? $this->routines->pendingRoutinesFor($owner, $businessDay)
                : collect(),
            // สร้าง URL จากฝั่ง server เสมอ ฝั่ง client ไม่ประกอบเส้นทางเอง
            // __ID__ เป็นตัวยึดตำแหน่งที่ JavaScript แทนที่ด้วย id จริงตอนเรียก
            'routes' => [
                'store' => route('daily-logs.store'),
                'update' => route('daily-logs.update', ['workLog' => '__ID__']),
                'destroy' => route('daily-logs.destroy', ['workLog' => '__ID__']),
                'timerStart' => route('daily-logs.timer.start'),
                'timerResume' => route('daily-logs.timer.resume', ['workLog' => '__ID__']),
                'timerStop' => route('daily-logs.timer.stop', ['workLog' => '__ID__']),
                'materializeRoutines' => route('daily-logs.routines.materialize'),
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
            'canUseTimer' => $isOwnDay && $businessDay->isSameDay(TodayWorkspace::businessNow()),
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
        $log->loadMissing(['category', 'project', 'task', 'attachments', 'user', 'participants']);

        return [
            'log' => WorkLogPresenter::forClient($log),
            'summary' => $this->summaryFor($owner, $log->work_date),
            'html' => view('daily-logs.components.log-card', [
                'log' => $log,
                'presented' => WorkLogPresenter::forClient($log),
                'capabilities' => ['canEdit' => true, 'canUseTimer' => true, 'isReadOnly' => false],
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
