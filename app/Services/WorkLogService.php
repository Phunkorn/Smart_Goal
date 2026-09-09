<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การเขียนข้อมูลบันทึกงานประจำวันทั้งหมด
 *
 * ทุกฟิลด์ที่เกี่ยวกับเวลา (started_at, ended_at, duration_minutes) ต้องผ่านที่นี่
 * ที่เดียว ห้าม controller ผูกค่าจาก request ลงคอลัมน์เหล่านี้โดยตรง เพราะ
 * ความสอดคล้องระหว่าง "ช่วงเวลา" กับ "จำนวนนาที" เป็นสิ่งที่หน้าสรุปและรายงาน
 * ทั้งระบบเชื่อถืออยู่ ถ้าปล่อยให้เขียนได้หลายทาง ตัวเลขจะเพี้ยนโดยไม่มีใครรู้
 */
class WorkLogService
{
    /**
     * @param  array<string, mixed>  $data  ค่าที่ผ่าน validation จาก controller แล้ว
     */
    public function create(User $owner, User $actor, array $data): WorkLog
    {
        $businessDay = $this->businessDay($data['work_date'] ?? null);
        [$startedAt, $endedAt, $minutes] = $this->resolveTimes($businessDay, $data);

        return DB::transaction(function () use ($owner, $actor, $data, $businessDay, $startedAt, $endedAt, $minutes): WorkLog {
            $log = WorkLog::create([
                'user_id' => $owner->id,
                'created_by' => $actor->id,
                // เก็บแผนกไว้เป็น snapshot ตอนสร้าง เพื่อให้ประวัติยังอยู่กับหัวหน้า
                // แผนกที่ดูแลอยู่ในตอนนั้น แม้ภายหลังเจ้าของจะย้ายแผนก
                'department_id' => $owner->department_id,
                'work_log_category_id' => $data['work_log_category_id'] ?? null,
                'work_log_template_id' => $data['work_log_template_id'] ?? null,
                'work_order_list_id' => $data['work_order_list_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'kind' => $data['kind'] ?? WorkLogDesign::DEFAULT_KIND,
                'status' => $minutes === null ? 'open' : 'done',
                'source' => $data['source'] ?? 'manual',
                'title' => $data['title'],
                'details' => $data['details'] ?? null,
                'location' => $data['location'] ?? null,
                'requester_name' => $data['requester_name'] ?? null,
                'work_date' => $businessDay->format('Y-m-d'),
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_minutes' => $minutes,
            ]);

            AuditTrail::log(
                'work_log_created',
                $log,
                sprintf('บันทึกงานประจำวัน "%s" (%s)', $log->title, WorkLogDesign::kind($log->kind)['label']),
                ['after' => $this->auditSnapshot($log)]
            );

            return $log;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkLog $log, User $actor, array $data): WorkLog
    {
        $before = $this->auditSnapshot($log);
        $businessDay = $this->businessDay($data['work_date'] ?? $log->work_date?->format('Y-m-d'));
        [$startedAt, $endedAt, $minutes] = $this->resolveTimes($businessDay, $data);

        return DB::transaction(function () use ($log, $data, $businessDay, $startedAt, $endedAt, $minutes, $before): WorkLog {
            $log->update([
                'work_log_category_id' => $data['work_log_category_id'] ?? null,
                'work_order_list_id' => $data['work_order_list_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'kind' => $data['kind'] ?? $log->kind,
                'title' => $data['title'] ?? $log->title,
                'details' => $data['details'] ?? null,
                'location' => $data['location'] ?? null,
                'requester_name' => $data['requester_name'] ?? null,
                'work_date' => $businessDay->format('Y-m-d'),
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_minutes' => $minutes,
                // รายการที่เจ้าของกด "ยืนยันว่าทำแล้ว" ไว้ ต้องไม่กลับไปเป็นค้าง
                // เพียงเพราะมาแก้ชื่อหรือรายละเอียดทีหลังโดยไม่ได้กรอกเวลา
                'status' => $minutes === null
                    ? ($log->status === 'done' ? 'done' : 'open')
                    : 'done',
                // การแก้เวลาด้วยมือถือว่าผู้ใช้ยืนยันตัวเลขเองแล้ว ป้ายเตือน
                // "ระบบปิดให้อัตโนมัติ" จึงต้องหายไป
                'auto_closed_at' => null,
            ]);

            AuditTrail::log(
                'work_log_updated',
                $log,
                sprintf('แก้ไขบันทึกงานประจำวัน "%s"', $log->title),
                ['before' => $before, 'after' => $this->auditSnapshot($log->refresh())]
            );

            return $log;
        });
    }

    public function delete(WorkLog $log, User $actor): void
    {
        DB::transaction(function () use ($log, $actor): void {
            AuditTrail::trash($log, $actor);

            AuditTrail::log(
                'work_log_deleted',
                $log,
                sprintf('ลบบันทึกงานประจำวัน "%s"', $log->title),
                ['before' => $this->auditSnapshot($log)]
            );

            // เคลียร์ตัวจับเวลาก่อนลบ ไม่งั้น unique index จะยังกันไม่ให้ผู้ใช้
            // เริ่มงานใหม่ได้ เพราะ soft delete ไม่ได้เอาแถวออกจากตารางจริง
            $log->forceFill(['open_timer_owner_id' => null])->save();
            $log->delete();
        });
    }

    /**
     * ยืนยันว่าทำงานรายการนี้เสร็จแล้ว โดยไม่ต้องจับเวลา
     *
     * นี่คือเส้นทางหลักของงานประจำ: ระบบวางรายการของวันนี้ไว้ให้ตามที่ตั้งค่า
     * เจ้าของเข้าไปทำจริง แล้วกลับมากดยืนยันหนึ่งครั้ง การบังคับให้กดเริ่ม/หยุด
     * ตัวจับเวลาเพื่อปิดงานที่ใช้เวลา 20 นาทีทุกเช้า เป็นภาระที่ไม่ได้ข้อมูล
     * เพิ่มขึ้นจริง เพราะช่วงเวลาถูกกำหนดไว้ในแม่แบบอยู่แล้ว
     *
     * เมื่อยังไม่มีเวลาบันทึกไว้เลย ระบบเติมช่วงเวลาที่ "ตั้งไว้" ของแม่แบบให้
     * เพื่อให้เวลารวมของวันไม่กลายเป็นศูนย์ทั้งที่ทำงานไปแล้วจริง
     */
    public function startRoutine(WorkLog $log, User $actor, ?string $lateReason = null): WorkLog
    {
        if ($log->work_log_template_id === null) {
            throw ValidationException::withMessages(['routine' => 'ปุ่มเริ่มงานใช้กับงานประจำเท่านั้น']);
        }

        if ($log->status !== 'open') {
            return $log;
        }

        // งานประจำของวันที่ผ่านไปแล้ว เริ่มย้อนหลังไม่ได้เด็ดขาด
        //
        // การกดเริ่มงานคือการบันทึกว่า "ตอนนี้กำลังทำอยู่" ถ้าปล่อยให้กดในวัน
        // ย้อนหลังได้ เวลาที่บันทึกจะเป็นเวลาของวันนี้ทั้งที่ผูกอยู่กับวันเมื่อวาน
        // ซึ่งทำให้เวลารวมของทั้งสองวันผิดพร้อมกัน ทางเดียวที่เหลือของวันที่ผ่านไป
        // แล้วคือระบุเหตุผลว่าทำไมไม่ได้ทำ (skipRoutine)
        if ($this->isPastDay($log)) {
            throw ValidationException::withMessages([
                'routine' => 'งานประจำของวันที่ผ่านมาเริ่มย้อนหลังไม่ได้ กรุณาระบุเหตุผลที่ไม่ได้ทำวันนั้นแทน',
            ]);
        }

        $now = TodayWorkspace::businessNow()->utc();

        if ($log->planned_start_at !== null && $now->lessThan($log->planned_start_at)) {
            throw ValidationException::withMessages(['routine' => 'ยังไม่ถึงเวลาเริ่มงานประจำ']);
        }

        if ($log->planned_start_at !== null && $now->greaterThan($log->planned_start_at) && blank($lateReason)) {
            throw ValidationException::withMessages(['late_start_reason' => 'กรุณาระบุเหตุผลที่เริ่มงานช้า']);
        }

        $before = $this->auditSnapshot($log);
        $log->update([
            'status' => 'in_progress',
            'started_at' => $now,
            'ended_at' => null,
            'duration_minutes' => null,
            'late_start_reason' => filled($lateReason) ? trim((string) $lateReason) : null,
            'skip_reason' => null,
            'skipped_at' => null,
        ]);

        AuditTrail::log('work_log_started', $log, sprintf('เริ่มงานประจำ "%s"', $log->title), [
            'before' => $before,
            'after' => $this->auditSnapshot($log->refresh()),
        ]);

        return $log;
    }

    public function markDone(WorkLog $log, User $actor, ?string $lateReason = null): WorkLog
    {
        if ($log->status === 'done') {
            return $log;
        }

        if ($log->work_log_template_id !== null) {
            if ($this->isPastDay($log) && $log->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'routine' => 'งานประจำของวันที่ผ่านมาปิดย้อนหลังไม่ได้ กรุณาระบุเหตุผลที่ไม่ได้ทำวันนั้นแทน',
                ]);
            }

            if ($log->status !== 'in_progress' || $log->started_at === null) {
                throw ValidationException::withMessages(['routine' => 'กรุณากดเริ่มงานก่อนกดเสร็จงาน']);
            }

            $now = TodayWorkspace::businessNow()->utc();

            if ($log->planned_end_at !== null && $now->greaterThan($log->planned_end_at) && blank($lateReason)) {
                throw ValidationException::withMessages(['late_completion_reason' => 'กรุณาระบุเหตุผลที่งานเสร็จเกินเวลา']);
            }

            $before = $this->auditSnapshot($log);
            $minutes = max(1, min(
                WorkLogDesign::MAX_DURATION_MINUTES,
                (int) $log->started_at->diffInMinutes($now)
            ));

            $log->update([
                'status' => 'done',
                'ended_at' => $now,
                'duration_minutes' => $minutes,
                'late_completion_reason' => filled($lateReason) ? trim((string) $lateReason) : null,
            ]);

            AuditTrail::log('work_log_completed', $log, sprintf('ทำงานประจำ "%s" เสร็จแล้ว', $log->title), [
                'before' => $before,
                'after' => $this->auditSnapshot($log->refresh()),
            ]);

            return $log;
        }

        $before = $this->auditSnapshot($log);
        [$startedAt, $endedAt, $minutes] = $this->plannedCompletion($log);

        return DB::transaction(function () use ($log, $startedAt, $endedAt, $minutes, $before): WorkLog {
            $log->update([
                'status' => 'done',
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_minutes' => $minutes,
            ]);

            AuditTrail::log(
                'work_log_completed',
                $log,
                sprintf('ยืนยันว่าทำงาน "%s" เสร็จแล้ว', $log->title),
                ['before' => $before, 'after' => $this->auditSnapshot($log->refresh())]
            );

            return $log;
        });
    }

    public function skipRoutine(WorkLog $log, User $actor, string $reason): WorkLog
    {
        if ($log->work_log_template_id === null) {
            throw ValidationException::withMessages(['routine' => 'ระบุว่าไม่ได้ทำวันนี้ได้เฉพาะงานประจำ']);
        }

        if (in_array($log->status, ['done', 'skipped'], true)) {
            return $log;
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['skip_reason' => 'กรุณาระบุเหตุผลที่ไม่ได้ทำงานวันนี้']);
        }

        $before = $this->auditSnapshot($log);
        $now = TodayWorkspace::businessNow()->utc();
        $minutes = $log->started_at === null ? null : max(1, (int) $log->started_at->diffInMinutes($now));

        $log->update([
            'status' => 'skipped',
            'ended_at' => $log->started_at === null ? null : $now,
            'duration_minutes' => $minutes,
            'skip_reason' => $reason,
            'skipped_at' => $now,
        ]);

        AuditTrail::log('work_log_skipped', $log, sprintf('ระบุว่าไม่ได้ทำงานประจำ "%s" วันนี้', $log->title), [
            'before' => $before,
            'after' => $this->auditSnapshot($log->refresh()),
        ]);

        return $log;
    }

    /**
     * ยกเลิกการยืนยัน — กดผิดรายการแล้วต้องแก้กลับได้
     *
     * เวลาที่ระบบเติมให้ตอนยืนยันถูกถอนออกด้วย ไม่งั้นรายการที่ "ยังไม่เสร็จ"
     * จะยังกินเวลาอยู่ในสรุปของวัน ซึ่งเป็นตัวเลขที่อ่านแล้วเข้าใจผิด
     */
    public function reopen(WorkLog $log, User $actor): WorkLog
    {
        if (! in_array($log->status, ['done', 'skipped'], true)) {
            return $log;
        }

        $before = $this->auditSnapshot($log);

        return DB::transaction(function () use ($log, $before): WorkLog {
            $log->update([
                'status' => 'open',
                'started_at' => null,
                'ended_at' => null,
                'duration_minutes' => null,
                'auto_closed_at' => null,
                'late_start_reason' => null,
                'late_completion_reason' => null,
                'skip_reason' => null,
                'skipped_at' => null,
            ]);

            AuditTrail::log(
                'work_log_reopened',
                $log,
                sprintf('ยกเลิกการยืนยันงาน "%s"', $log->title),
                ['before' => $before, 'after' => $this->auditSnapshot($log->refresh())]
            );

            return $log;
        });
    }

    /**
     * วันของรายการผ่านไปแล้วหรือยัง เทียบด้วยวันตามเวลาทำการ (Asia/Bangkok)
     *
     * ห้ามเทียบกับ now() ตรง ๆ เพราะ 23:00 ที่กรุงเทพยังเป็นวันเดิมของธุรกิจ
     * แต่เป็นวันถัดไปแล้วตามเวลา UTC ที่เก็บอยู่ในฐานข้อมูล
     */
    private function isPastDay(WorkLog $log): bool
    {
        return $log->work_date !== null
            && $log->work_date->format('Y-m-d') < TodayWorkspace::businessNow()->format('Y-m-d');
    }

    /**
     * ช่วงเวลาที่จะบันทึกเมื่อยืนยันงานที่ไม่ได้จับเวลา
     *
     * ใช้ค่าที่มีอยู่ก่อนเสมอถ้าเจ้าของเคยกรอกไว้เอง ระบบไม่ทับข้อมูลที่คนกรอก
     *
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface, 2: ?int}
     */
    private function plannedCompletion(WorkLog $log): array
    {
        if ($log->duration_minutes !== null) {
            return [$log->started_at, $log->ended_at, $log->duration_minutes];
        }

        $minutes = $log->template?->default_duration_minutes;
        $startedAt = $this->plannedStartAt($log) ?? $log->started_at;

        if ($minutes === null || $startedAt === null) {
            return [$startedAt, $log->ended_at, null];
        }

        return [$startedAt, $startedAt->copy()->addMinutes($minutes), (int) $minutes];
    }

    /**
     * เวลาเริ่มที่ตั้งไว้ในแม่แบบของรายการนี้ (ถ้ามี) ในหน่วย UTC
     */
    private function plannedStartAt(WorkLog $log): ?CarbonInterface
    {
        if ($log->started_at !== null) {
            return $log->started_at;
        }

        $template = $log->template;

        if ($template?->default_start_time === null) {
            return null;
        }

        return $this->businessDay($log->work_date?->format('Y-m-d'))
            ->copy()
            ->setTimeFromTimeString($template->normalizedStartTime())
            ->utc();
    }

    /**
     * ปิดตัวจับเวลาที่ยังค้างอยู่จากรุ่นก่อนหน้า
     *
     * ระบบจับเวลาถูกถอดออกจากฟีเจอร์นี้แล้ว — งานถูกปิดด้วยการกดยืนยันครั้งเดียว
     * แทน แต่ฐานข้อมูลของเครื่องที่ใช้งานมาก่อนยังมีแถวที่ open_timer_owner_id
     * ค้างอยู่ได้ ถ้าไม่ปิดให้ รายการนั้นจะค้างตลอดไปโดยไม่มีปุ่มไหนปิดมันได้อีก
     *
     * เวลาสิ้นสุดถูกจำกัดไว้ที่สิ้นวันทำการของวันที่เริ่ม หรือ MAX_TIMER_MINUTES
     * แล้วแต่ว่าอันไหนมาก่อน เพื่อไม่ให้ได้รายการ 30 ชั่วโมงที่ทำให้รายงานเพี้ยน
     * รายการที่ถูกปิดแบบนี้จะมี auto_closed_at ให้หน้าจอขึ้นป้ายเตือนเจ้าของ
     *
     * @return int จำนวนรายการที่ถูกปิด
     */
    public function closeLeftoverTimers(User $owner, ?CarbonInterface $now = null): int
    {
        $stale = WorkLog::query()
            ->where('open_timer_owner_id', $owner->id)
            ->whereNotNull('started_at')
            ->get();

        foreach ($stale as $log) {
            $endOfStartDay = TodayWorkspace::businessNow($log->started_at)->endOfDay();
            $cap = TodayWorkspace::businessNow($log->started_at)->addMinutes(WorkLogDesign::MAX_TIMER_MINUTES);
            $endedAt = $endOfStartDay->lessThan($cap) ? $endOfStartDay : $cap;

            $minutes = $this->minutesBetween($log->started_at, $endedAt);

            DB::transaction(function () use ($log, $endedAt, $minutes): void {
                $log->update([
                    'ended_at' => $endedAt->copy()->utc(),
                    'duration_minutes' => $minutes,
                    'status' => 'done',
                    'open_timer_owner_id' => null,
                    'auto_closed_at' => now(),
                ]);

                AuditTrail::log(
                    'work_log_timer_auto_closed',
                    $log,
                    sprintf('ระบบปิดตัวจับเวลาที่ค้างของงาน "%s"', $log->title),
                    ['after' => $this->auditSnapshot($log->refresh())]
                );
            });
        }

        return $stale->count();
    }

    /**
     * จำนวนนาทีระหว่างสองเวลา อย่างน้อยหนึ่งนาทีเสมอ
     */
    private function minutesBetween(?CarbonInterface $from, CarbonInterface $to): int
    {
        if ($from === null) {
            return WorkLogDesign::MIN_DURATION_MINUTES;
        }

        $minutes = (int) round($from->diffInSeconds($to, true) / 60);

        return max(WorkLogDesign::MIN_DURATION_MINUTES, min($minutes, WorkLogDesign::MAX_DURATION_MINUTES));
    }

    /**
     * แปลงวันที่ทำงานจากข้อความเป็นวันทำการ พร้อมตรวจขอบเขตที่ยอมรับได้
     */
    private function businessDay(mixed $workDate): CarbonInterface
    {
        $today = TodayWorkspace::businessNow()->startOfDay();

        if ($workDate === null || $workDate === '') {
            return $today;
        }

        try {
            $day = TodayWorkspace::businessNow(
                CarbonImmutable::createFromFormat(
                    'Y-m-d',
                    $workDate instanceof CarbonInterface ? $workDate->format('Y-m-d') : (string) $workDate,
                    TodayWorkspace::BUSINESS_TIMEZONE
                )
            )->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'work_date' => 'รูปแบบวันที่ไม่ถูกต้อง',
            ]);
        }

