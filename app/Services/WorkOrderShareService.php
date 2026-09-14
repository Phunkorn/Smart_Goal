<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderShare;
use App\Models\WorkOrderShareRequest;
use App\Support\AuditTrail;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * การแชร์งานและคำขอเข้าร่วม
 *
 * การรับคนเข้างานจริงยังเป็นหน้าที่ของ CollaboratorInvitationService::invite() ตัวเดิม
 * เสมอ ห้ามคัดลอกกติกา accepted/pending มาไว้ที่นี่
 *
 * สิ่งที่บริการนี้เป็นเจ้าของคือ "ใครมีอำนาจรับคนเข้างานที่ถูกแชร์" ซึ่งต่างจากการเชิญ
 * ผู้ร่วมงานแบบเดิม:
 *
 *   - หัวหน้าแผนกที่ดูแลแผนกปลายทางของงาน ชี้ขาดได้เอง จบในขั้นเดียว
 *   - พนักงานธรรมดาที่แชร์งาน ต้องส่งต่อให้หัวหน้าแผนก "ของตัวเอง" (เจ้าของงาน)
 *     ไม่ใช่หัวหน้าแผนกของผู้ขอ
 *
 * จึงไม่ใช้ pivot work_order_collaborators เป็นที่พักคำขอชั้นที่สอง แต่ใช้สถานะ
 * awaiting_head ของคำขอแชร์งานเอง แล้วไปแสดงในหน้าคำขออนุมัติหน้าเดิม
 */
