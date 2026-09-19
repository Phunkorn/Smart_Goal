<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\JointRoutineWork;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use App\Support\WorkLogWeekdays;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * การอ่านข้อมูลบันทึกงานประจำวัน พร้อมกติกาว่าใครเห็นอะไรในระดับ SQL
 *
 * แยกจาก WorkLogPolicy โดยตั้งใจ: policy ตัดสิน "รายการนี้คนนี้เปิดได้ไหม"
 * ส่วนที่นี่ตัดสิน "query ต้องกรองอะไรออกก่อนถึงมือผู้ใช้" ทั้งสองอิงกติกาเดียวกัน
 * (เจ้าของ / หัวหน้าแผนกของเจ้าของ / admin) แต่ใช้คนละจุดของ request
 */
class WorkLogQueryService
{
    /**
     * สถานะไหนเป็นป้ายของแถวงานร่วมบนปฏิทิน — ค่ามากกว่าชนะ (สิ่งที่ต้องสนใจก่อน)
     *
     * ใช้เฉพาะสถานะที่ JointRoutineWork นับเป็นช่วงเดียวกัน: พบปัญหา/เสร็จแล้ว และ เกินเวลา/รอเริ่ม/ยังไม่เริ่ม
     */
    private const JOINT_STATUS_WEIGHT = ['issue' => 3, 'overdue' => 2, 'open' => 1];

