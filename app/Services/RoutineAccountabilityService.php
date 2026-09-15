<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ความรับผิดชอบของงานประจำจากแม่แบบ — ปิดรอบ 17:00, เหตุผลย้อนหลัง และการระบุว่าใครไม่มา
 *
 * กติกา (บังคับที่นี่ ไม่ใช่แค่ใน Swal):
 * 1. ถึง 17:00 ตามเวลาไทย รายการของวันนั้นที่ยังไม่เริ่มเป็น not_started และที่เริ่มแล้วแต่ไม่กดเสร็จ
 *    เป็น unfinished วันที่ถึงกำหนดแต่ไม่มีรายการเลย (ไม่ได้เปิดระบบ) ถูกสร้างเป็น not_started
 * 2. ก่อนเริ่มรายการของวันใหม่ ทุกวันที่ค้างของงานเดียวกันที่ยังไม่มีเหตุผลต้องได้เหตุผลครบในครั้งเดียว
 * 3. คนที่กดเริ่มต้องตอบว่าผู้ร่วมงานที่ยังไม่เริ่มวันนี้มาหรือไม่มา คนที่ "ไม่มา" ได้สถานะ absent
 *    และต้องระบุเหตุผลเองเมื่อกลับมาเริ่มงานนี้
 *
 * เรียกปิดรอบแบบ lazy จากทุกทางเข้า (เปิดหน้า, topbar, เริ่ม/เสร็จ/ไม่ได้ทำ) และจากคำสั่งตามเวลา
 * ความถูกต้องไม่ขึ้นกับว่าทางไหนมาก่อน เพราะ unique (template, user, วัน) กันรายการซ้ำ
 */
