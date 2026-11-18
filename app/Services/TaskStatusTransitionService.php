<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use App\Support\CrossDepartmentWork;
use App\Support\TaskReviewStage;
use App\Support\TodayWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskStatusTransitionService
{
    private const ACTIVE_STATUSES = [2, 3, 4, 5, 6];

    private const ADMIN_MANUAL_STATUSES = [2, 3, 4, 5];

    /** สถานะที่ส่งเข้าขั้นตรวจสอบได้โดยตรง — กำลังทำ, พักงาน และล่าช้า */
    private const REVIEWABLE_FROM = [2, 5, 6];

    public function __construct(private readonly NotificationService $notifications) {}

    public function capabilities(WorkOrder $task, User $actor): array
    {
        $task->loadMissing('collaborators');
        $status = (int) $task->job_status;
        $selfTask = $this->isSelfTask($task, $actor);

        return [
            'can_edit' => Gate::forUser($actor)->allows('work', $task),
            'can_admin_override' => Gate::forUser($actor)->allows('overrideStatus', $task),
            'can_submit_review' => in_array($status, self::REVIEWABLE_FROM, true) && ! $selfTask
                && Gate::forUser($actor)->allows('submitForReview', $task),
            'can_review' => $status === 3 && Gate::forUser($actor)->allows('review', $task),
            'can_self_close' => $actor->role !== 'viewer' && $status !== 4 && $selfTask,
            'can_reopen' => Gate::forUser($actor)->allows('reopen', $task),
            // หน้าจอใช้ค่านี้เลือกระหว่าง "เด้งบอกว่าปิดไม่ได้" กับ "เด้งยืนยันปิดพร้อมงานย่อย"
            'can_close_with_subtasks' => $status !== 4 && $this->canCloseWithSubtasks($task, $actor),
            'is_final' => $status === 4,
            'is_self_task' => $selfTask,
            // งานของตัวเองไม่มีขั้นตรวจสอบ หน้าจอจึงต้องไม่แสดงสถานะ 3 ให้เลือกเลย
            'shows_review_stage' => TaskReviewStage::appliesTo($task, $actor),
            'approver_id' => $this->approverId($task),
            'allowed_statuses' => $this->allowedStatuses($task, $actor),
            /*
             * ด่านงานย่อยไม่ใช่เรื่องสิทธิ์ จึงไม่ถูกตัดออกจาก allowed_statuses
             * (ถ้าตัด ปุ่มจะถูก disable เงียบ ๆ โดยผู้ใช้ไม่รู้สาเหตุ)
             * UI อ่านสามค่านี้ไปเด้ง SweetAlert บอกเหตุผลก่อนยิง request
             */
            'task_id' => (int) $task->job_id,
            'child_count' => $this->childCount($task),
            'open_child_count' => $this->openChildCount($task),
            // บอกหน้าจอว่าแถวงานย่อยที่มันเห็นเป็นชุดเต็มหรือถูกตัดด้วยสิทธิ์
            // ถ้าถูกตัด การนับจาก DOM จะต่ำกว่าความจริง ด่านจึงต้องเชื่อตัวเลขนี้แทน
            'children_restricted' => CrossDepartmentWork::childrenWereRestricted($task),
        ];
    }

    public function transition(WorkOrder $task, User $actor, int $targetStatus, array $options = []): WorkOrder
    {
        if ($actor->role === 'viewer') {
            throw new AuthorizationException;
        }

        if (! in_array($targetStatus, self::ACTIVE_STATUSES, true)) {
            $this->reject('สถานะงานนี้ไม่รองรับแล้ว');
        }

        $task->loadMissing('collaborators');
        TodayWorkspace::normalizeLateForTransition($task);
        $task->refresh()->loadMissing('collaborators');
        $from = (int) $task->job_status;

        if ($from === $targetStatus) {
            return $task;
        }

        /*
         * ปิดงานหรือส่งตรวจได้ต่อเมื่องานย่อยเสร็จครบ
         *
         * งานแม่ที่ปิดแล้วแต่มีงานย่อยเปิดค้างอยู่ข้างใต้ทำให้บอร์ดและรายงานไม่ตรงความจริง
         * นี่เป็นกฎเนื้องาน ไม่ใช่เรื่องสิทธิ์ จึงบังคับกับ admin ที่ปรับสถานะข้ามขั้นด้วย
         * ต้องอยู่ก่อน resolveAction() เพราะ admin ลัดออกไปทาง overrideStatus ตั้งแต่บรรทัดแรกของที่นั่น
         *
         * 4 -> 2 (เปิดงานอีกครั้ง) และ 3 -> 2 (ส่งกลับแก้ไข) ไม่ถูกกฎนี้แตะ
         */
        $closeSubtasks = false;

        if (in_array($targetStatus, [3, 4], true) && $from !== 4) {
            $openChildren = $this->openChildCount($task);

            /*
             * ข้อยกเว้นเดียวของด่านนี้ — ผู้ดูแลระบบปิดงานพร้อมงานย่อยที่ค้างทั้งหมด
             *
             * ด่านยังมีอยู่เพื่อไม่ให้เกิดงานแม่ที่ปิดแล้วแต่มีงานย่อยเปิดค้าง ทางนี้จึงไม่ข้ามด่าน
             * แต่ "เคลียร์ให้ครบ" แทนในคราวเดียว งานย่อยทุกใบถูกปิดผ่าน transition() ตัวนี้เอง
             * จึงได้กฎสถานะ ด่านงานย่อยชั้นลึกกว่า และ AuditTrail ครบเหมือนการปิดทีละใบ
             *
             * ต้องขอมาอย่างชัดแจ้ง (close_subtasks) — หน้าจอเด้งยืนยันพร้อมจำนวนงานย่อยก่อนเสมอ
             * คำขอที่ไม่ได้ส่งธงมายังถูกปฏิเสธเหมือนเดิม การปิดงานย่อยจึงไม่เกิดขึ้นเงียบ ๆ
             */
            $closeSubtasks = $openChildren > 0
                && $targetStatus === 4
                && ($options['close_subtasks'] ?? false) === true
                && $this->canCloseWithSubtasks($task, $actor);

            if ($openChildren > 0 && ! $closeSubtasks) {
                $this->reject($targetStatus === 4
                    ? 'ยังเคลียร์งานย่อยไม่ครบ เหลืออีก '.$openChildren.' งาน ต้องปิดงานย่อยให้ครบก่อนจึงจะปิดงานนี้ได้'
                    : 'ยังเคลียร์งานย่อยไม่ครบ เหลืออีก '.$openChildren.' งาน ต้องปิดงานย่อยให้ครบก่อนจึงจะส่งตรวจได้');
            }

            if ($closeSubtasks) {
                $this->assertSubtasksCanBeClosed($task, $actor);
            }
        }

        $action = $this->resolveAction($task, $actor, $from, $targetStatus, $options);
        $this->authorizeAction($task, $actor, $action);

        $reason = trim((string) ($options['reason'] ?? ''));
        if ($action === 'review_returned' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลที่ส่งงานกลับแก้ไข']);
        }

        $before = $task->attributesToArray();

        return DB::transaction(function () use ($task, $actor, $targetStatus, $action, $reason, $before, $from, $closeSubtasks) {
            if ($closeSubtasks) {
                $this->closeOpenSubtasks($task, $actor);
            }

            $updates = ['job_status' => $targetStatus];

            if ($action === 'admin_status_overridden') {
                $updates += $this->adminOverrideUpdates($targetStatus, $actor);
            } elseif ($action === 'submitted_for_review') {
                $updates += [
                    'submitted_for_review_by' => $actor->id,
                    'submitted_for_review_at' => now(),
                    'review_return_reason' => null,
                    // ส่งตรวจจากสถานะพักงานได้โดยตรง งานจึงไม่ได้ถูกพักอีกต่อไป
                    'paused_at' => null,
                ];
            } elseif (in_array($action, ['review_approved', 'self_closed'], true)) {
                $updates += [
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
                    'job_completed_at' => null,
                    'submitted_for_review_by' => null,
                    'submitted_for_review_at' => null,
                    'final_approved_by' => null,
                    'final_approved_at' => null,
                    'review_return_reason' => null,
                    'paused_at' => null,
                ];
            } else {
                if ($targetStatus === 5) {
                    $updates['paused_at'] = now();
                }
                if ($from === 5 && $targetStatus !== 5) {
                    $updates['paused_at'] = null;
                }
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
            if ($to === 2 && ($options['action'] ?? null) === 'reopen') {
                return 'task_reopened';
            }
            $this->reject('งานนี้ปิดแล้ว ต้องใช้คำสั่งเปิดงานอีกครั้งเท่านั้น');
        }

        if (Gate::forUser($actor)->allows('overrideStatus', $task)) {
            if ($to === 6) {
                $this->reject('สถานะล่าช้าถูกกำหนดจากกำหนดส่งโดยอัตโนมัติ');
            }
            if ($from === 6 && $to === 2 && TodayWorkspace::isLateBySchedule($task)) {
                $this->reject('งานยังเกินกำหนดส่ง จึงไม่สามารถเปลี่ยนเป็นกำลังทำได้');
            }

            return 'admin_status_overridden';
        }

        if ($to === 4) {
            if ($this->isSelfTask($task, $actor)) {
                return 'self_closed';
            }
            if ($from === 3) {
                return 'review_approved';
            }
            $this->reject('งานนี้ต้องส่งตรวจสอบก่อนปิดงาน');
        }

        if ($to === 3) {
            if (in_array($from, self::REVIEWABLE_FROM, true) && ! $this->isSelfTask($task, $actor)) {
                return 'submitted_for_review';
            }
            $this->reject('สามารถส่งตรวจได้จากสถานะกำลังทำ พักงาน หรือล่าช้าเท่านั้น');
        }

        if ($from === 3 && $to === 2) {
            return 'review_returned';
        }
        if ($from === 6) {
            $this->reject('งานล่าช้าต้องส่งตรวจสอบก่อนเปลี่ยนสถานะ');
        }
        if (($from === 2 && $to === 5) || ($from === 5 && $to === 2)) {
            return 'status_changed';
        }

        $this->reject('ไม่อนุญาตให้เปลี่ยนสถานะงานในลักษณะนี้');
    }

    private function authorizeAction(WorkOrder $task, User $actor, string $action): void
    {
        $ability = match ($action) {
            'admin_status_overridden' => 'overrideStatus',
            'submitted_for_review' => 'submitForReview',
            'review_approved', 'review_returned' => 'review',
            'task_reopened' => 'reopen',
            default => 'work',
        };

        if (! Gate::forUser($actor)->allows($ability, $task)) {
            throw new AuthorizationException;
        }
    }

    private function notify(WorkOrder $task, User $actor, string $action, string $reason): void
    {
        /*
         * ส่งกลับแก้ไขใช้ผู้รับชุดเดียวกับการอนุมัติ — คนที่ต้องลงมือแก้งานต่อคือทุกคนที่ทำงานนี้
         *
         * เดิมส่งให้ผู้รับผิดชอบ (user_id) คนเดียว เมื่อผู้ตรวจเป็นผู้รับผิดชอบเอง (สร้างงานของตัวเอง
         * แล้วเชิญคนอื่นมาร่วม) ผู้รับจะถูกตัดออกเพราะเป็นคนกด ผู้ร่วมงานที่ส่งตรวจมาจึงไม่รู้ว่า
         * งานถูกส่งกลับ ทั้งที่ตอนอนุมัติเขาได้รับแจ้งเตือน
         */
        $workers = fn () => collect([$task->user_id])
            ->merge($task->collaborators->filter(fn ($user) => $user->pivot?->status === 'accepted')->pluck('id'));

        $recipientIds = match ($action) {
            'admin_status_overridden' => collect(),
            'submitted_for_review' => collect([$this->approverId($task)]),
            'review_returned', 'review_approved', 'self_closed', 'task_reopened' => $workers(),
            default => collect(),
        };

        $recipientIds = $recipientIds->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === (int) $actor->id);
        if ($recipientIds->isEmpty()) {
            return;
        }

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
            'admin_status_overridden' => 'Admin ปรับสถานะงาน: '.$task->job_topic,
            'submitted_for_review' => 'ส่งงานเพื่อตรวจสอบ: '.$task->job_topic,
            'review_approved', 'self_closed' => 'อนุมัติและปิดงาน: '.$task->job_topic,
            'review_returned' => 'ส่งงานกลับแก้ไข: '.$task->job_topic,
            'task_reopened' => 'เปิดงานอีกครั้ง: '.$task->job_topic,
            default => 'เปลี่ยนสถานะงาน: '.$task->job_topic,
        };
    }

    private function approverId(WorkOrder $task): ?int
    {
        return TaskReviewStage::approverId($task);
    }

    /** @return array<int> */
    private function allowedStatuses(WorkOrder $task, User $actor): array
    {
        $status = (int) $task->job_status;
        $allowed = in_array($status, self::ACTIVE_STATUSES, true) ? [$status] : [];

        if ($status === 4) {
            if (Gate::forUser($actor)->allows('reopen', $task)) {
                $allowed[] = 2;
            }

            return array_values(array_unique($allowed));
        }

        if (Gate::forUser($actor)->allows('overrideStatus', $task)) {
            return array_values(array_unique(array_merge($allowed, self::ADMIN_MANUAL_STATUSES)));
        }

        if (Gate::forUser($actor)->allows('work', $task)) {
            $allowed = array_merge($allowed, match ($status) {
                2 => [5],
                5 => [2],
                default => [],
            });
        }

        // งานที่ผู้ใช้สร้างเองและรับผิดชอบเองไม่มีขั้นตรวจสอบ resolveAction() ปฏิเสธเสมอ
        // จึงต้องไม่โฆษณาสถานะ 3 ออกไปให้ UI ลากแล้วเด้ง error
        if (in_array($status, self::REVIEWABLE_FROM, true)
            && ! $this->isSelfTask($task, $actor)
            && Gate::forUser($actor)->allows('submitForReview', $task)) {
            $allowed[] = 3;
        }

        if ($status === 3 && Gate::forUser($actor)->allows('review', $task)) {
            $allowed = array_merge($allowed, [2, 4]);
        }

        if ($this->isSelfTask($task, $actor)) {
            $allowed[] = 4;
        }

        return array_values(array_unique($allowed));
    }

    private function adminOverrideUpdates(int $targetStatus, User $actor): array
    {
        $updates = [
            'submitted_for_review_by' => null,
            'submitted_for_review_at' => null,
            'final_approved_by' => null,
            'final_approved_at' => null,
            'job_completed_at' => null,
            'review_return_reason' => null,
            'paused_at' => null,
            'late_at' => null,
        ];

        if ($targetStatus === 4) {
            $updates['job_completed_at'] = now();
            $updates['final_approved_by'] = $actor->id;
            $updates['final_approved_at'] = now();
        } elseif ($targetStatus === 5) {
            $updates['paused_at'] = now();
        }

        return $updates;
    }

    /**
     * งานย่อยที่ยัง "ไม่เสร็จ"
     *
     * เคลียร์แล้วคือสถานะ 4 เท่านั้น — พักงาน (5) รอตรวจสอบ (3) และล่าช้า (6)
     * ยังนับว่าค้าง เพราะงานเหล่านั้นยังต้องมีคนทำต่อ
     */
    private function openChildCount(WorkOrder $task): int
    {
        // หน้า Task Workspace เรียก capabilities() ให้ทุกงานบนหน้า ถ้านับด้วยคิวรีทุกครั้ง
        // จะเพิ่มคิวรีตามจำนวนงาน ทั้งที่ children ถูก eager-load มาแล้วตั้งแต่ controller
        if (self::childrenAreComplete($task)) {
            return $task->children->filter(fn (WorkOrder $child) => (int) $child->job_status !== 4)->count();
        }

        return $task->children()->where('job_status', '!=', 4)->count();
    }

    private function childCount(WorkOrder $task): int
    {
        return self::childrenAreComplete($task)
            ? $task->children->count()
            : $task->children()->count();
    }

    /**
     * ความสัมพันธ์ children ที่โหลดมาเป็น "ชุดเต็ม" หรือถูกตัดด้วยสิทธิ์ไปแล้ว
     *
     * CrossDepartmentWork::restrictChildren() ตัด children ของงานที่ผู้ดูเห็นได้บางส่วน
     * ให้เหลือเฉพาะงานย่อยที่เขาร่วม ถ้านับด่านจากชุดที่ถูกตัด ตัวเลขจะต่ำกว่าความจริง
     * แล้วหน้าจอจะบอกว่าปิดงานได้ทั้งที่ server จะปฏิเสธ จึงต้องกลับไปนับจากฐานข้อมูล
     * ส่วนงานที่ยังไม่ถูกตัดยังนับในหน่วยความจำได้เหมือนเดิม ไม่เพิ่มคิวรีให้หน้า Workspace
     */
    private static function childrenAreComplete(WorkOrder $task): bool
    {
        return $task->relationLoaded('children')
            && ! CrossDepartmentWork::childrenWereRestricted($task);
    }

    /**
     * รหัสงานย่อยทุกชั้นที่ยังไม่เสร็จ — controller ใช้ส่งสถานะใหม่ของงานย่อยกลับไปให้หน้าจอ
     * หลังผู้ดูแลระบบปิดงานแม่พร้อมงานย่อย
     *
     * @return array<int>
     */
    public function openSubtaskIds(WorkOrder $task): array
    {
        return $this->openDescendants($task)
            ->map(fn (WorkOrder $child) => (int) $child->job_id)
            ->values()
            ->all();
    }

    private function canCloseWithSubtasks(WorkOrder $task, User $actor): bool
    {
        return Gate::forUser($actor)->allows('closeWithSubtasks', $task);
    }

    /**
     * งานย่อยที่ค้างทุกใบต้องปิดได้จริงก่อนเริ่ม ไม่งั้นจะปิดไปได้ครึ่งเดียวแล้วเด้ง 403
     *
     * งานย่อยที่ยังรออนุมัติ (หรือถูกปฏิเสธ) ยังไม่อยู่ในวงจรสถานะ ผู้ดูแลต้องตัดสินคำขอนั้นก่อน
     * ซึ่งตรงกับกติกาของงานแม่ — งานที่ยังไม่อนุมัติเปลี่ยนสถานะไม่ได้
     */
    private function assertSubtasksCanBeClosed(WorkOrder $task, User $actor): void
    {
        $blocked = $this->openDescendants($task)
            ->reject(fn (WorkOrder $child) => Gate::forUser($actor)->allows('overrideStatus', $child))
            ->count();

        if ($blocked > 0) {
            $this->reject('มีงานย่อยที่ยังไม่ได้รับอนุมัติ '.$blocked.' งาน ต้องอนุมัติหรือปฏิเสธงานย่อยเหล่านั้นก่อนจึงจะปิดงานนี้ได้');
        }
    }

    /**
     * ปิดงานย่อยชั้นถัดไปที่ค้างทีละใบผ่าน transition() — ชั้นที่ลึกกว่าถูกปิดต่อในการเรียกซ้ำ
     * ทำงานอยู่ใน DB::transaction ของงานแม่ ถ้าใบใดล้ม งานทุกใบย้อนกลับพร้อมกัน
     */
    private function closeOpenSubtasks(WorkOrder $task, User $actor): void
    {
        $task->children()
            ->where('job_status', '!=', 4)
            ->orderBy('parent_sort_order')
            ->orderBy('job_id')
            ->get()
            ->each(fn (WorkOrder $child) => $this->transition($child, $actor, 4, [
                'close_subtasks' => true,
                'reason' => 'ปิดพร้อมงานแม่ “'.$task->job_topic.'” โดยผู้ดูแลระบบ',
            ]));
    }

    /**
     * งานย่อยทุกชั้นที่ยังไม่เสร็จ — ไล่ลงไปเฉพาะสายที่ยังเปิดอยู่ เพราะ closeOpenSubtasks()
     * ข้ามงานที่ปิดแล้วไปทั้งกิ่ง ทั้งสองที่จึงเห็นชุดงานเดียวกัน
     *
     * @return Collection<int, WorkOrder>
     */
    private function openDescendants(WorkOrder $task): Collection
    {
        $found = collect();
        $seen = [(int) $task->job_id];
        $parents = $seen;

        while ($parents !== []) {
            $level = WorkOrder::query()
                ->whereIn('parent_job_id', $parents)
                ->where('job_status', '!=', 4)
                ->get()
                ->reject(fn (WorkOrder $child) => in_array((int) $child->job_id, $seen, true))
                ->values();

            $found = $found->merge($level);
            $parents = $level->map(fn (WorkOrder $child) => (int) $child->job_id)->all();
            $seen = array_merge($seen, $parents);
        }

        return $found;
    }

    private function isSelfTask(WorkOrder $task, User $actor): bool
    {
        return TaskReviewStage::isSelfTask($task, $actor);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['job_status' => $message]);
    }
}
