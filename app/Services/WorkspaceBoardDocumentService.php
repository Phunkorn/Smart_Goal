<?php

namespace App\Services;

use App\Exceptions\WorkspaceVersionConflict;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardDocument;
use App\Support\TodayWorkspace;
use App\Support\WorkspaceDesign;
use App\Support\WorkspaceDocumentValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การอ่านและเขียนเนื้อหาบนผืนผ้าใบ พร้อมกลไกกันสองคนเขียนทับกัน
 *
 * ระบบนี้ตั้งใจไม่ทำ realtime การกันเขียนทับจึงใช้ optimistic concurrency
 * ทั้งกระดาน คือฝั่งเบราว์เซอร์ส่งเลขเวอร์ชันที่ตัวเองโหลดมาด้วยทุกครั้ง
 * ถ้าไม่ตรงกับของจริงบนเซิร์ฟเวอร์ แปลว่ามีคนอื่นบันทึกไปก่อนแล้ว
 *
 * ห้ามเปลี่ยนไปเป็น "อ่านค่ามาเทียบแล้วค่อย save()"
 * ---------------------------------------------------------------
 * สองแท็บที่กดบันทึกพร้อมกันจะอ่านเวอร์ชันเดียวกัน ผ่านการเทียบทั้งคู่ แล้วเขียน
 * ทับกันเงียบ ๆ โดยไม่มีใครรู้ วิธีที่ถูกคือสั่ง UPDATE พร้อมเงื่อนไขเวอร์ชันใน
 * คำสั่งเดียว แล้วดูจำนวนแถวที่ถูกแก้ ฐานข้อมูลเป็นผู้ตัดสินว่าใครมาก่อน
 * วิธีนี้ให้ผลเหมือนกันทั้ง MySQL (production) และ SQLite (ทดสอบ) โดยไม่ต้องใช้
 * SELECT ... FOR UPDATE ซึ่ง SQLite ไม่รองรับ
 */
class WorkspaceBoardDocumentService
{
    /**
     * เนื้อหาปัจจุบันของกระดาน พร้อมเลขเวอร์ชันสำหรับให้เบราว์เซอร์ส่งกลับมาตอนบันทึก
     *
     * @return array<string, mixed>
     */
    public function currentState(WorkspaceBoard $board): array
    {
        $document = $this->documentRow($board);
        $board->loadMissing('lastEditor:id,name');

        return [
            'version' => (int) $document->content_version,
            'document' => $this->normalizeStoredDocument($document->document),
            'element_count' => (int) $board->element_count,
            'saved_by' => $board->lastEditor === null ? null : [
                'id' => $board->lastEditor->id,
                'name' => $board->lastEditor->name,
            ],
            'saved_at_label' => $this->savedAtLabel($board),
        ];
    }

    /**
     * บันทึกเนื้อหาใหม่ทับของเดิม ถ้าเวอร์ชันยังตรงกัน
     *
     * @param  array<int, int>  $allowedAttachmentIds
     * @return array<string, mixed>
     *
     * @throws WorkspaceVersionConflict
     * @throws ValidationException
     */
    public function save(
        WorkspaceBoard $board,
        User $actor,
        mixed $document,
        int $baseVersion,
        array $allowedAttachmentIds = [],
    ): array {
        // ทำความสะอาดก่อนเปิด transaction เพราะการตรวจข้อมูลไม่ต้องถือ lock
        // และ payload ที่ผิดรูปแบบต้องถูกปฏิเสธด้วย 422 ไม่ใช่ 409
        $clean = WorkspaceDocumentValidator::sanitize($document, $allowedAttachmentIds);
        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);

        return DB::transaction(function () use ($board, $actor, $clean, $encoded, $baseVersion): array {
            // เช็คเวอร์ชันและเขียนในคำสั่งเดียว (ดูเหตุผลบนหัวคลาส)
            $affected = DB::table('workspace_board_documents')
                ->where('workspace_board_id', $board->id)
                ->where('content_version', $baseVersion)
                ->update([
                    'document' => $encoded,
                    'byte_size' => strlen($encoded),
                    'content_version' => DB::raw('content_version + 1'),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new WorkspaceVersionConflict($this->currentState($board->fresh()));
            }

            // คอลัมน์สรุปเหล่านี้ไม่อยู่ใน $fillable โดยตั้งใจ ต้องเขียนผ่าน service
            // เท่านั้น จึงใช้ forceFill ไม่ใช่ update() ที่รับข้อมูลจาก request
            $board->forceFill([
                'element_count' => count($clean['elements']),
                'last_edited_by' => $actor->id,
                'last_edited_at' => now(),
            ])->save();

            return [
                'version' => $baseVersion + 1,
                'element_count' => count($clean['elements']),
                'saved_at_label' => $this->savedAtLabel($board),
            ];
        });
    }

    /**
     * แถวเนื้อหาของกระดาน สร้างให้อัตโนมัติถ้าหายไป
     *
     * ตามปกติ WorkspaceBoardService::create() สร้างไว้ให้แล้วใน transaction เดียวกัน
     * การกู้ตรงนี้มีไว้สำหรับกระดานที่เกิดก่อนโค้ดชุดนี้ หรือถูกสร้างจาก seeder
     * เพราะกระดานที่ไม่มีแถวเนื้อหาจะทำให้ทุกการบันทึกกลายเป็น 409 ที่ผู้ใช้แก้เองไม่ได้
     */
    private function documentRow(WorkspaceBoard $board): WorkspaceBoardDocument
    {
        $document = WorkspaceBoardDocument::query()->find($board->id);

        if ($document !== null) {
            return $document;
        }

        $empty = WorkspaceDesign::emptyDocument();

        $created = WorkspaceBoardDocument::query()->create([
            'workspace_board_id' => $board->id,
            'document' => $empty,
            'byte_size' => strlen((string) json_encode($empty, JSON_UNESCAPED_UNICODE)),
        ]);

        // content_version ไม่อยู่ใน $fillable โดยตั้งใจ ค่าเริ่มต้น 1 จึงมาจาก
        // ฐานข้อมูล ไม่ใช่จากโมเดล ต้องอ่านกลับมาก่อนใช้ ไม่งั้นจะได้ 0 แล้ว
        // การบันทึกครั้งแรกจะกลายเป็นการชนเวอร์ชันทันที
        return $created->refresh();
    }

    /**
     * เนื้อหาที่อ่านจากฐานข้อมูลอาจเป็น null หรือรูปแบบเก่า จึงบีบให้อยู่ในรูปที่
     * ฝั่งเบราว์เซอร์คาดหวังเสมอ หน้าจอจะได้ไม่ต้องเขียนโค้ดกันค่าว่างซ้ำอีกชั้น
     *
     * @return array{schema: int, elements: array<int, mixed>}
     */
    private function normalizeStoredDocument(mixed $document): array
    {
        if (! is_array($document) || ! isset($document['elements']) || ! is_array($document['elements'])) {
            return WorkspaceDesign::emptyDocument();
        }

        return ['schema' => 1, 'elements' => array_values($document['elements'])];
    }

    /**
     * เวลาที่บันทึกล่าสุดในเวลาทำการของกรุงเทพ (คอลัมน์เก็บเป็น UTC)
     */
    private function savedAtLabel(WorkspaceBoard $board): ?string
    {
        $savedAt = $board->last_edited_at;

        return $savedAt === null ? null : TodayWorkspace::businessNow($savedAt)->format('H:i');
    }
}