    /**
     * ขอบเขตข้อมูลที่ผู้ใช้คนหนึ่งมองเห็นได้
     *
     * viewer ไม่มีสิทธิ์เห็นบันทึกงานประจำวันของใครเลย จึงคืน query ที่ไม่มีผลลัพธ์
     * แทนการโยน exception เพราะจุดเรียกใช้บางแห่งเป็นการนับยอดประกอบหน้าอื่น
     * ส่วนการปิดกั้นการเข้าหน้าเป็นหน้าที่ของ route middleware และ policy
     */
    public function visibleQuery(User $viewer): Builder
    {
        $query = WorkLog::query();

        if ($viewer->role === 'viewer') {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->role === 'admin') {
            return $query;
        }

        if ($viewer->isDepartmentHead()) {
            // หัวหน้าเห็นทั้งของตัวเองและของลูกทีม โดยอิง department_id ที่บันทึกไว้
            // ตอนสร้าง (snapshot) และ fallback ไปที่แผนกปัจจุบันของเจ้าของเมื่อไม่มีค่า
            // เพื่อให้ตรงกับ WorkLogPolicy::logDepartmentId()
            return $query->where(function (Builder $scoped) use ($viewer): void {
                $scoped->where('user_id', $viewer->id)
                    ->orWhere('department_id', $viewer->department_id)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner
                            ->where('department_id', $viewer->department_id)));
            });
        }

        return $query->where('user_id', $viewer->id);
    }

    /**
     * บันทึกงานของคนหนึ่งในหนึ่งวันทำการ เรียงตามเวลาเริ่มจริงหรือเวลาที่วางแผนไว้
     *
     * รายการที่ไม่ระบุเวลาไปอยู่ท้ายสุด เพราะไทม์ไลน์อ่านจากบนลงล่างตามเวลาจริง
     * การจัดเรียงทำในหน่วยความจำหลัง query เพื่อเลี่ยงไวยากรณ์ NULLS LAST
     * ที่ SQLite (ทดสอบ) กับ MySQL (production) เขียนไม่เหมือนกัน
     *
     * @return Collection<int, WorkLog>
     */
    public function dayFor(User $owner, CarbonInterface $businessDay): Collection
    {
        return WorkLog::query()
            // template.user ใช้บอกว่ารายการงานประจำนี้เป็นของแม่แบบที่คนอื่นตั้งไว้
            ->with(['category', 'project', 'task', 'attachments', 'user', 'participants', 'template.user', 'template.participants', 'sharedFrom.user', 'sharedFrom.participants', 'absentMarkedBy:id,name'])
            ->where('user_id', $owner->id)
            ->whereDate('work_date', $businessDay->format('Y-m-d'))
            ->get()
            ->sortBy([
                fn (WorkLog $log): int => ($log->started_at ?? $log->planned_start_at) === null ? 1 : 0,
                fn (WorkLog $log): int => ($log->started_at ?? $log->planned_start_at)?->getTimestamp() ?? 0,
                fn (WorkLog $log): int => $log->id,
            ])
            ->values();
    }

    /**
     * Read-only calendar of existing logs and due templates. Never creates WorkLogs.
     *
     * @param Collection<int, User> $owners
     * @return array<string, list<array<string, mixed>>>
     */
    public function calendarFor(User $viewer, Collection $owners, CarbonInterface $month): array
    {
        $owners = $owners->filter(fn (User $owner): bool => Gate::forUser($viewer)
            ->allows('viewCalendarDay', [WorkLog::class, $owner]))->values();
        if ($owners->isEmpty()) {
            return [];
        }

        $first = $month->copy()->startOfMonth();
        $last = $month->copy()->endOfMonth();
        $ownerIds = $owners->pluck('id')->all();
        $entries = [];
        $existing = [];

        $logs = WorkLog::query()
            ->with(['user:id,name,profile_image', 'category:id,name', 'project:id,name', 'participants:id,name'])
            ->whereIn('user_id', $ownerIds)
            ->whereDate('work_date', '>=', $first->format('Y-m-d'))
            ->whereDate('work_date', '<=', $last->format('Y-m-d'))
            ->get();
        foreach ($logs as $log) {
            if (! Gate::forUser($viewer)->allows('viewCalendar', $log)) {
                continue;
            }
            $date = $log->work_date?->format('Y-m-d');
            if ($date === null) {
                continue;
            }
            if ($log->work_log_template_id !== null) {
                $existing[$log->work_log_template_id.':'.$log->user_id.':'.$date] = true;
            }
            $entries[$date][] = [
                'title' => $log->title,
                'template_id' => $log->work_log_template_id === null ? null : (int) $log->work_log_template_id,
                'people' => [$this->calendarPerson($log->user, (int) $log->user_id)],
                'kind' => $log->kind,
                'category_id' => $log->work_log_category_id,
                'category' => $log->category?->name,
                'project' => $log->project?->name,
                'time' => $log->planned_start_at
                    ? TodayWorkspace::businessNow($log->planned_start_at)->format('H:i')
                    : ($log->started_at ? TodayWorkspace::businessNow($log->started_at)->format('H:i') : null),
                'status' => $log->status,
                // ป้ายสถานะที่ทุกคนในแผนกเห็น เช่น "ตรวจเช็กคอมวันนี้เริ่มหรือยัง / พบปัญหาไหม"
                ...$this->calendarStatusFields(WorkLogPresenter::calendarStatus($log)),
                'participants' => $log->participants->pluck('name')->all(),
                'log_id' => $log->id,
            ];
        }

        // A removed WorkLog must not reappear as a planned item. The materializer
        // uses the same soft-deleted row as its duplicate guard.
        WorkLog::withTrashed()
            ->whereIn('user_id', $ownerIds)
            ->whereNotNull('work_log_template_id')
            ->whereDate('work_date', '>=', $first->format('Y-m-d'))
            ->whereDate('work_date', '<=', $last->format('Y-m-d'))
            ->get(['work_log_template_id', 'user_id', 'work_date'])
            ->each(function (WorkLog $log) use (&$existing): void {
                $date = $log->work_date?->format('Y-m-d');
                if ($date !== null) {
                    $existing[$log->work_log_template_id.':'.$log->user_id.':'.$date] = true;
                }
            });

        $templates = WorkLogTemplate::query()
            ->with(['user:id,name,profile_image', 'category:id,name', 'project:id,name', 'participants:id,name,profile_image,department_id'])
            ->active()
            ->where(fn (Builder $query) => $query
                ->whereIn('user_id', $ownerIds)
                ->orWhereHas('participants', fn (Builder $people) => $people->whereIn('users.id', $ownerIds)))
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $last->format('Y-m-d')))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $first->format('Y-m-d')))
            ->get();

        $today = TodayWorkspace::businessNow()->format('Y-m-d');

        for ($day = $first->copy(); $day->lessThanOrEqualTo($last); $day->addDay()) {
            $date = $day->format('Y-m-d');
            foreach ($templates as $template) {
                if (($template->starts_on && $template->starts_on->format('Y-m-d') > $date)
                    || ($template->ends_on && $template->ends_on->format('Y-m-d') < $date)
                    || ! WorkLogWeekdays::matches((int) $template->weekday_mask, $day)) {
                    continue;
                }
                foreach ($owners as $owner) {
                    if ((int) $template->user_id !== (int) $owner->id
                        && ! $template->participants->contains('id', $owner->id)) {
                        continue;
                    }
                    if (! Gate::forUser($viewer)->allows('viewCalendar', [$template, $owner])) {
                        continue;
                    }
                    if (isset($existing[$template->id.':'.$owner->id.':'.$date])) {
                        continue;
                    }
                    $entries[$date][] = [
                        'title' => $template->title,
                        'template_id' => (int) $template->id,
                        'people' => [$this->calendarPerson($owner, (int) $owner->id)],
                        'kind' => $template->kind,
                        'category_id' => $template->work_log_category_id,
                        'category' => $template->category?->name,
                        'project' => $template->project?->name,
                        'time' => $template->default_start_time ? substr((string) $template->default_start_time, 0, 5) : null,
                        'status' => 'planned',
                        // แผนที่ยังไม่มีรายการจริง: วันข้างหน้า = วางแผนไว้, วันนี้ = ยังไม่เริ่ม,
                        // วันที่ผ่านไปแล้ว = ไม่มีบันทึก
                        ...$this->calendarStatusFields(match (true) {
                            $date > $today => 'planned',
                            $date === $today => 'waiting',
                            default => 'not_logged',
                        }),
                        'participants' => [],
                        'log_id' => null,
                    ];
                }
            }
        }

        foreach ($entries as $date => $items) {
            $entries[$date] = $this->mergeJointEntries((string) $date, $items);
        }

        foreach ($entries as &$items) {
            usort($items, fn (array $a, array $b): int =>
                strcmp((string) ($a['time'] ?? '99:99'), (string) ($b['time'] ?? '99:99'))
                ?: strcmp($a['title'], $b['title']));
        }
        unset($items);

        return $entries;
    }

    /**
     * งานประจำที่ทำร่วมกันเป็นแถวเดียว พร้อม avatar ของทุกคน — กติกาว่าอะไรรวมกันได้อยู่ที่ JointRoutineWork
     *
     * คนที่ "ไม่มา" หรือต้องตอบเหตุผลเองยังเป็นแถวของตัวเอง เพราะสถานะไม่ใช่สถานะร่วม
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mergeJointEntries(string $date, array $items): array
    {
        $groups = [];

        foreach (array_values($items) as $index => $item) {
            $key = JointRoutineWork::key($item['template_id'], $date, (string) $item['status_key']) ?? 'single:'.$index;

            if (! isset($groups[$key])) {
                $groups[$key] = $item;

                continue;
            }

            $group = $groups[$key];
            $group['people'] = collect([...$group['people'], ...$item['people']])
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->all();
            $group['participants'] = array_values(array_unique([...$group['participants'], ...$item['participants']]));

            // ป้ายของกลุ่มคือสถานะที่ต้องสนใจที่สุดในกลุ่ม: "พบปัญหา" ของคนกดเสร็จต้องไม่หายไป
            // เพราะแถวของอีกคนเป็น "เสร็จแล้ว" และ "เกินเวลา" ต้องไม่ถูกกลบด้วย "ยังไม่เริ่ม" ของคนที่ยังไม่เปิดระบบ
            if ((self::JOINT_STATUS_WEIGHT[$item['status_key']] ?? 0) > (self::JOINT_STATUS_WEIGHT[$group['status_key']] ?? 0)) {
                $group = [...$group, ...array_intersect_key($item, array_flip(['status', 'status_key', 'status_label', 'status_tone']))];
            }

            $groups[$key] = $group;
        }

        return array_values($groups);
    }

    /**
     * @return array{id: int, name: string, initial: string, avatar_url: ?string}
     */
    private function calendarPerson(?User $person, int $id): array
    {
        $name = $person?->name ?: 'ไม่ระบุชื่อ';

        return [
            'id' => $id,
            'name' => $name,
            'initial' => mb_substr($name, 0, 1),
            'avatar_url' => $person?->profile_image ? route('media.profile', $person) : null,
        ];
    }

    /**
     * @return array{status_key: string, status_label: string, status_tone: string}
     */
    private function calendarStatusFields(string $key): array
    {
        $meta = WorkLogDesign::status($key);

        return ['status_key' => $key, 'status_label' => $meta['label'], 'status_tone' => $meta['tone']];
    }

    /** Actual WorkLogs only; the monthly summary never counts unmaterialized plans. */
    public function monthFor(User $viewer, User $owner, CarbonInterface $month): Collection
    {
        if (! Gate::forUser($viewer)->allows('viewDay', [WorkLog::class, $owner])) {
            return collect();
        }

        return $this->visibleQuery($viewer)
            ->with(['category', 'attachments', 'project', 'task'])
            ->where('user_id', $owner->id)
            ->whereDate('work_date', '>=', $month->copy()->startOfMonth()->format('Y-m-d'))
            ->whereDate('work_date', '<=', $month->copy()->endOfMonth()->format('Y-m-d'))
            ->get()
            ->filter(fn (WorkLog $log): bool => Gate::forUser($viewer)->allows('view', $log))
            ->values();
    }

    /**
     * รายชื่อคนที่ผู้ใช้เปิดดูไทม์ไลน์แทนได้ (ตัวเลือกในหน้าบันทึกงาน)
     *
     * คืน collection ว่างสำหรับพนักงานทั่วไป เพราะเห็นได้เฉพาะของตัวเอง
     * viewer ไม่เข้าเงื่อนไขใดเลยจึงได้ว่างเช่นกัน
     *
     * @return Collection<int, User>
     */
    public function visibleMembersFor(User $viewer): Collection
    {
        if ($viewer->role !== 'admin' && ! $viewer->isDepartmentHead()) {
            return collect();
        }

        return User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($viewer->isDepartmentHead(), fn (Builder $query) => $query
                ->where('department_id', $viewer->department_id))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);
    }

    /**
     * สมาชิกที่ปรากฏในปฏิทินร่วมกัน
     *
     * ต่างจาก visibleMembersFor() ซึ่งใช้สำหรับเปิด Timeline ของคนอื่นและยัง
     * จำกัดเฉพาะหัวหน้า/admin เมธอดนี้เปิดให้พนักงานทุกคนเห็นเฉพาะสมาชิกใน
     * แผนกเดียวกัน ส่วน admin คงขอบเขตทุกแผนกตามเดิม
     *
     * @return Collection<int, User>
     */
    public function calendarMembersFor(User $viewer): Collection
    {
        if ($viewer->role === 'viewer' || ($viewer->role !== 'admin' && $viewer->department_id === null)) {
            return collect();
        }

        return User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($viewer->role !== 'admin', fn (Builder $query) => $query
                ->where('department_id', $viewer->department_id))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);
    }

    /**
     * เจ้าของหน้าที่กำลังเปิดดู (พารามิเตอร์ ?user=) — ตัวเองเป็นค่าเริ่มต้น
     *
     * id ที่ไม่มีอยู่จริงเป็น 404 ส่วนการไม่มีสิทธิ์ดูเป็นหน้าที่ของ policy ที่ผู้เรียกต้องตรวจต่อ
     * เพื่อให้ข้อความ error สื่อความหมายต่างกัน
     */
    public function resolveOwner(int $requestedId, User $viewer): User
    {
        if ($requestedId === 0 || $requestedId === $viewer->id) {
            return $viewer;
        }

        return User::query()->findOrFail($requestedId);
    }

    /**
     * วันทำการที่หน้าจอกำลังแสดง จากพารามิเตอร์ ?date= ที่ผู้ใช้ส่งมา
     *
     * ค่าที่ผิดรูปแบบหรืออยู่นอกช่วงที่ยอมรับได้จะถูกปัดกลับเป็น "วันนี้" เงียบ ๆ
     * แทนการโยน error เพราะเป็นพารามิเตอร์ของการนำทาง ไม่ใช่ข้อมูลที่ผู้ใช้กรอก
     */
    public function resolveBusinessDay(?string $requested): CarbonInterface
    {
        $today = TodayWorkspace::businessNow()->startOfDay();

        if ($requested === null || $requested === '') {
            return $today;
        }

        try {
            $candidate = TodayWorkspace::businessNow(
                Carbon::createFromFormat('Y-m-d', $requested, TodayWorkspace::BUSINESS_TIMEZONE)
            )->startOfDay();
        } catch (\Throwable) {
            return $today;
        }

        if ($candidate->greaterThan($today)) {
            return $today;
        }

        return $candidate;
    }
}
