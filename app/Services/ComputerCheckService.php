<?php

namespace App\Services;

use App\Models\ComputerCheckResult;
use App\Models\ComputerCheckSession;
use App\Models\ComputerCheckWorkstation;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Support\AuditTrail;
use App\Support\ComputerCheckDesign;
use App\Support\TodayWorkspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การเขียนข้อมูลทั้งหมดของ "ตรวจเช็กคอมพิวเตอร์ประจำวัน"
 *
 * ทุกฟิลด์ที่เป็นเวลา (started_at, completed_at, checked_at) และสถานะของรอบ
 * ต้องผ่านที่นี่ที่เดียว controller ห้ามผูกค่าจาก request ลงคอลัมน์เหล่านี้ตรง ๆ
 * ด้วยเหตุผลเดียวกับ WorkLogService: เวลาที่ใช้ตรวจถูกนำไปเขียนเป็นเวลาของ
 * บันทึกงานประจำวันต่อ ถ้าเขียนได้หลายทางตัวเลขในรายงานจะเพี้ยนโดยไม่มีใครรู้
 *
 * การสร้างบันทึกงานประจำวันเกิดที่ complete() ครั้งเดียวต่อหนึ่งรอบ
 * ไม่ใช่หนึ่งรายการต่อหนึ่งเครื่อง
 */
class ComputerCheckService
{
    public function __construct(
        private readonly WorkLogService $logs,
        private readonly WorkLogParticipantService $participants,
    ) {}

    /**
     * เปิดรอบตรวจของวัน+กะหนึ่ง หรือคืนรอบเดิมถ้ามีอยู่แล้ว
     *
     * กระดาษจริงมีใบเดียวต่อวันต่อกะ unique index จึงบังคับกติกานั้นไว้ที่ฐานข้อมูล
     * ที่นี่จับ QueryException จาก race (กดเริ่มจากสองเครื่องพร้อมกัน) แล้วอ่านแถว
     * ที่อีกฝั่งเพิ่งสร้างกลับมาแทนการโยน error ใส่ผู้ใช้
     */
    public function start(User $actor, string $checkDate, string $shift, ?int $checkerTwoId = null): ComputerCheckSession
    {
        $businessDay = $this->businessDay($checkDate);
        $date = $businessDay->format('Y-m-d');

        $existing = ComputerCheckSession::query()
            ->whereDate('check_date', $date)
            ->where('shift', $shift)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($checkerTwoId !== null && (int) $checkerTwoId === (int) $actor->id) {
            throw ValidationException::withMessages([
                'checker_2_id' => 'ผู้ตรวจคนที่ 2 ต้องเป็นคนละคนกับผู้ตรวจคนที่ 1',
            ]);
        }

        $this->assertEligibleSecondChecker($actor, $checkerTwoId);

        try {
            return DB::transaction(function () use ($actor, $date, $shift, $checkerTwoId): ComputerCheckSession {
                $session = ComputerCheckSession::create([
                    // แผนกเป็น snapshot ตอนเปิดรอบ เพื่อให้ประวัติยังอยู่กับหัวหน้า
                    // แผนกที่ดูแลอยู่ในตอนนั้น แม้ผู้ตรวจจะย้ายแผนกภายหลัง
                    'department_id' => $actor->department_id,
                    'check_date' => $date,
                    'shift' => $shift,
                    'checker_1_id' => $actor->id,
                    'checker_2_id' => $checkerTwoId,
                    // เวลาเริ่มเก็บอัตโนมัติ ไม่ใช่ค่าที่ผู้ใช้กรอก
                    'started_at' => now(),
                    'status' => 'in_progress',
                    'created_by' => $actor->id,
                ]);

                $this->seedResults($session);

                AuditTrail::log(
                    'computer_check_started',
                    $session,
                    sprintf(
                        'เริ่มตรวจเช็กคอมพิวเตอร์ %s %s',
                        ComputerCheckDesign::ROOM_NAME,
                        ComputerCheckDesign::shiftFullLabel($shift)
                    ),
                    ['after' => $this->auditSnapshot($session)]
                );

                return $session;
            });
        } catch (QueryException $exception) {
            $raced = ComputerCheckSession::query()
                ->whereDate('check_date', $date)
                ->where('shift', $shift)
                ->first();

            if ($raced === null) {
                throw $exception;
            }

            return $raced;
        }
    }

