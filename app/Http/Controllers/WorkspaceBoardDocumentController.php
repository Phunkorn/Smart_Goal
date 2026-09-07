<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkspaceVersionConflict;
use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkspaceBoard;
use App\Services\WorkspaceBoardDocumentService;
use App\Support\WorkspaceBoardPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * อ่านและบันทึกเนื้อหาบนผืนผ้าใบของกระดานไอเดีย
 *
 * แยกจาก WorkspaceBoardController เพราะสอง endpoint นี้ถูกเรียกด้วยจังหวะที่ต่างกัน
 * มาก การบันทึกอัตโนมัติยิงทุกไม่กี่วินาทีระหว่างที่มีคนวาด ส่วนการจัดการตัวกระดาน
 * เกิดนาน ๆ ครั้ง การรวมไว้ด้วยกันจะทำให้ controller เดียวมีสองอัตราการเรียกที่
 * ต้องปรับจูนคนละแบบ
 */
class WorkspaceBoardDocumentController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly WorkspaceBoardDocumentService $documents,
    ) {}

    /**
     * ดึงเนื้อหาฉบับล่าสุด (ปุ่มรีเฟรช และการกู้คืนหลังชนเวอร์ชัน)
     *
     * ใช้สิทธิ์ view ไม่ใช่ update เพราะคนที่ดูอย่างเดียวก็ต้องเห็นภาพวาดล่าสุด
     */
    public function show(Request $request, WorkspaceBoard $board)
    {
        Gate::authorize('view', $board);

        $state = $this->documents->currentState($board);

        return response()->json([
            'ok' => true,
            ...$state,
            // เติม URL ของรูปให้ก่อนส่งออก เอกสารที่เก็บไว้มีแค่ attachmentId
            'document' => WorkspaceBoardPresenter::documentForClient($board, $state['document']),
        ]);
    }

    /**
     * บันทึกเนื้อหาทับของเดิม
     *
     * ใช้สิทธิ์ update ซึ่งเป็นสิทธิ์เดียวกับ "วาดบนกระดานนี้ได้" คนนอกแผนกจึงถูก
     * ปฏิเสธที่นี่แม้จะเปิดหน้าดูได้ และแม้จะยิงคำขอตรงโดยไม่ผ่านหน้าจอ
     */
    public function update(Request $request, WorkspaceBoard $board)
    {
        Gate::authorize('update', $board);

        // ตรวจแค่รูปร่างชั้นนอกที่นี่ ส่วนเนื้อในของ document เป็นหน้าที่ของ
        // WorkspaceDocumentValidator ซึ่งรู้จักชนิดของชิ้นงานแต่ละแบบ
        $request->validate([
            'base_version' => ['required', 'integer', 'min:1'],
            'document' => ['required', 'array'],
        ]);

        try {
            $result = $this->documents->save(
                $board,
                Auth::user(),
                $request->input('document'),
                (int) $request->integer('base_version'),
                $this->allowedAttachmentIds($board),
            );
        } catch (WorkspaceVersionConflict $conflict) {
            // ส่งเนื้อหาฉบับล่าสุดกลับไปด้วยเลย หน้าจอจะได้กู้คืนได้ในคำขอเดียว
            // ไม่ต้องยิง GET ตามอีกรอบขณะที่ผู้ใช้กำลังรออยู่หน้ากล่องยืนยัน
            return $this->jsonOrBack(
                $request,
                false,
                'มีคนอื่นบันทึกกระดานนี้ไปแล้ว กรุณาโหลดฉบับล่าสุด',
                409,
                [
                    'code' => 'version_conflict',
                    ...$conflict->currentState,
                    'document' => WorkspaceBoardPresenter::documentForClient(
                        $board,
                        $conflict->currentState['document']
                    ),
                ]
            );
        }

        return $this->jsonOrBack($request, true, 'บันทึกแล้ว', 200, $result);
    }

    /**
     * id ของไฟล์แนบที่เป็นของกระดานใบนี้
     *
     * ส่งให้ WorkspaceDocumentValidator เพื่อปฏิเสธชิ้นงานรูปภาพที่อ้างไฟล์ของ
     * กระดานใบอื่น ซึ่งจะเป็นการข้ามการตรวจสิทธิ์ของ MediaController ที่ตัดสิน
     * จากกระดานเจ้าของไฟล์
     *
     * @return array<int, int>
     */
    private function allowedAttachmentIds(WorkspaceBoard $board): array
    {
        return $board->attachments()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
