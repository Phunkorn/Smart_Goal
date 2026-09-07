<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
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
                'status' => $minutes === null ? 'open' : 'done',
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
     * เริ่มจับเวลางานใหม่
     *
     * @param  array<string, mixed>  $data
     */
    public function startTimer(User $owner, User $actor, array $data): WorkLog
    {
        $now = TodayWorkspace::businessNow();

        return $this->guardSingleTimer(function () use ($owner, $actor, $data, $now): WorkLog {
            return DB::transaction(function () use ($owner, $actor, $data, $now): WorkLog {
                $log = WorkLog::create([
                    'user_id' => $owner->id,
                    'created_by' => $actor->id,
                    'department_id' => $owner->department_id,
                    'work_log_category_id' => $data['work_log_category_id'] ?? null,
                    'work_order_list_id' => $data['work_order_list_id'] ?? null,
                    'job_id' => $data['job_id'] ?? null,
                    'kind' => $data['kind'] ?? WorkLogDesign::DEFAULT_KIND,
                    'status' => 'open',
                    'source' => 'manual',
                    'title' => $data['title'],
                    'details' => $data['details'] ?? null,
                    'location' => $data['location'] ?? null,
                    'requester_name' => $data['requester_name'] ?? null,
                    'work_date' => $now->format('Y-m-d'),
                    'started_at' => $now->copy()->utc(),
                    'open_timer_owner_id' => $owner->id,
                ]);

                AuditTrail::log(
                    'work_log_timer_started',
                    $log,
                    sprintf('เริ่มจับเวลางาน "%s"', $log->title),
                    ['after' => $this->auditSnapshot($log)]
                );

                return $log;
            });
        });
    }

    /**
     * เริ่มจับเวลาบนรายการที่มีอยู่แล้ว เช่น งานประจำที่ระบบสร้างให้ตอนเช้า
     */
    public function resumeTimer(WorkLog $log, User $actor): WorkLog
    {
        if ($log->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => 'งานนี้ปิดไปแล้ว เริ่มจับเวลาใหม่ไม่ได้',
            ]);
        }

        $now = TodayWorkspace::businessNow();

        return $this->guardSingleTimer(function () use ($log, $now): WorkLog {
            return DB::transaction(function () use ($log, $now): WorkLog {
                $log->update([
                    'started_at' => $now->copy()->utc(),
                    'ended_at' => null,
                    'duration_minutes' => null,
                    'auto_closed_at' => null,
                    'open_timer_owner_id' => $log->user_id,
                ]);

                AuditTrail::log(
                    'work_log_timer_started',
                    $log,
                    sprintf('เริ่มจับเวลางาน "%s"', $log->title),
                    ['after' => $this->auditSnapshot($log->refresh())]
                );

                return $log;
            });
        });
    }

    /**
     * หยุดจับเวลาและปิดงาน
     *
     * จำนวนนาทีคำนวณจากเวลาของเซิร์ฟเวอร์เสมอ ไม่รับค่าจากฝั่ง client เพราะ
     * นาฬิกาของเครื่องผู้ใช้ตั้งเองได้ และตัวเลขนี้ถูกใช้ในรายงานภาระงาน
     *
     * งานที่สั้นกว่าหนึ่งนาทีถูกปัดขึ้นเป็น 1 นาที เพื่อไม่ให้ได้รายการที่ปิดแล้ว
     * แต่มีเวลาเป็นศูนย์ ซึ่งอ่านแล้วเหมือนระบบทำงานผิด
     */
    public function stopTimer(WorkLog $log, User $actor, ?CarbonInterface $now = null): WorkLog
    {
        if ($log->open_timer_owner_id === null) {
            throw ValidationException::withMessages([
                'status' => 'งานนี้ไม่ได้กำลังจับเวลาอยู่',
            ]);
        }

        $before = $this->auditSnapshot($log);
        $endedAt = ($now ?? TodayWorkspace::businessNow())->copy();
        $minutes = $this->minutesBetween($log->started_at, $endedAt);

        return DB::transaction(function () use ($log, $endedAt, $minutes, $before): WorkLog {
            $log->update([
                'ended_at' => $endedAt->utc(),
                'duration_minutes' => $minutes,
                'status' => 'done',
                'open_timer_owner_id' => null,
            ]);

            AuditTrail::log(
                'work_log_timer_stopped',
                $log,
                sprintf('หยุดจับเวลางาน "%s" (%s)', $log->title, WorkLogDesign::durationLabel($minutes)),
                ['before' => $before, 'after' => $this->auditSnapshot($log->refresh())]
            );

            return $log;
        });
    }

    /**
     * ปิดตัวจับเวลาที่ถูกลืมเปิดค้างข้ามวัน
     *
     * เรียกได้ทั้งจากหน้าเว็บตอนเปิดหน้า และจาก artisan command ที่ตั้งเวลาไว้
     * เพราะระบบไม่ได้รับประกันว่า cron จะถูกตั้งไว้บนเครื่อง production จริง
     * (ดู deploy/README.md ที่ไม่มีขั้นตอนตั้ง schedule:run)
     *
     * เวลาสิ้นสุดถูกจำกัดไว้ที่สิ้นวันทำการของวันนั้น หรือ MAX_TIMER_MINUTES
     * แล้วแต่ว่าอันไหนมาก่อน เพื่อไม่ให้ได้รายการ 30 ชั่วโมงที่ทำให้รายงานเพี้ยน
     * รายการที่ถูกปิดแบบนี้จะมี auto_closed_at ให้หน้าจอขึ้นป้ายเตือนเจ้าของ
     *
     * @return int จำนวนรายการที่ถูกปิด
     */
    public function closeStaleTimers(User $owner, ?CarbonInterface $now = null): int
    {
        $businessNow = ($now ?? TodayWorkspace::businessNow())->copy();

        $stale = WorkLog::query()
            ->where('open_timer_owner_id', $owner->id)
            ->whereNotNull('started_at')
            ->get()
            ->filter(function (WorkLog $log) use ($businessNow): bool {
                // ค้างข้ามวันทำการ หรือเดินเกินเพดานที่ยอมรับได้
                $startedBusinessDay = TodayWorkspace::businessNow($log->started_at)->startOfDay();

                return ! $startedBusinessDay->isSameDay($businessNow)
                    || $this->minutesBetween($log->started_at, $businessNow) >= WorkLogDesign::MAX_TIMER_MINUTES;
            });

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
     * แปลง unique index ที่ฐานข้อมูลปฏิเสธ ให้เป็นข้อความที่ผู้ใช้เข้าใจ
     *
     * กติกา "หนึ่งคนจับเวลาได้ทีละงานเดียว" ถูกบังคับที่ฐานข้อมูล ไม่ใช่ที่โค้ด
     * เพราะการเช็คก่อนเขียนถูก race ได้เมื่อผู้ใช้กดจากสองแท็บพร้อมกัน
     * ที่นี่จึงไม่ตรวจซ้ำ แต่ดักผลลัพธ์ที่ฐานข้อมูลตัดสินแล้วมาแปลงเป็นข้อความ
     */
    private function guardSingleTimer(callable $operation): WorkLog
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'timer' => 'คุณมีงานที่กำลังจับเวลาอยู่แล้ว กรุณากดเสร็จสิ้นงานนั้นก่อน',
            ]);
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        // 23000/23505 คือกลุ่ม integrity constraint violation ของ MySQL และ SQLite
        // ส่วนการเทียบข้อความไว้รองรับไดรเวอร์ที่ไม่ตั้ง SQLSTATE ให้ครบ
        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains(mb_strtolower($exception->getMessage()), 'unique');
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
            'work_order_list_id' => $log->work_order_list_id,
            'job_id' => $log->job_id,
        ];
    }
}
