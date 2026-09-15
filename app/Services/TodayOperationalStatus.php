<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "วันนี้ใครทำงานประจำหรือออกนอกสถานที่" — ตัวคำนวณเดียวของทั้งระบบ
 *
 * บอร์ดทีม (/work-board/departments) และภาพรวมทีมของรายงานปฏิบัติงาน อ่านจากคลาสนี้ร่วมกัน
 * ตัวเลขสองหน้าจึงตรงกันโดยโครงสร้าง
 *
 * งานประจำนับทั้งรายการที่ถูกสร้างแล้ว และที่ถึงกำหนดวันนี้แต่ยังไม่ถูกสร้าง (pendingRoutinesFor
 * อ่านอย่างเดียว) เพราะรายการของวันนี้เกิดตอนเจ้าของเปิดระบบ คนที่ยังไม่เปิดระบบจึงต้องขึ้นว่า
 * "ยังไม่เริ่ม" ไม่ใช่ "ไม่มีงานประจำ" — รวมเวรของตารางเวรที่ลงไว้วันนี้ด้วย
 *
 * คลาสนี้ไม่ตัดสินสิทธิ์ ผู้เรียกต้องตรวจ WorkLogPolicy::viewDay ก่อน
 */
final class TodayOperationalStatus
{
    /** สถานะงานประจำของวันนี้รายคน — เรียงจากสิ่งที่หัวหน้าต้องดูก่อน */
    public const STATUSES = [
        // ปิดรอบ 17:00 แล้วมีรายการไม่ได้เริ่ม / เริ่มแล้วไม่กดเสร็จ / ไม่มา
        'incomplete' => ['label' => 'ปิดรอบไม่ครบ', 'tone' => 'red'],
        'overdue' => ['label' => 'เกินเวลา', 'tone' => 'red'],
        'waiting' => ['label' => 'ยังไม่เริ่ม', 'tone' => 'amber'],
        'progress' => ['label' => 'กำลังทำ', 'tone' => 'blue'],
        'complete' => ['label' => 'ทำครบแล้ว', 'tone' => 'green'],
        'none' => ['label' => 'ไม่มีงานประจำวันนี้', 'tone' => 'slate'],
    ];

    public function __construct(private readonly WorkLogRoutineMaterializer $routines) {}

    /**
     * @param  Collection<int, User>  $members
     * @return Collection<int, array<string, mixed>> keyed by user id
     */
    public function forMembers(Collection $members, ?int $departmentId = null): Collection
    {
        $ids = $members->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return collect();
        }

        $logs = self::scopedQuery($ids, $departmentId)
            ->with(['category:id,name', 'template:id,title,default_start_time,default_duration_minutes'])
            ->whereDate('work_date', TodayWorkspace::businessNow()->format('Y-m-d'))
            ->orderBy('planned_start_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');
        $today = TodayWorkspace::businessNow()->startOfDay();