        if ($day->greaterThan($today)) {
            throw ValidationException::withMessages([
                'work_date' => 'บันทึกงานล่วงหน้าไม่ได้',
            ]);
        }

        if ($day->lessThan($today->copy()->subDays(WorkLogDesign::MAX_BACKFILL_DAYS))) {
            throw ValidationException::withMessages([
                'work_date' => sprintf('ย้อนหลังได้ไม่เกิน %d วัน', WorkLogDesign::MAX_BACKFILL_DAYS),
            ]);
        }

        return $day;
    }

    /**
     * คำนวณช่วงเวลาและจำนวนนาทีจากข้อมูลที่ผู้ใช้กรอก
     *
     * รองรับสามรูปแบบที่เกิดขึ้นจริง:
     * 1. กรอกเวลาเริ่มและสิ้นสุด — คำนวณจำนวนนาทีให้
     * 2. กรอกเฉพาะจำนวนนาที (เช่น "ทำ backup 30 นาที" ที่จำเวลานาฬิกาไม่ได้)
     * 3. ไม่กรอกเวลาเลย — เป็นรายการที่ยังไม่ปิด
     *
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface, 2: ?int}
     */
    private function resolveTimes(CarbonInterface $businessDay, array $data): array
    {
        $startTime = $this->normalizeClock($data['start_time'] ?? null);
        $endTime = $this->normalizeClock($data['end_time'] ?? null);
        $rawMinutes = $data['duration_minutes'] ?? null;

        if ($startTime === null && $endTime !== null) {
            throw ValidationException::withMessages([
                'start_time' => 'ต้องระบุเวลาเริ่มก่อนเวลาสิ้นสุด',
            ]);
        }

        if ($startTime !== null && $endTime !== null) {
            if ($rawMinutes !== null && $rawMinutes !== '') {
                throw ValidationException::withMessages([
                    'duration_minutes' => 'เมื่อระบุช่วงเวลาแล้ว ระบบจะคำนวณจำนวนนาทีให้เอง',
                ]);
            }

            $startedAt = $this->atClock($businessDay, $startTime);
            $endedAt = $this->atClock($businessDay, $endTime);

            // งานข้ามคืน เช่น 22:30 ถึง 01:15 ยังนับเป็นงานของวันที่เริ่ม
            if ($endedAt->lessThanOrEqualTo($startedAt)) {
                $endedAt = $endedAt->addDay();
            }

            $minutes = (int) $startedAt->diffInMinutes($endedAt);

            $this->assertDurationInRange($minutes);

            return [$startedAt->utc(), $endedAt->utc(), $minutes];
        }

        if ($rawMinutes !== null && $rawMinutes !== '') {
            $minutes = (int) $rawMinutes;
            $this->assertDurationInRange($minutes);

            // ระบุเวลาเริ่มไว้ด้วยก็เก็บให้ เพื่อให้ไทม์ไลน์ยังเรียงลำดับได้ถูก
            $startedAt = $startTime === null ? null : $this->atClock($businessDay, $startTime);

            return [
                $startedAt?->utc(),
                $startedAt?->copy()->addMinutes($minutes)->utc(),
                $minutes,
            ];
        }

        return [
            $startTime === null ? null : $this->atClock($businessDay, $startTime)->utc(),
            null,
            null,
        ];
    }

    private function assertDurationInRange(int $minutes): void
    {
        if ($minutes < WorkLogDesign::MIN_DURATION_MINUTES || $minutes > WorkLogDesign::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'duration_minutes' => sprintf(
                    'ระยะเวลาต้องอยู่ระหว่าง %d ถึง %d นาที',
                    WorkLogDesign::MIN_DURATION_MINUTES,
                    WorkLogDesign::MAX_DURATION_MINUTES
                ),
            ]);
        }
    }

    /**
     * @return array{0: int, 1: int}|null [ชั่วโมง, นาที]
     */
    private function normalizeClock(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $value, $matches)) {
            throw ValidationException::withMessages([
                'start_time' => 'รูปแบบเวลาไม่ถูกต้อง ต้องเป็น ชช:นน',
            ]);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * ประกอบวันทำการเข้ากับเวลานาฬิกา โดยยังอยู่ในเขตเวลาทำการ
     * ผู้เรียกเป็นผู้แปลงเป็น UTC ตอนบันทึกลงฐานข้อมูล
     *
     * @param  array{0: int, 1: int}  $clock
     */
    private function atClock(CarbonInterface $businessDay, array $clock): CarbonInterface
    {
        return $businessDay->copy()->setTime($clock[0], $clock[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(WorkLog $log): array
    {
        return [
            'title' => $log->title,
            'kind' => $log->kind,
            'status' => $log->status,
            'work_date' => $log->work_date?->format('Y-m-d'),
            'started_at' => $log->started_at?->toIso8601String(),
            'ended_at' => $log->ended_at?->toIso8601String(),
            'duration_minutes' => $log->duration_minutes,
            'planned_start_at' => $log->planned_start_at?->toIso8601String(),
            'planned_end_at' => $log->planned_end_at?->toIso8601String(),
            'late_start_reason' => $log->late_start_reason,
            'late_completion_reason' => $log->late_completion_reason,
            'skip_reason' => $log->skip_reason,
            'work_order_list_id' => $log->work_order_list_id,
            'job_id' => $log->job_id,
        ];
    }
}
