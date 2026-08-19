<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;

class NotificationMaintenanceService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function generateDeadlines(?CarbonImmutable $now = null): int
    {
        $today = ($now ?? CarbonImmutable::now('Asia/Bangkok'))->setTimezone('Asia/Bangkok')->startOfDay();
        $tomorrowStartUtc = $today->addDay()->utc();
        $created = 0;

        WorkOrder::with(['collaborators', 'user', 'creator', 'leader'])
            ->where('approval_status', 'approved')
            ->where('job_status', '!=', 4)
            ->where('job_due_at', '<', $tomorrowStartUtc)
            ->chunkById(100, function ($tasks) use ($today, &$created) {
                foreach ($tasks as $task) {
                    $due = CarbonImmutable::parse($task->job_due_at, 'Asia/Bangkok')->setTimezone('Asia/Bangkok')->startOfDay();
                    if ($due->gt($today)) continue;
                    $isDueToday = $due->equalTo($today);
                    $type = $isDueToday ? 'deadline_due_today' : 'deadline_overdue';
                    $title = $isDueToday ? 'งานครบกำหนดวันนี้' : 'งานเลยกำหนด';
                    $message = $isDueToday
                        ? 'งาน “'.$task->job_topic.'” ครบกำหนดวันนี้'
                        : 'งาน “'.$task->job_topic.'” เลยกำหนดแล้ว';
                    $recipients = collect([$task->user_id, $task->created_by, $task->leader_user_id])
                        ->merge($task->collaborators->filter(fn ($user) => $user->pivot?->status === 'accepted')->pluck('id'));
                    $before = SystemNotification::count();
                    $this->notifications->notify($recipients, $type, $title, $message, $task, null,
                        ['due_date' => $due->toDateString()],
                        'deadline:'.$type.':'.$task->job_id.':'.$due->toDateString());
                    $created += SystemNotification::count() - $before;
                }
            }, 'job_id');

        return $created;
    }

    public function prune(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? CarbonImmutable::now('Asia/Bangkok'))->setTimezone('Asia/Bangkok')->subDays(90);

        return SystemNotification::whereNotNull('read_at')->where('created_at', '<', $cutoff)->delete();
    }
}