        return $members->mapWithKeys(fn (User $member): array => [
            (int) $member->id => $this->summarize(
                $logs->get($member->id, collect()),
                $this->routines->pendingRoutinesFor($member, $today)
                    ->each(fn (WorkLogTemplate $template) => $template->loadMissing('category:id,name'))
            ),
        ]);
    }

    /**
     * @param  Collection<int, WorkLog>  $logs  รายการของวันนี้ทุกประเภทของหนึ่งคน
     * @param  Collection<int, WorkLogTemplate>  $unopened  งานประจำที่ถึงกำหนดวันนี้แต่ยังไม่มีรายการ
     * @return array{routine: array<string, mixed>, field: array{count: int, locations: list<string>}, logs: Collection<int, WorkLog>, unopened: Collection<int, WorkLogTemplate>}
     */
    public function summarize(Collection $logs, Collection $unopened): array
    {
        $field = $logs->where('kind', 'field');

        return [
            'routine' => $this->routineStatus(
                $logs->filter(fn (WorkLog $log): bool => $log->work_log_template_id !== null),
                $unopened->count()
            ),
            'field' => [
                'count' => $field->count(),
                'locations' => $field->pluck('location')->filter()->map(fn ($place): string => trim((string) $place))->unique()->values()->all(),
            ],
            'logs' => $logs,
            'unopened' => $unopened,
        ];
    }

    /**
     * รายการของวันนี้สำหรับแผงดูงานสมาชิก — ต่อท้ายด้วยงานประจำที่ยังไม่ถูกเปิด
     *
     * @param  array<string, mixed>  $summary  ผลจาก summarize()
     * @return list<array<string, mixed>>
     */
    public function items(array $summary): array
    {
        $logs = $summary['logs']->map(function (WorkLog $log): array {
            $status = WorkLogPresenter::displayStatus($log);
            $start = $log->started_at ?? $log->planned_start_at;
            $end = $log->ended_at ?? $log->planned_end_at;

            return [
                'title' => (string) $log->title,
                'kind' => $log->kind,
                'kind_label' => WorkLogDesign::kind($log->kind)['label'],
                'kind_icon' => WorkLogDesign::kind($log->kind)['icon'],
                'category' => $log->category?->name ?? 'ไม่ระบุหมวด',
                'status_label' => WorkLogDesign::status($status)['label'],
                'status_tone' => WorkLogDesign::status($status)['tone'],
                'time' => $start === null ? null : $start->copy()->timezone(TodayWorkspace::BUSINESS_TIMEZONE)->format('H:i')
                    .($end === null ? '' : ' - '.$end->copy()->timezone(TodayWorkspace::BUSINESS_TIMEZONE)->format('H:i')),
                'location' => $log->location,
            ];
        });

        $unopened = $summary['unopened']->map(fn (WorkLogTemplate $template): array => [
            'title' => (string) $template->title,
            'kind' => $template->kind,
            'kind_label' => WorkLogDesign::kind($template->kind)['label'],
            'kind_icon' => WorkLogDesign::kind($template->kind)['icon'],
            'category' => $template->category?->name ?? 'ไม่ระบุหมวด',
            'status_label' => self::STATUSES['waiting']['label'],
            'status_tone' => self::STATUSES['waiting']['tone'],
            'time' => $template->plannedWindowLabel(),
            'location' => null,
        ]);

        return $logs->concat($unopened)->values()->all();
    }

    /**
     * บันทึกของกลุ่มคน — หัวหน้าแผนกเห็นเฉพาะบันทึกที่เป็นของแผนกตัวเอง (กติกาเดิมของรายงาน)
     *
     * @param  array<int, int>  $userIds
     */
    public static function scopedQuery(array $userIds, ?int $departmentId): Builder
    {
        return WorkLog::query()
            ->whereIn('user_id', $userIds)
            ->when($departmentId, fn (Builder $query, int $id) => $query
                ->where(fn (Builder $scoped) => $scoped
                    ->where('department_id', $id)
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('department_id')
                        ->whereHas('user', fn (Builder $owner) => $owner->where('department_id', $id)))));
    }

    /**
     * @param  Collection<int, WorkLog>  $logs  รายการงานประจำของวันนี้ที่มีอยู่แล้ว
     * @return array{key: string, label: string, tone: string, closed: int, total: int}
     */
    private function routineStatus(Collection $logs, int $unopened): array
    {
        $total = $logs->count() + $unopened;
        $closed = $logs->whereIn('status', ['done', 'skipped'])->count();
        $overdue = $logs->filter(fn (WorkLog $log): bool => WorkLogPresenter::displayStatus($log) === 'overdue')->count();
        $cutoff = $logs->filter(fn (WorkLog $log): bool => in_array(WorkLogPresenter::displayStatus($log), WorkLogDesign::EXPLANATION_STATUSES, true))->count();
        $started = $closed + $logs->where('status', 'in_progress')->count();

        $key = match (true) {
            $total === 0 => 'none',
            $closed === $total => 'complete',
            $cutoff > 0 => 'incomplete',
            $overdue > 0 => 'overdue',
            $started > 0 => 'progress',
            default => 'waiting',
        };

        return ['key' => $key, ...self::STATUSES[$key], 'closed' => $closed, 'total' => $total];
    }
}