class WorkOrderShareService
{
    public function __construct(
        private readonly CollaboratorInvitationService $invitations,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * เปิดประกาศแชร์งาน
     *
     * @throws RuntimeException เมื่องานใบนี้มีประกาศที่ยังเปิดอยู่แล้ว
     */
    public function open(WorkOrder $task, User $sharer, string $scope, ?string $note = null): WorkOrderShare
    {
        return DB::transaction(function () use ($task, $sharer, $scope, $note): WorkOrderShare {
            // ล็อกประกาศเดิมของงานใบนี้ก่อน เพื่อกันสองแท็บกดแชร์พร้อมกันแล้วได้
            // ประกาศซ้อนสองใบ ซึ่งจะทำให้ฟีดขึ้นงานเดียวกันสองการ์ด
            $existing = WorkOrderShare::query()
                ->where('work_order_id', $task->job_id)
                ->lockForUpdate()
                ->get();

            if ($existing->firstWhere('status', 'open')) {
                throw new RuntimeException('งานนี้ถูกแชร์อยู่แล้ว');
            }

            // admin ไม่ผูกกับแผนกเลย (UserController บังคับ department_id เป็น null)
            // จึงแชร์แบบเจาะจงแผนกไม่ได้ ต้องเป็นประกาศทั้งองค์กรเท่านั้น
            $departmentId = $sharer->department_id ?: $task->department_id ?: $task->user?->department_id;

            if ($scope === WorkOrderShare::SCOPE_DEPARTMENT && $departmentId === null) {
                throw new RuntimeException('บัญชีนี้ไม่ได้สังกัดแผนก จึงแชร์เฉพาะในแผนกไม่ได้');
            }

            $share = WorkOrderShare::create([
                'work_order_id' => $task->job_id,
                'shared_by' => $sharer->id,
                'department_id' => $departmentId,
                'scope' => $scope,
                'status' => 'open',
                'note' => $note,
            ]);

            AuditTrail::log('work_order_shared', $task, 'แชร์งาน: '.$task->job_topic, [
                'share_id' => $share->id,
                'scope' => $scope,
            ]);

            return $share;
        });
    }

    /**
     * ปิดประกาศ พร้อมยกเลิกคำขอที่ยังไม่ถูกตัดสิน
     *
     * คำขอที่ค้างต้องถูกปิดไปด้วย ไม่อย่างนั้นผู้ขอจะรอคำตอบที่ไม่มีวันมา
     */
    public function close(WorkOrderShare $share, User $actor): void
    {
        DB::transaction(function () use ($share, $actor): void {
            $locked = WorkOrderShare::query()->lockForUpdate()->findOrFail($share->id);

            if (! $locked->isOpen()) {
                return;
            }

            $locked->update(['status' => 'closed', 'closed_at' => now()]);

            $locked->requests()->where('status', 'pending')->update([
                'status' => 'cancelled',
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            AuditTrail::log('work_order_share_closed', $locked->workOrder, 'ปิดประกาศแชร์งาน', [
                'share_id' => $locked->id,
            ]);
        });
    }

    /**
     * ขอเข้าร่วมงานที่ถูกแชร์
     *
     * @throws RuntimeException เมื่อขอซ้ำหรือเกี่ยวข้องกับงานนี้อยู่แล้ว
     */
    public function requestJoin(WorkOrderShare $share, User $requester): WorkOrderShareRequest
    {
        $request = DB::transaction(function () use ($share, $requester): WorkOrderShareRequest {
            $locked = WorkOrderShare::query()
                ->with('workOrder')
                ->lockForUpdate()
                ->findOrFail($share->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException('ประกาศนี้ถูกปิดแล้ว');
            }

            $task = $locked->workOrder;

            if (! $task || (int) $task->job_status === 4) {
                throw new RuntimeException('งานนี้ปิดไปแล้ว จึงขอเข้าร่วมไม่ได้');
            }

            if ($this->alreadyInvolved($task, $requester)) {
                throw new RuntimeException('คุณอยู่ในงานนี้อยู่แล้ว');
            }

            $previous = $locked->requests()->where('requester_id', $requester->id)->first();

            if ($previous) {
                // unique(work_order_share_id, requester_id) ห้ามขอซ้ำในประกาศเดียวกันอยู่แล้ว
                // ข้อความจึงต้องบอกเหตุผลจริง ไม่ใช่ปล่อยให้ชน constraint เป็น 500
                throw new RuntimeException(match ($previous->status) {
                    'pending' => 'คุณส่งคำขอเข้าร่วมงานนี้ไปแล้ว กำลังรอผู้แชร์พิจารณา',
                    'approved' => 'คำขอของคุณได้รับการอนุมัติแล้ว',
                    'rejected' => 'คำขอของคุณถูกปฏิเสธไปแล้ว ติดต่อผู้แชร์โดยตรงหากต้องการเข้าร่วม',
                    default => 'คำขอก่อนหน้าถูกยกเลิกไปแล้ว',
                });
            }

            return $locked->requests()->create([
                'requester_id' => $requester->id,
                'status' => 'pending',
            ]);
        });

        $share->loadMissing('workOrder');

        // ใช้ notifyDetached เพราะ notify() กรองผู้รับด้วย Gate 'view' ของงาน ซึ่งผู้แชร์
        // ผ่านอยู่แล้ว แต่การผูก work_order_id ไว้จะทำให้ target() พาไปหน้างานแทน
        // หน้าคำขอ สิ่งที่ผู้แชร์ต้องไปคือคิวคำขอของตัวเอง
        $this->notifications->notifyDetached(
            [$share->shared_by],
            'share_join_requested',
            'มีคนขอเข้าร่วมงานที่คุณแชร์',
            $requester->name.' ขอเข้าร่วมงาน “'.($share->workOrder?->job_topic ?? '').'”',
            $requester,
            ['share_id' => $share->id, 'share_request_id' => $request->id],
            dedupePrefix: 'share_join_requested:'.$request->id,
        );

        return $request;
    }

    /**
     * ผู้ตัดสินคนนี้ชี้ขาดได้เอง หรือต้องส่งต่อหัวหน้าแผนกอีกขั้น
     *
     * แหล่งความจริงเดียวของคำถามนี้ — ทั้ง service, controller และ Blade อ่านจากที่นี่
     * ห้ามคัดลอกเงื่อนไขไปเขียนซ้ำที่อื่น ไม่อย่างนั้นข้อความบนหน้าจอกับสิ่งที่ระบบทำ
     * จริงจะเพี้ยนออกจากกัน ซึ่งเคยเกิดมาแล้ว
     *
     * หัวหน้าแผนกที่ดูแลแผนกปลายทางของงานเป็นผู้มีอำนาจสูงสุดของงานนั้นอยู่แล้ว
     * การบังคับให้เขาส่งคำขอต่อคือการให้เขาขออนุมัติจากตัวเอง
     *
     * ข้อควรระวัง: หัวหน้าแผนกถือ role = 'user' (UserController บังคับไว้) การเช็ค
     * ด้วย role === 'admin' อย่างเดียวจึงมองไม่เห็นหัวหน้าเลย ต้องใช้
     * overseesDepartment() ซึ่งเป็นตัวตัดสินเดียวกับที่ policy ทั้งระบบใช้
     */
    public function decidesAlone(User $decider, WorkOrder $task, User $requester): bool
    {
        $taskDepartmentId = $task->department_id ?: $task->user?->department_id;

        return $decider->role === 'admin'
            || $decider->overseesDepartment($taskDepartmentId)
            || ($taskDepartmentId !== null
                && (int) $requester->department_id === (int) $taskDepartmentId);
    }

    /**
     * ผู้แชร์อนุมัติคำขอ — ชั้นแรก
     *
     * จบในขั้นนี้เมื่อ decidesAlone() เป็นจริง มิฉะนั้นคำขอเข้าสถานะ awaiting_head
     * แล้วรอหัวหน้าแผนกของผู้แชร์ตัดสินในหน้าคำขออนุมัติ
     *
     * @return array{status: int, message: string, collaborator_status: ?string}
     */
    public function approve(WorkOrderShareRequest $request, User $decider): array
    {
        $outcome = DB::transaction(function () use ($request, $decider): array {
            $locked = $this->lockRequest($request);

            if ($locked->status !== 'pending') {
                return ['status' => 409, 'message' => 'คำขอนี้ถูกพิจารณาไปแล้ว', 'collaborator_status' => null];
            }

            $guard = $this->guardDecidable($locked);

            if ($guard !== null) {
                return $guard;
            }

            $task = $locked->share->workOrder;
            $requester = $locked->requester;

            // ยังไม่มีอำนาจชี้ขาด — ส่งต่อหัวหน้าแผนก ยังไม่แตะ pivot ผู้ร่วมงาน
            if (! $this->decidesAlone($decider, $task, $requester)) {
                $locked->update([
                    'status' => 'awaiting_head',
                    'decided_by' => $decider->id,
                    'decided_at' => now(),
                    'decision_reason' => null,
                ]);

                AuditTrail::log('work_order_share_request_escalated', $task, 'ส่งคำขอเข้าร่วมงานต่อให้หัวหน้าแผนก', [
                    'share_request_id' => $locked->id,
                    'user_id' => $requester->id,
                ]);

                return [
                    'status' => 200,
                    'escalated' => true,
                    'message' => 'ส่งคำขอต่อให้หัวหน้าแผนกของคุณพิจารณาแล้ว',
                    'collaborator_status' => null,
                    'requester_id' => $requester->id,
                    'task_topic' => $task->job_topic,
                    'task_department_id' => $task->department_id ?: $task->user?->department_id,
                    'share_request_id' => $locked->id,
                ];
            }

            return $this->admit($locked, $decider, headDecision: false);
        });

        $this->announce($outcome, $decider);

        return $outcome;
    }

    /**
     * หัวหน้าแผนกของผู้แชร์ (หรือ admin) ตัดสินคำขอชั้นที่สอง
     *
     * @return array{status: int, message: string, collaborator_status: ?string}
     */
    public function approveAsHead(WorkOrderShareRequest $request, User $decider): array
    {
        $outcome = DB::transaction(function () use ($request, $decider): array {
            $locked = $this->lockRequest($request);

            if ($locked->status !== 'awaiting_head') {
                return ['status' => 409, 'message' => 'คำขอนี้ถูกพิจารณาไปแล้ว', 'collaborator_status' => null];
            }

            $guard = $this->guardDecidable($locked);

            if ($guard !== null) {
                return $guard;
            }

            return $this->admit($locked, $decider, headDecision: true);
        });

        $this->announce($outcome, $decider);

        return $outcome;
    }

    /**
     * รับผู้ขอเข้างานจริง
     *
     * ผู้ตัดสินที่มาถึงจุดนี้ผ่านการตรวจอำนาจมาแล้วทั้งสองเส้นทาง จึงส่ง
     * actorHasFinalSay: true เข้าไป ผลของ invite() จึงเป็น accepted เสมอ
     *
     * @return array{status: int, message: string, collaborator_status: ?string}
     */
    private function admit(WorkOrderShareRequest $locked, User $decider, bool $headDecision): array
    {
        $task = $locked->share->workOrder;
        $requester = $locked->requester;

        $collaboratorStatus = $this->invitations->invite($task, $requester, $decider, actorHasFinalSay: true);

        if ($collaboratorStatus === null) {
            return [
                'status' => 422,
                'message' => 'เพิ่มผู้ขอเข้าร่วมงานไม่ได้ — อาจอยู่ในงานนี้อยู่แล้วหรือบัญชีไม่พร้อมรับงาน',
                'collaborator_status' => null,
            ];
        }

        $locked->update([
            'status' => 'approved',
            'collaborator_status' => $collaboratorStatus,
            'decision_reason' => null,
            // การตัดสินของหัวหน้าเป็นคนละเหตุการณ์กับของผู้แชร์ จึงไม่เขียนทับกัน
            ...$headDecision
                ? ['head_decided_by' => $decider->id, 'head_decided_at' => now()]
                : ['decided_by' => $decider->id, 'decided_at' => now()],
        ]);

        AuditTrail::log('work_order_share_request_approved', $task, 'อนุมัติคำขอเข้าร่วมงานที่แชร์', [
            'share_request_id' => $locked->id,
            'user_id' => $requester->id,
            'decided_as_head' => $headDecision,
        ]);

        return [
            'status' => 200,
            'message' => 'อนุมัติแล้ว ผู้ขอเข้าร่วมงานเรียบร้อย',
            'collaborator_status' => $collaboratorStatus,
            'requester_id' => $requester->id,
            'task_topic' => $task->job_topic,
            'share_request_id' => $locked->id,
        ];
    }

    private function lockRequest(WorkOrderShareRequest $request): WorkOrderShareRequest
    {
        return WorkOrderShareRequest::query()
            ->with(['share.workOrder.user.department', 'requester.department'])
            ->lockForUpdate()
            ->findOrFail($request->id);
    }

    /**
     * เงื่อนไขที่ทำให้คำขอตัดสินไม่ได้แล้ว ตรวจซ้ำบนแถวที่ล็อกไว้เสมอ
     *
     * @return array{status: int, message: string, collaborator_status: ?string}|null
     */
    private function guardDecidable(WorkOrderShareRequest $locked): ?array
    {
        $share = $locked->share;
        $task = $share?->workOrder;

        if (! $share || ! $task) {
            return ['status' => 422, 'message' => 'ไม่พบงานของประกาศนี้แล้ว', 'collaborator_status' => null];
        }

        if (! $share->isOpen()) {
            return ['status' => 422, 'message' => 'ประกาศนี้ถูกปิดไปแล้ว', 'collaborator_status' => null];
        }

        if ((int) $task->job_status === 4) {
            return ['status' => 422, 'message' => 'งานนี้ปิดไปแล้ว จึงเพิ่มผู้ร่วมงานไม่ได้', 'collaborator_status' => null];
        }

        if (! $locked->requester?->is_active) {
            return ['status' => 422, 'message' => 'บัญชีของผู้ขอถูกปิดใช้งานแล้ว', 'collaborator_status' => null];
        }

        return null;
    }

    /**
     * แจ้งเตือนหลังทรานแซกชันปิดแล้ว
     *
     * ส่งนอกทรานแซกชัน เพื่อไม่ให้แจ้งเตือนหลุดออกไปแล้วธุรกรรมถูกย้อนกลับทีหลัง
     *
     * @param  array<string, mixed>  $outcome
     */
    private function announce(array $outcome, User $decider): void
    {
        if ($outcome['status'] !== 200) {
            return;
        }

        // ส่งต่อหัวหน้าแผนกของผู้แชร์ — departmentApprovalRecipientIds() fallback ไปหา
        // admin ให้เองเมื่อแผนกนั้นไม่มีหัวหน้า ซึ่งเป็นพฤติกรรมที่ต้องการ
        if ($outcome['escalated'] ?? false) {
            $this->notifications->notifyDetached(
                $this->notifications->departmentApprovalRecipientIds($outcome['task_department_id']),
                'share_join_awaiting_head',
                'ขออนุมัติผู้ร่วมงานข้ามแผนกจากการแชร์งาน',
                $decider->name.' อนุมัติให้มีผู้ขอเข้าร่วมงาน “'.$outcome['task_topic'].'” รอการอนุมัติจากหัวหน้าแผนก',
                $decider,
                ['share_request_id' => $outcome['share_request_id']],
                dedupePrefix: 'share_join_awaiting_head:'.$outcome['share_request_id'],
            );

            return;
        }

        $this->notifications->notifyDetached(
            [$outcome['requester_id']],
            'share_join_approved',
            'คำขอเข้าร่วมงานได้รับการอนุมัติ',
            'คุณเข้าร่วมงาน “'.$outcome['task_topic'].'” แล้ว',
            $decider,
            ['share_request_id' => $outcome['share_request_id']],
            dedupePrefix: 'share_join_approved:'.$outcome['share_request_id'],
        );
    }

    /** @return array{status: int, message: string} */
    public function reject(WorkOrderShareRequest $request, User $decider, ?string $reason = null): array
    {
        $outcome = DB::transaction(function () use ($request, $decider, $reason): array {
            $locked = WorkOrderShareRequest::query()
                ->with(['share.workOrder', 'requester'])
                ->lockForUpdate()
                ->findOrFail($request->id);

            // ปฏิเสธได้ทั้งชั้นผู้แชร์ (pending) และชั้นหัวหน้าแผนก (awaiting_head)
            if (! in_array($locked->status, ['pending', 'awaiting_head'], true)) {
                return ['status' => 409, 'message' => 'คำขอนี้ถูกพิจารณาไปแล้ว'];
            }

            $headDecision = $locked->status === 'awaiting_head';

            $locked->update([
                'status' => 'rejected',
                'decision_reason' => $reason,
                ...$headDecision
                    ? ['head_decided_by' => $decider->id, 'head_decided_at' => now()]
                    : ['decided_by' => $decider->id, 'decided_at' => now()],
            ]);

            AuditTrail::log('work_order_share_request_rejected', $locked->share?->workOrder, 'ปฏิเสธคำขอเข้าร่วมงานที่แชร์', [
                'share_request_id' => $locked->id,
                'user_id' => $locked->requester_id,
            ]);

            return [
                'status' => 200,
                'message' => 'ปฏิเสธคำขอแล้ว',
                'requester_id' => $locked->requester_id,
                'task_topic' => $locked->share?->workOrder?->job_topic ?? '',
            ];
        });

        if ($outcome['status'] === 200) {
            $this->notifications->notifyDetached(
                [$outcome['requester_id']],
                'share_join_rejected',
                'คำขอเข้าร่วมงานถูกปฏิเสธ',
                'คำขอเข้าร่วมงาน “'.$outcome['task_topic'].'” ถูกปฏิเสธ'.($reason ? ' — '.$reason : ''),
                $decider,
                ['share_request_id' => $request->id],
                dedupePrefix: 'share_join_rejected:'.$request->id,
            );
        }

        return $outcome;
    }

    /**
     * ผู้ใช้คนนี้อยู่ในงานอยู่แล้วหรือยัง
     *
     * นับผู้ร่วมงานทุกสถานะ รวม pending และ rejected เพราะ
     * work_order_collaborators มี unique(work_order_id, user_id) การปล่อยให้ขอ
     * ซ้ำจะจบลงที่ invite() คืน null แล้วผู้ขอไม่รู้ว่าเกิดอะไรขึ้น
     */
    private function alreadyInvolved(WorkOrder $task, User $user): bool
    {
        if (in_array((int) $user->id, array_filter([
            (int) $task->user_id,
            (int) $task->created_by,
            (int) $task->leader_user_id,
        ]), true)) {
            return true;
        }

        return $task->collaborators()->where('users.id', $user->id)->exists();
    }
}
