<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkLog;
use App\Support\TodayWorkspace;
use Illuminate\Support\Collection;

class RoutineAttentionService
{
    public function __construct(
        private readonly WorkLogRoutineMaterializer $materializer,
        private readonly NotificationService $notifications,
        private readonly RoutineAccountabilityService $accountability,
    ) {}

    /**
     * สถานะงานประจำของเจ้าของสำหรับไอคอน Topbar
     *
     * @return array{total:int,waiting:int,running:int,overdue:int,items:Collection}
     */
    public function summary(User $user, bool $createNotifications = true): array
    {
        if ($user->role === 'viewer' || ! $user->is_active) {
            return ['total' => 0, 'waiting' => 0, 'running' => 0, 'overdue' => 0, 'items' => collect()];
        }

        // ปิดรอบ 17:00 ก่อน — รายการที่ปิดรอบแล้วไม่ใช่ "รอเริ่ม/กำลังทำ" อีกต่อไป และได้แจ้งเตือนปิดรอบของตัวเอง
        $this->accountability->closeFor($user);
        $this->materializer->materializeToday($user);
        $now = TodayWorkspace::businessNow()->utc();
        $day = TodayWorkspace::businessNow()->format('Y-m-d');

        $items = WorkLog::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $day)
            ->whereNotNull('work_log_template_id')
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw('planned_start_at IS NULL')
            ->orderBy('planned_start_at')
            ->get();

        if ($createNotifications) {
            $this->notifyAttention($user, $items, $now, $day);
        }

        $overdue = $items->filter(fn (WorkLog $log): bool => $log->planned_end_at !== null && $now->greaterThan($log->planned_end_at));
        $running = $items->where('status', 'in_progress');
        $waiting = $items->where('status', 'open');

        return [
            'total' => $items->count(),
            'waiting' => $waiting->count(),
            'running' => $running->count(),
            'overdue' => $overdue->count(),
            'items' => $items->take(5),
        ];
    }

    public function notifyUser(User $user): void
    {
        $this->summary($user, true);
    }

    private function notifyAttention(User $user, Collection $items, $now, string $day): void
    {
        foreach ($items as $log) {
            if ($log->status === 'open' && $log->planned_start_at !== null && $now->greaterThanOrEqualTo($log->planned_start_at->copy()->addMinutes(10))) {
                $this->notifications->notifyDetached(
                    [$user->id],
                    'work_log_routine_start_late',
                    'งานประจำยังไม่ได้เริ่ม',
                    sprintf('งาน “%s” ถึงเวลาเริ่มแล้ว กรุณาเริ่มงานหรือระบุว่าไม่ได้ทำวันนี้', $log->title),
                    null,
                    ['work_log_id' => $log->id, 'work_date' => $day],
                    [],
                    'routine-start-late:'.$log->id.':'.$day
                );
            }

            if ($log->planned_end_at !== null && $now->greaterThan($log->planned_end_at)) {
                $this->notifications->notifyDetached(
                    [$user->id],
                    'work_log_routine_overdue',
                    'งานประจำเกินเวลา',
                    sprintf('งาน “%s” เลยเวลาที่กำหนดแล้ว กรุณากดเสร็จงานหรือระบุเหตุผล', $log->title),
                    null,
                    ['work_log_id' => $log->id, 'work_date' => $day],
                    [],
                    'routine-overdue:'.$log->id.':'.$day
                );
            }
        }
        // แจ้งเตือน "ยังไม่ได้ปิดรายการหลัง 17:00" แบบเดิมถูกแทนด้วยแจ้งเตือนปิดรอบของ
        // RoutineAccountabilityService::closeFor() เพราะถึง 17:00 รายการถูกปิดรอบแล้ว ไม่ได้ค้างอยู่อีก
    }
}