    /**
     * บันทึกผลของหนึ่งเครื่อง
     *
     * กติกา "เลือกมีปัญหาแล้วต้องมีรายละเอียด" อยู่ที่นี่ ไม่ใช่ที่ controller
     * เพราะเป็นกฎทางธุรกิจที่ต้องเป็นจริงไม่ว่าจะเข้ามาทางไหน
     *
     * @param  array<string, string>  $statuses  คีย์เป็นชื่ออุปกรณ์ ค่าเป็น pending|normal|issue
     */
    public function saveResult(
        ComputerCheckSession $session,
        ComputerCheckWorkstation $workstation,
        User $actor,
        array $statuses,
        ?string $note = null
    ): ComputerCheckResult {
        $this->assertOpen($session);

        $columns = [];
        $hasIssue = false;

        foreach (ComputerCheckDesign::deviceKeys() as $device) {
            $value = $statuses[$device] ?? 'pending';
            $columns[$device.'_status'] = $value;
            $hasIssue = $hasIssue || $value === 'issue';
        }

        $note = trim((string) $note);

        if ($hasIssue && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'กรุณาระบุรายละเอียดปัญหาที่พบ',
            ]);
        }

        $rolledUp = ComputerCheckDesign::rollUp($statuses);

        return DB::transaction(function () use ($session, $workstation, $actor, $columns, $note, $hasIssue, $rolledUp): ComputerCheckResult {
            $result = $this->resultFor($session, $workstation);

            $result->update([
                ...$columns,
                // ไม่มีปัญหาแล้วก็ไม่ต้องเก็บรายละเอียดปัญหาเดิมค้างไว้ให้สับสน
                'note' => $hasIssue ? $note : ($note === '' ? null : $note),
                // เครื่องที่ยังไม่แตะเลยต้องไม่มีชื่อคนตรวจติดอยู่ ไม่งั้นตัวเลข
                // "ตรวจแล้วกี่เครื่อง" กับหน้าจอจะเล่าคนละเรื่องกัน
                'checked_by' => $rolledUp === 'pending' ? null : $actor->id,
                'checked_at' => $rolledUp === 'pending' ? null : now(),
            ]);

            return $result;
        });
    }

    /**
     * ตั้งหลายเครื่องให้เป็น "ปกติทั้งหมด" พร้อมกัน
     *
     * โหมดเลือกหลายเครื่องรองรับเฉพาะผลปกติเท่านั้นโดยตั้งใจ — รายละเอียดปัญหา
     * ของแต่ละเครื่องไม่เหมือนกัน การกดทีเดียวให้หลายเครื่อง "มีปัญหา" จะได้
     * ข้อความเดียวกันทุกเครื่องซึ่งไม่มีประโยชน์กับคนที่มาตามแก้
     *
     * @param  array<int, int>  $workstationIds
     * @return int จำนวนเครื่องที่ถูกปรับจริง
     */
    public function markNormal(ComputerCheckSession $session, User $actor, array $workstationIds): int
    {
        $this->assertOpen($session);

        $ids = collect($workstationIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'workstations' => 'กรุณาเลือกเครื่องอย่างน้อยหนึ่งเครื่อง',
            ]);
        }

        $workstations = ComputerCheckWorkstation::query()->whereKey($ids)->get();

        if ($workstations->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'workstations' => 'มีเครื่องที่เลือกไม่อยู่ในผังห้องแล้ว',
            ]);
        }

        $normal = array_fill_keys(ComputerCheckDesign::statusColumns(), 'normal');

        return DB::transaction(function () use ($session, $actor, $workstations, $normal): int {
            $now = now();

            foreach ($workstations as $workstation) {
                $this->resultFor($session, $workstation)->update([
                    ...$normal,
                    // ปกติทั้งหมดแล้วก็ไม่มีปัญหาค้าง รายละเอียดเดิมต้องถูกล้าง
                    'note' => null,
                    'checked_by' => $actor->id,
                    'checked_at' => $now,
                ]);
            }

            return $workstations->count();
        });
    }

    /**
     * ปิดรอบ แล้วสร้างบันทึกงานประจำวันหนึ่งรายการให้ผู้ตรวจคนที่ 1
     *
     * ต้องตรวจครบทุกเครื่องก่อนจึงปิดได้ เพราะแบบฟอร์มกระดาษที่ระบบนี้มาแทน
     * คือหลักฐานว่าเดินตรวจครบทั้งห้องแล้ว ใบที่ปิดทั้งที่ยังมีช่องว่างจะอ่าน
     * ไม่ออกว่า "เครื่องนั้นปกติ" หรือ "ยังไม่ได้ไปดู"
     */
    public function complete(ComputerCheckSession $session, User $actor): ComputerCheckSession
    {
        $this->assertOpen($session);

        $results = $session->results()->with('workstation')->get();
        $pending = $results->reject(static fn (ComputerCheckResult $result): bool => $result->isFullyChecked());

        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages([
                'results' => sprintf(
                    'ยังตรวจไม่ครบอีก %d เครื่อง (%s) — ต้องตรวจครบทุกเครื่องก่อนบันทึกผล',
                    $pending->count(),
                    $pending->take(5)->map(static fn (ComputerCheckResult $result): string => (string) $result->workstation?->code)
                        ->filter()
                        ->join(', ').($pending->count() > 5 ? ' …' : '')
                ),
            ]);
        }

        return DB::transaction(function () use ($session, $actor, $results): ComputerCheckSession {
            $completedAt = now();

            $session->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
            ]);

            $log = $this->createDailyLog($session->refresh(), $actor, $results);

            $session->update(['work_log_id' => $log->id]);

            AuditTrail::log(
                'computer_check_completed',
                $session,
                sprintf(
                    'บันทึกผลตรวจเช็กคอมพิวเตอร์ %s %s (%d เครื่อง)',
                    ComputerCheckDesign::ROOM_NAME,
                    ComputerCheckDesign::shiftFullLabel($session->shift),
                    $results->count()
                ),
                ['after' => $this->auditSnapshot($session->refresh())]
            );

            return $session->refresh();
        });
    }

    /**
     * ตัวเลขสรุปของรอบหนึ่ง — แหล่งความจริงเดียวของ "ตรวจแล้ว X / ทั้งหมด"
     *
     * ทั้งแถบ progress บนผัง หน้าสรุปผล ประวัติการตรวจ และแบบตรวจ A4 เรียก
     * เมธอดนี้ ห้ามนับใหม่เองที่อื่น
     *
     * @param  Collection<int, ComputerCheckResult>  $results
     * @return array<string, int>
     */
    public static function summarize(Collection $results): array
    {
        $byStatus = $results
            ->map(static fn (ComputerCheckResult $result): string => $result->rolledUpStatus())
            ->countBy();

        $normal = (int) $byStatus->get('normal', 0);
        $issue = (int) $byStatus->get('issue', 0);

        return [
            'total' => $results->count(),
            'normal' => $normal,
            'issue' => $issue,
            'partial' => (int) $byStatus->get('partial', 0),
            'pending' => (int) $byStatus->get('pending', 0),
            // "ตรวจแล้ว" นับเฉพาะเครื่องที่ครบทั้งห้าอุปกรณ์ เครื่องที่ติ๊กค้างไว้
            // ยังไม่ถือว่าตรวจเสร็จ (ดู ComputerCheckDesign::rollUp)
            'checked' => $normal + $issue,
        ];
    }

    /**
     * สร้างแถวผล 'pending' ให้ทุกเครื่องที่ใช้งานอยู่ ณ เวลาที่เปิดรอบ
     *
     * ทำตั้งแต่เปิดรอบเพื่อให้แบบตรวจย้อนหลังประกอบจากแถวผลของรอบนั้นได้โดยตรง
     * ถ้าภายหลังมีการเพิ่มหรือปิดเครื่อง ใบเก่าจะยังมีจำนวนเครื่องเท่าเดิม
     */
    private function seedResults(ComputerCheckSession $session): void
    {
        $now = now();

        $rows = ComputerCheckWorkstation::query()
            ->active()
            ->get()
            ->map(static fn (ComputerCheckWorkstation $workstation): array => [
                'computer_check_session_id' => $session->id,
                'computer_check_workstation_id' => $workstation->id,
                'keyboard_mouse_status' => 'pending',
                'speaker_status' => 'pending',
                'microphone_status' => 'pending',
                'internet_status' => 'pending',
                'computer_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            ComputerCheckResult::query()->insert($rows);
        }
    }

    /**
     * แถวผลของเครื่องนี้ในรอบนี้
     *
     * ปกติถูกสร้างไว้แล้วตั้งแต่เปิดรอบ firstOrCreate มีไว้รองรับเครื่องที่ถูก
     * เพิ่มเข้าผังระหว่างที่รอบยังเปิดอยู่ ไม่ใช่เส้นทางหลัก
     */
    private function resultFor(ComputerCheckSession $session, ComputerCheckWorkstation $workstation): ComputerCheckResult
    {
        return ComputerCheckResult::query()->firstOrCreate(
            [
                'computer_check_session_id' => $session->id,
                'computer_check_workstation_id' => $workstation->id,
            ],
            array_fill_keys(ComputerCheckDesign::statusColumns(), 'pending')
        );
    }

    /**
     * บันทึกงานประจำวันหนึ่งรายการต่อหนึ่งรอบ
     *
     * เขียนผ่าน WorkLogService::create() เสมอ ไม่สร้าง WorkLog เองตรง ๆ เพื่อให้
     * เวลา ค่า duration และ audit trail ของบันทึกงานยังมีทางเข้าทางเดียว
     *
     * @param  Collection<int, ComputerCheckResult>  $results
     */
    private function createDailyLog(ComputerCheckSession $session, User $actor, Collection $results): WorkLog
    {
        $summary = self::summarize($results);
        $category = WorkLogCategory::query()
            ->where('name', ComputerCheckDesign::LOG_CATEGORY)
            ->value('id');

        $clock = static fn (?CarbonInterface $value): ?string => $value === null
            ? null
            : TodayWorkspace::businessNow($value)->format('H:i');

        $log = $this->logs->create($actor, $actor, [
            'work_log_category_id' => $category,
            'kind' => 'routine',
            'source' => 'manual',
            'title' => ComputerCheckDesign::LOG_TITLE,
            'details' => $this->logDetails($session, $summary),
            'work_date' => $session->check_date?->format('Y-m-d'),
            'start_time' => $clock($session->started_at),
            'end_time' => $clock($session->completed_at),
            // พบปัญหาแม้เครื่องเดียวก็ต้องขึ้นป้าย "พบปัญหา" บนปฏิทินแผนก
            // ไม่ใช่ "เสร็จแล้ว" เฉย ๆ ซึ่งจะทำให้หัวหน้ามองข้ามไป
            'outcome' => $summary['issue'] > 0 ? 'issue' : 'done',
            'issue_details' => $summary['issue'] > 0
                ? $this->issueSummary($results)
                : null,
        ]);

        // ผู้ตรวจคนที่ 2 เป็นผู้ร่วมงานของบันทึกนี้ ไม่ใช่เจ้าของอีกใบหนึ่ง
        $this->participants->sync($log, $actor, array_filter([$session->checker_2_id]));

        return $log;
    }

    /**
     * รายละเอียดอัตโนมัติของบันทึกงาน — สิ่งที่หัวหน้าอ่านได้โดยไม่ต้องเปิดแบบตรวจ
     *
     * @param  array<string, int>  $summary
     */
    private function logDetails(ComputerCheckSession $session, array $summary): string
    {
        $checkers = collect([$session->checkerOne?->name, $session->checkerTwo?->name])
            ->filter()
            ->join(', ');

        $start = $session->started_at === null ? null : TodayWorkspace::businessNow($session->started_at)->format('H:i');
        $end = $session->completed_at === null ? null : TodayWorkspace::businessNow($session->completed_at)->format('H:i');

        return collect([
            ComputerCheckDesign::shiftFullLabel($session->shift),
            sprintf('ตรวจทั้งหมด %d เครื่อง', $summary['total']),
            sprintf('ปกติ %d เครื่อง', $summary['normal']),
            sprintf('พบปัญหา %d เครื่อง', $summary['issue']),
            $checkers === '' ? null : 'ผู้ตรวจ: '.$checkers,
            $start === null || $end === null ? null : sprintf('เวลา: %s - %s', $start, $end),
        ])->filter()->join("\n");
    }

    /**
     * สรุปปัญหาแบบสั้นสำหรับช่อง "พบปัญหา" ของบันทึกงาน
     *
     * รายการเต็มพร้อมรายละเอียดอยู่ในแบบตรวจ A4 ที่นี่เอาแค่พอให้รู้ว่าเครื่องไหน
     *
     * @param  Collection<int, ComputerCheckResult>  $results
     */
    private function issueSummary(Collection $results): string
    {
        $lines = $results
            ->filter(static fn (ComputerCheckResult $result): bool => $result->rolledUpStatus() === 'issue')
            ->sortBy(static fn (ComputerCheckResult $result): string => (string) $result->workstation?->code)
            ->map(static fn (ComputerCheckResult $result): string => sprintf(
                '%s — %s',
                $result->workstation?->code ?? 'ไม่ทราบเครื่อง',
                implode(', ', $result->failedDeviceLabels())
            ))
            ->values();

        return $lines->join("\n");
    }

    private function assertOpen(ComputerCheckSession $session): void
    {
        if ($session->isCompleted()) {
            throw ValidationException::withMessages([
                'session' => 'รอบนี้บันทึกผลไปแล้ว แก้ไขย้อนหลังไม่ได้',
            ]);
        }
    }

    /**
     * ผู้ตรวจคนที่ 2 ต้องเป็นคนที่เพิ่มเป็นผู้ร่วมงานของบันทึกงานได้จริง
     *
     * ใช้รายชื่อชุดเดียวกับผู้ร่วมงานของบันทึกงานประจำวัน (แผนกเดียวกัน บัญชี
     * เปิดใช้งาน role = user) ถ้าปล่อยให้เลือกคนนอกรายการนี้ได้ ชื่อนั้นจะถูก
     * WorkLogParticipantService::sync() คัดออกเงียบ ๆ ตอนปิดรอบ แล้วแบบตรวจกับ
     * บันทึกงานจะมีรายชื่อผู้ตรวจไม่ตรงกัน
     */
    private function assertEligibleSecondChecker(User $actor, ?int $checkerTwoId): void
    {
        if ($checkerTwoId === null) {
            return;
        }

        $eligible = $this->participants->candidatesForOwner($actor)
            ->contains(static fn (User $person): bool => (int) $person->id === (int) $checkerTwoId);

        if (! $eligible) {
            throw ValidationException::withMessages([
                'checker_2_id' => 'เลือกผู้ตรวจคนที่ 2 ได้เฉพาะเพื่อนร่วมแผนกที่ยังใช้งานบัญชีอยู่',
            ]);
        }
    }

    /**
     * วันทำการที่รับได้ — กติกาเดียวกับบันทึกงานประจำวัน (ล่วงหน้าไม่ได้ ย้อนหลังจำกัด)
     */
    private function businessDay(string $checkDate): CarbonInterface
    {
        $today = TodayWorkspace::businessNow()->startOfDay();

        try {
            $day = TodayWorkspace::businessNow(
                CarbonImmutable::createFromFormat('Y-m-d', $checkDate, TodayWorkspace::BUSINESS_TIMEZONE)
            )->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['check_date' => 'รูปแบบวันที่ไม่ถูกต้อง']);
        }

        if ($day->greaterThan($today)) {
            throw ValidationException::withMessages(['check_date' => 'เปิดรอบตรวจล่วงหน้าไม่ได้']);
        }

        if ($day->lessThan($today->copy()->subDays(ComputerCheckDesign::MAX_BACKFILL_DAYS))) {
            throw ValidationException::withMessages([
                'check_date' => sprintf('ย้อนหลังได้ไม่เกิน %d วัน', ComputerCheckDesign::MAX_BACKFILL_DAYS),
            ]);
        }

        return $day;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(ComputerCheckSession $session): array
    {
        return [
            'check_date' => $session->check_date?->format('Y-m-d'),
            'shift' => $session->shift,
            'status' => $session->status,
            'checker_1_id' => $session->checker_1_id,
            'checker_2_id' => $session->checker_2_id,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'work_log_id' => $session->work_log_id,
        ];
    }
}
