<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use App\Support\TodayWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskStatusTransitionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function capabilities(WorkOrder $task, User $actor): array
    {
        $task->loadMissing('collaborators');
        $status = (int) $task->job_status;
        $selfTask = $this->isSelfTask($task, $actor);

        return [
            'can_edit' => Gate::forUser($actor)->allows('update', $task),
            'can_submit_review' => in_array($status, [2, 6], true) && ! $selfTask
                && Gate::forUser($actor)->allows('submitForReview', $task),
            'can_review' => $status === 3 && Gate::forUser($actor)->allows('review', $task),
            'can_self_close' => $actor->role !== 'viewer' && $status !== 4 && $selfTask,
            'can_reopen' => Gate::forUser($actor)->allows('reopen', $task),
            'is_final' => $status === 4,
            'is_self_task' => $selfTask,
            'approver_id' => $this->approverId($task),
        ];
    }

    public function transition(WorkOrder $task, User $actor, int $targetStatus, array $options = []): WorkOrder
    {
        if ($actor->role === 'viewer') {
            throw new AuthorizationException();
        }

        $task->loadMissing(['collaborators', 'subtasks']);
        TodayWorkspace::normalizeLateForTransition($task);
        $task->refresh()->loadMissing(['collaborators', 'subtasks']);
        $from = (int) $task->job_status;

        if ($from === $targetStatus) {
            return $task;
        }

        $action = $this->resolveAction($task, $actor, $from, $targetStatus, $options);
        $this->authorizeAction($task, $actor, $action);

        if (in_array($action, ['review_approved', 'self_closed'], true) && $task->subtasks->contains(fn ($subtask) => ! $subtask->is_completed)) {
            $this->reject('กรุณาติ๊กงานย่อยให้ครบทุกข้อก่อนปิดโปรเจกต์');
        }

        $reason = trim((string) ($options['reason'] ?? ''));
        if ($action === 'review_returned' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ส่งงานกลับแก้ไข']);
        }

        $before = $task->attributesToArray();

        return DB::transaction(function () use ($task, $actor, $targetStatus, $options, $action, $reason, $before, $from) {
            $updates = ['job_status' => $targetStatus];

            if ($action === 'submitted_for_review') {
                $updates += [
                    'submitted_for_review_by' => $actor->id,
                    'submitted_for_review_at' => now(),
                    'review_return_reason' => null,
                ];
            } elseif (in_array($action, ['review_approved', 'self_closed'], true)) {
                $updates += [
                    'job_progress' => 100,
                    'job_completed_at' => now(),
                    'final_approved_by' => $actor->id,
                    'final_approved_at' => now(),
                    'paused_at' => null,
                    'review_return_reason' => null,
                ];
            } elseif ($action === 'review_returned') {
                $updates += [
                    'submitted_for_review_by' => null,
                    'submitted_for_review_at' => null,
                    'final_approved_by' => null,
                    'final_approved_at' => null,
                    'job_completed_at' => null,
                    'review_return_reason' => $reason,
                ];
            } elseif ($action === 'task_reopened') {
                $updates += [
                    'job_progress' => min((int) $task->job_progress, 99),
                    'job_completed_at' => null,
                    'submitted_for_review_by' => null,
                    'submitted_for_review_at' => null,
                    'final_approved_by' => null,
                    'final_approved_at' => null,
                    'review_return_reason' => null,
                    'paused_at' => null,
                ];
            } else {
                if ($targetStatus === 5) $updates['paused_at'] = now();
                if ($from === 5 && $targetStatus !== 5) $updates['paused_at'] = null;
                if (array_key_exists('job_progress', $options)) $updates['job_progress'] = (int) $options['job_progress'];
            }

            $task->update($updates);
            $task->refresh();
            $this->notify($task, $actor, $action, $reason);
            AuditTrail::log($action, $task, $this->activityDescription($task, $action), [
                'before' => $before,
                'after' => $task->attributesToArray(),
                'reason' => $reason ?: null,
            ]);

            return $task;
        });
    }

    private function resolveAction(WorkOrder $task, User $actor, int $from, int $to, array $options): string
    {
        if ($from === 4) {
            if ($to === 2 && ($options['action'] ?? null) === 'reopen') return 'task_reopened';
            $this->reject('งานนี้ปิดแล้ว ต้องใช้คำสั่งเปิดงานอีกครั้งเท่านั้น');
        }

        if ($to === 4) {
            if ($this->isSelfTask($task, $actor)) return 'self_closed';
            if ($from === 3) return 'review_approved';
            $this->reject('งานนี้ต้องส่งตรวจสอบก่อนปิดงาน');
        }

        if ($to === 3) {
            if (in_array($from, [2, 6], true) && ! $this->isSelfTask($task, $actor)) return 'submitted_for_review';
            $this->reject('สามารถส่งตรวจได้จากสถานะกำลังทำหรือล่าช้าเท่านั้น');
        }

        if ($from === 3 && $to === 2) return 'review_returned';
        if ($from === 6) $this->reject('งานล่าช้าต้องส่งตรวจสอบก่อนเปลี่ยนสถานะ');
        if (($from === 1 && $to === 2) || ($from === 2 && $to === 5) || ($from === 5 && $to === 2)) return 'status_changed';

        $this->reject('ไม่อนุญาตให้เปลี่ยนสถานะงานในลักษณะนี้');
    }

    private function authorizeAction(WorkOrder $task, User $actor, string $action): void
    {
        $ability = match ($action) {
            'submitted_for_review' => 'submitForReview',
            'review_approved', 'review_returned' => 'review',
            'task_reopened' => 'reopen',
            default => 'update',
        };

        if (! Gate::forUser($actor)->allows($ability, $task)) throw new AuthorizationException();
    }

    private function notify(WorkOrder $task, User $actor, string $action, string $reason): void
    {
        $recipientIds = match ($action) {
            'submitted_for_review' => collect([$this->approverId($task)]),
            'review_returned' => collect([$task->user_id]),
            'review_approved', 'self_closed', 'task_reopened' => collect([$task->user_id])
                ->merge($task->collaborators->filter(fn ($user) => $user->pivot?->status === 'accepted')->pluck('id')),
            default => collect(),
        };

        $recipientIds = $recipientIds->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === (int) $actor->id);
        if ($recipientIds->isEmpty()) return;

        [$title, $message] = match ($action) {
            'submitted_for_review' => ['มีงานรอตรวจสอบ', $actor->name.' ส่งงาน “'.$task->job_topic.'” เพื่อตรวจสอบ'],
            'review_returned' => ['งานถูกส่งกลับแก้ไข', 'งาน “'.$task->job_topic.'” ถูกส่งกลับให้แก้ไข: '.$reason],
            'task_reopened' => ['งานถูกเปิดอีกครั้ง', 'งาน “'.$task->job_topic.'” ถูกเปิดอีกครั้ง'],
            default => ['งานได้รับการอนุมัติ', 'งาน “'.$task->job_topic.'” ได้รับการอนุมัติและปิดงานแล้ว'],
        };

        $this->notifications->notify($recipientIds, $action, $title, $message, $task, $actor,
            ['status' => (int) $task->job_status]);
    }

    private function activityDescription(WorkOrder $task, string $action): string
    {
        return match ($action) {
            'submitted_for_review' => 'ส่งงานเพื่อตรวจสอบ: '.$task->job_topic,
            'review_approved', 'self_closed' => 'อนุมัติและปิดงาน: '.$task->job_topic,
            'review_returned' => 'ส่งงานกลับแก้ไข: '.$task->job_topic,
            'task_reopened' => 'เปิดงานอีกครั้ง: '.$task->job_topic,
            default => 'เปลี่ยนสถานะงาน: '.$task->job_topic,
        };
    }

    private function approverId(WorkOrder $task): ?int
    {
        return $task->created_by ?: ($task->leader_user_id ?: $task->user_id);
    }

    private function isSelfTask(WorkOrder $task, User $actor): bool
    {
        return (int) $task->user_id === (int) $actor->id && (int) $this->approverId($task) === (int) $actor->id;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['job_status' => $message]);
    }
}
