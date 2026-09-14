<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkOrderShare;
use App\Models\WorkOrderShareRequest;
use App\Services\WorkOrderShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * คำขอเข้าร่วมงานที่ถูกแชร์
 *
 * การอนุมัติที่นี่เป็นชั้นแรกเท่านั้น คำขอข้ามแผนกยังต้องผ่านหัวหน้าแผนกของผู้ขอ
 * ผ่านคิวคำขออนุมัติเดิม ข้อความตอบกลับจึงต้องบอกให้ชัดว่ายังไม่จบ
 */
class WorkOrderShareRequestController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(private readonly WorkOrderShareService $shares) {}

    public function store(Request $request, WorkOrderShare $share)
    {
        $this->authorize('requestJoin', $share);

        try {
            $shareRequest = $this->shares->requestJoin($share, $request->user());
        } catch (RuntimeException $exception) {
            return $this->jsonOrBack($request, false, $exception->getMessage(), 422);
        }

        return $this->jsonOrBack($request, true, 'ส่งคำขอเข้าร่วมงานแล้ว รอผู้แชร์พิจารณา', 201, [
            'share_request_id' => $shareRequest->id,
        ]);
    }

    /**
     * อนุมัติคำขอ — route เดียวรับทั้งสองชั้น
     *
     * ชั้นไหนตัดสินจากสถานะปัจจุบันของคำขอ ไม่ใช่จาก route คนละเส้น เพื่อให้ฝั่งหน้าจอ
     * มีปุ่ม "อนุมัติ" ชุดเดียว ไม่ต้องรู้ว่าตัวเองกำลังอยู่ชั้นไหน
     */
    public function approve(Request $request, WorkOrderShareRequest $shareRequest)
    {
        $this->authorizeDecision($shareRequest);

        $outcome = $shareRequest->status === 'awaiting_head'
            ? $this->shares->approveAsHead($shareRequest, $request->user())
            : $this->shares->approve($shareRequest, $request->user());

        return $this->jsonOrBack(
            $request,
            $outcome['status'] === 200,
            $outcome['message'],
            $outcome['status'],
            ['collaborator_status' => $outcome['collaborator_status']],
        );
    }

    public function reject(Request $request, WorkOrderShareRequest $shareRequest)
    {
        $this->authorizeDecision($shareRequest);

        $validated = $request->validate([
            'decision_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $outcome = $this->shares->reject($shareRequest, $request->user(), $validated['decision_reason'] ?? null);

        return $this->jsonOrBack($request, $outcome['status'] === 200, $outcome['message'], $outcome['status']);
    }

    /**
     * ผ่านได้ถ้ามีสิทธิ์ตัดสินชั้นใดชั้นหนึ่ง
     *
     * ไม่เลือก ability จากสถานะปัจจุบันตรง ๆ เพราะคำขอที่ถูกตัดสินไปแล้วจะไม่เข้า
     * เงื่อนไขของชั้นไหนเลย คนที่เพิ่งกดอนุมัติไปเองแล้วกดซ้ำ (หรือเปิดสองแท็บ) จะได้
     * 403 ทั้งที่คำตอบที่ถูกต้องคือ 409 "ถูกพิจารณาไปแล้ว" ซึ่ง service เป็นคนตอบ
     */
    private function authorizeDecision(WorkOrderShareRequest $shareRequest): void
    {
        $shareRequest->loadMissing('share.workOrder.user');
        $share = $shareRequest->share;

        abort_unless(
            $share && (Gate::allows('review', $share) || Gate::allows('reviewAsHead', $share)),
            403
        );
    }
}