final class RoutineAccountabilityService
{
    public function __construct(
        private readonly WorkLogRoutineMaterializer $routines,
        private readonly WorkLogService $logs,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * ปิดรอบรายการที่ถึงเวลาแล้วของคนหนึ่ง
     *
     * @return int จำนวนรายการที่ถูกปิดหรือถูกสร้างเป็นรายการปิดรอบ
     */
    public function closeFor(User $user): int
    {
        if ($user->role === 'viewer' || ! $user->is_active) {
            return 0;
        }

        $now = TodayWorkspace::businessNow();
        $today = $now->copy()->startOfDay();
        $lastDay = $now->greaterThanOrEqualTo(WorkLogDesign::cutoffAt($today)) ? $today : $today->copy()->subDay();
        $closed = 0;

        foreach ($this->templatesFor($user) as $template) {
            $firstDay = $this->firstAccountableDay($template, $user);
            $last = $lastDay->copy();

            if ($template->ends_on !== null) {
                $endsOn = $this->businessDate($template->ends_on->format('Y-m-d'));
                if ($endsOn->lessThan($last)) {
                    $last = $endsOn;
                }
            }

            if ($firstDay->greaterThan($last)) {
                continue;
            }

            $rows = WorkLog::withTrashed()
                ->where('user_id', $user->id)
                ->where('work_log_template_id', $template->id)
                ->whereDate('work_date', '>=', $firstDay->format('Y-m-d'))
                ->whereDate('work_date', '<=', $last->format('Y-m-d'))
                ->get()
                ->keyBy(fn (WorkLog $log): string => $log->work_date->format('Y-m-d'));

            for ($day = $firstDay->copy(); $day->lessThanOrEqualTo($last); $day = $day->copy()->addDay()) {
                if (! WorkLogWeekdays::matches((int) $template->weekday_mask, $day)) {
                    continue;
                }

                $row = $rows->get($day->format('Y-m-d'));

                // เจ้าของลบรายการของวันนั้นทิ้งเอง = บอกแล้วว่าไม่ได้ทำ ไม่สร้างกลับมา
                if ($row?->trashed()) {
                    continue;
                }

                if ($row === null) {
                    $closed += $this->createClosed($user, $template, $day);

                    continue;
                }

                if (in_array($row->status, ['open', 'in_progress'], true)) {
                    $row->update([
                        'status' => $row->status === 'in_progress' ? 'unfinished' : 'not_started',
                        'cutoff_closed_at' => now(),
                    ]);
                    $closed++;
                }
            }
        }

        if ($closed > 0) {
            AuditTrail::log(
                'work_log_routine_cutoff',
                null,
                sprintf('ปิดรอบงานประจำ %d รายการของผู้ใช้ #%d (ตัดรอบ %s น.)', $closed, $user->id, WorkLogDesign::ROUTINE_CUTOFF_TIME),
                ['user_id' => $user->id, 'closed' => $closed]
            );

            $this->notifications->notifyDetached(
                [$user->id],
                'work_log_routine_cutoff',
                'ปิดรอบงานประจำแล้ว',
                sprintf(
                    'มีงานประจำ %d รายการที่ปิดรอบ %s น. โดยยังไม่ได้เริ่มหรือยังไม่ได้กดเสร็จ ต้องระบุเหตุผลก่อนเริ่มงานเดียวกันครั้งถัดไป',
                    $closed,
                    WorkLogDesign::ROUTINE_CUTOFF_TIME
                ),
                null,
                ['work_date' => $today->format('Y-m-d')],
                [],
                'routine-cutoff:'.$today->format('Y-m-d')
            );
        }

        return $closed;
    }

    /**
     * วันที่ค้างของงานเดียวกันก่อนวันของรายการนี้ ที่ยังไม่มีเหตุผล — เรียงจากเก่าไปใหม่
     *
     * @return Collection<int, WorkLog>
     */
    public function backlogFor(User $user, WorkLog $log): Collection
    {
        if ($log->work_log_template_id === null || $log->work_date === null) {
            return collect();
        }

        return WorkLog::query()
            ->with('absentMarkedBy:id,name')
            ->where('user_id', $user->id)
            ->where('work_log_template_id', $log->work_log_template_id)
            ->whereDate('work_date', '<', $log->work_date->format('Y-m-d'))
            ->whereIn('status', WorkLogDesign::EXPLANATION_STATUSES)
            ->orderBy('work_date')
            ->get()
            ->filter(fn (WorkLog $row): bool => WorkLogPresenter::requiresExplanation($row))
            ->values();
    }

    /**
     * ผู้ร่วมงานคนอื่นของแม่แบบที่วันนี้ยังไม่ได้เริ่ม และยังไม่มีใครระบุว่าไม่มา
     *
     * @return Collection<int, User>
     */
    public function attendanceCandidates(WorkLog $log, User $actor): Collection
    {
        $template = $log->template()->with(['user', 'participants'])->first();

        if ($template === null || $log->work_date === null) {
            return collect();
        }

        $members = collect([$template->user])
            ->merge($template->participants)
            ->filter(fn (?User $member): bool => $member !== null
                && (int) $member->id !== (int) $actor->id
                && $member->is_active
                && $member->role !== 'viewer')
            ->unique('id')
            ->values();

        if ($members->isEmpty()) {
            return collect();
        }

        $day = $this->businessDate($log->work_date->format('Y-m-d'));
        $rows = WorkLog::withTrashed()
            ->where('work_log_template_id', $template->id)
            ->whereIn('user_id', $members->pluck('id'))
            ->whereDate('work_date', $day->format('Y-m-d'))
            ->get()
            ->keyBy('user_id');

        return $members->filter(function (User $member) use ($rows, $template, $day): bool {
            $row = $rows->get($member->id);

            if ($row !== null) {
                return ! $row->trashed() && $row->status === 'open';
            }

            // ยังไม่มีรายการ (ยังไม่เปิดระบบ) — ถามได้ถ้าวันนี้เป็นวันที่เขาต้องรับผิดชอบแล้ว
            return ! $this->firstAccountableDay($template, $member)->greaterThan($day);
        })->values();
    }

    /**
     * บันทึกเหตุผลของรายการที่ปิดรอบแล้ว — สถานะเดิมไม่เปลี่ยน ไม่แปลงเป็น "ไม่ได้ทำ"
     */
    public function explain(WorkLog $row, User $actor, string $reason): WorkLog
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['skip_reason' => 'กรุณาระบุเหตุผล']);
        }

        if (! WorkLogPresenter::requiresExplanation($row)) {
            throw ValidationException::withMessages(['skip_reason' => 'รายการนี้ไม่ต้องระบุเหตุผลเพิ่ม']);
        }

        // สถานะที่หน้าจอเห็นอาจยังไม่ถูกเขียนลงฐานข้อมูล (ยังไม่มีทางเข้าไหนปิดรอบให้) จึงเขียนให้ตรงก่อน
        $status = WorkLogPresenter::displayStatus($row);
        $field = $status === 'unfinished' ? 'unfinished_reason' : 'skip_reason';

        $row->update([
            'status' => $status,
            $field => $reason,
            'explained_at' => now(),
            'cutoff_closed_at' => $row->cutoff_closed_at ?? now(),
        ]);

        AuditTrail::log(
            'work_log_explained',
            $row,
            sprintf('ระบุเหตุผลของงานประจำ "%s" วันที่ %s (%s)', $row->title, $row->work_date?->format('Y-m-d'), WorkLogDesign::status($status)['label']),
            ['after' => ['status' => $status, $field => $reason], 'by' => $actor->id]
        );

        return $row;
    }

    /**
     * กดเริ่มงานประจำพร้อมกติกาทั้งหมด — endpoint start เดิมเรียกที่นี่
     *
     * @param  array{late_start_reason?: ?string, backlog_reasons?: array<string, ?string>, attendance?: array<int|string, string>}  $input
     *
     * @throws RoutineStartRequirements เมื่อยังขาดเหตุผลย้อนหลังหรือคำตอบว่าผู้ร่วมงานมาไหม
     */
    public function start(WorkLog $log, User $actor, array $input): WorkLog
    {
        $this->closeFor($actor);
        $log->refresh();

        if ($log->work_log_template_id === null) {
            return $this->logs->startRoutine($log, $actor, $input['late_start_reason'] ?? null);
        }

        $reasons = collect($input['backlog_reasons'] ?? [])->map(fn ($value): string => trim((string) $value));
        $answers = collect($input['attendance'] ?? [])->mapWithKeys(fn ($value, $key): array => [(int) $key => (string) $value]);

        $backlog = $this->backlogFor($actor, $log);
        $candidates = in_array($log->status, ['open', 'absent'], true)
            ? $this->attendanceCandidates($log, $actor)
            : collect();

        $missingBacklog = $backlog->filter(fn (WorkLog $row): bool => ($reasons->get($row->work_date->format('Y-m-d')) ?? '') === '');
        $missingAttendance = $candidates->filter(fn (User $member): bool => ! in_array($answers->get((int) $member->id), ['present', 'absent'], true));

        if ($missingBacklog->isNotEmpty() || $missingAttendance->isNotEmpty()) {
            throw new RoutineStartRequirements(
                $backlog->map(fn (WorkLog $row): array => $this->backlogItem($row))->all(),
                $candidates->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name])->all()
            );
        }

        return DB::transaction(function () use ($log, $actor, $input, $reasons, $answers, $backlog, $candidates): WorkLog {
            foreach ($backlog as $row) {
                $this->explain($row, $actor, (string) $reasons->get($row->work_date->format('Y-m-d')));
            }

            foreach ($candidates as $member) {
                if ($answers->get((int) $member->id) === 'absent') {
                    $this->markAbsent($member, $log, $actor);
                }
            }

            return $this->logs->startRoutine($log, $actor, $input['late_start_reason'] ?? null);
        });
    }

    /**
     * ระบุว่าผู้ร่วมงานไม่มาทำงานนี้วันนี้ — รายการของเขาเป็น absent และเขาได้แจ้งเตือน
     */
    private function markAbsent(User $member, WorkLog $actorLog, User $actor): void
    {
        $date = $actorLog->work_date->format('Y-m-d');
        $this->routines->materializeToday($member);

        $row = WorkLog::query()
            ->where('user_id', $member->id)
            ->where('work_log_template_id', $actorLog->work_log_template_id)
            ->whereDate('work_date', $date)
            ->first();

        if ($row === null || $row->status !== 'open') {
            return;
        }

        $row->update([
            'status' => 'absent',
            'absent_marked_by' => $actor->id,
            'absent_marked_at' => now(),
        ]);

        AuditTrail::log(
            'work_log_marked_absent',
            $row,
            sprintf('%s ระบุว่า %s ไม่ได้มาทำงาน "%s" วันที่ %s', $actor->name, $member->name, $row->title, $date),
            ['after' => ['status' => 'absent', 'absent_marked_by' => $actor->id]]
        );

        $this->notifications->notifyDetached(
            [$member->id],
            'work_log_routine_absent',
            'ถูกระบุว่าไม่ได้มาทำงานประจำ',
            sprintf(
                '%s ระบุว่าคุณไม่ได้มาทำงาน "%s" วันที่ %s — ต้องระบุเหตุผลก่อนเริ่มงานนี้ครั้งถัดไป',
                $actor->name,
                $row->title,
                self::thaiDate($this->businessDate($date))
            ),
            $actor,
            ['work_log_id' => $row->id, 'work_date' => $date]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function backlogItem(WorkLog $row): array
    {
        $status = WorkLogPresenter::displayStatus($row);

        return [
            'log_id' => $row->id,
            'date' => $row->work_date->format('Y-m-d'),
            'date_label' => self::thaiDate($this->businessDate($row->work_date->format('Y-m-d'))),
            'type' => $status,
            'status_label' => WorkLogDesign::status($status)['label'],
            'absent_marked_by' => $status === 'absent' ? $row->absentMarkedBy?->name : null,
        ];
    }

    /** เช่น "7 กันยายน 2569" */
    public static function thaiDate(CarbonInterface $day): string
    {
        return $day->copy()->locale('th')->translatedFormat('j F').' '.($day->year + 543);
    }

    /**
     * @return Collection<int, WorkLogTemplate>
     */
    private function templatesFor(User $user): Collection
    {
        return WorkLogTemplate::query()
            ->active()
            ->with(['participants' => fn ($query) => $query->select('users.id')])
            ->where(fn ($scoped) => $scoped
                ->where('user_id', $user->id)
                ->orWhereHas('participants', fn ($person) => $person->where('users.id', $user->id)))
            ->orderBy('id')
            ->get();
    }

    /**
     * วันแรกที่คนนี้ต้องรับผิดชอบงานนี้ (วันทำการ)
     *
     * นับจากเวลาที่ช้าที่สุดของ: เริ่มนับของแม่แบบ (accountable_from หรือ created_at),
     * เวลาที่ถูกเพิ่มเป็นผู้ร่วมงาน และ starts_on — ถ้าเวลานั้นเลย 17:00 ของวันนั้นแล้ว เริ่มนับวันถัดไป
     * และย้อนหลังไม่เกิน MAX_BACKFILL_DAYS
     */
    private function firstAccountableDay(WorkLogTemplate $template, User $user): CarbonInterface
    {
        $moments = collect([$template->accountable_from ?? $template->created_at]);

        if ((int) $template->user_id !== (int) $user->id) {
            $moments->push($template->participants->firstWhere('id', $user->id)?->pivot?->created_at);
        }

        $since = $moments->filter()
            ->map(fn ($moment): CarbonInterface => TodayWorkspace::businessNow(CarbonImmutable::parse($moment)))
            ->reduce(fn (?CarbonInterface $carry, CarbonInterface $moment): CarbonInterface => $carry === null || $moment->greaterThan($carry) ? $moment : $carry)
            ?? TodayWorkspace::businessNow();

        $day = $since->copy()->startOfDay();

        if ($since->greaterThan(WorkLogDesign::cutoffAt($day))) {
            $day = $day->copy()->addDay();
        }

        if ($template->starts_on !== null) {
            $startsOn = $this->businessDate($template->starts_on->format('Y-m-d'));
            if ($startsOn->greaterThan($day)) {
                $day = $startsOn;
            }
        }

        $floor = TodayWorkspace::businessNow()->startOfDay()->subDays(WorkLogDesign::MAX_BACKFILL_DAYS);

        return $day->lessThan($floor) ? $floor : $day;
    }

    private function createClosed(User $user, WorkLogTemplate $template, CarbonInterface $day): int
    {
        try {
            WorkLog::create([
                'user_id' => $user->id,
                'created_by' => $user->id,
                'department_id' => $user->department_id,
                'work_log_category_id' => $template->work_log_category_id,
                'work_log_template_id' => $template->id,
                'work_order_list_id' => $template->work_order_list_id,
                'job_id' => $template->job_id,
                'kind' => $template->kind ?: WorkLogDesign::DEFAULT_KIND,
                'status' => 'not_started',
                'source' => 'template',
                'title' => $template->title,
                'details' => $template->details,
                'work_date' => $day->format('Y-m-d'),
                'planned_start_at' => $this->routines->plannedStartAt($template, $day),
                'planned_end_at' => $this->routines->plannedEndAt($template, $day),
                'cutoff_closed_at' => now(),
            ]);

            return 1;
        } catch (QueryException $exception) {
            if (WorkLogRoutineMaterializer::isDuplicateKey($exception)) {
                return 0;
            }

            throw $exception;
        }
    }

    private function businessDate(string $date): CarbonInterface
    {
        return TodayWorkspace::businessNow(
            CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' 12:00:00', TodayWorkspace::BUSINESS_TIMEZONE)
        )->startOfDay();
    }
}
