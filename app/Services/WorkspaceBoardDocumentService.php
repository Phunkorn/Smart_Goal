<?php

namespace App\Services;

use App\Exceptions\WorkspaceVersionConflict;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardDocument;
use App\Support\TodayWorkspace;
use App\Support\WorkspaceBoardPresenter;
use App\Support\WorkspaceDesign;
use App\Support\WorkspaceDocumentValidator;
use App\Support\WorkspaceOperations;
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
    public const HISTORY_LIMIT = 50;

    public const HISTORY_BYTES = 8388608;

    /** Idle checks read only the revision. A pruned cursor falls back to a snapshot. */
    public function changesSince(WorkspaceBoard $board, int $since): array
    {
        $revision = (int) $board->document()->value('content_version');
        if ($revision === $since) {
            return ['version' => $revision, 'unchanged' => true];
        }
        // Read snapshot and revision together; never label an old snapshot with a newer revision.
        $row = $board->document()->first();
        if ($row === null) {
            return ['version' => 1, 'document' => WorkspaceDesign::emptyDocument()];
        }
        $revision = (int) $row->content_version;
        $changes = DB::table('workspace_board_changes')->where('workspace_board_id', $board->id)
            ->where('revision', '>', $since)->where('revision', '<=', $revision)->orderBy('revision')->get();
        if ($since < 1 || $changes->count() !== $revision - $since) {
            return ['version' => $revision, 'document' => WorkspaceBoardPresenter::documentForClient($board, $row->document)];
        }
        $upserts = [];
        $deleted = [];
        $order = [];
        foreach ($changes as $change) {
            $delta = json_decode($change->delta, true);
            foreach ($delta['deleted'] as $id) {
                unset($upserts[$id]);
                $deleted[$id] = true;
            }
            foreach ($delta['upserts'] as $element) {
                unset($deleted[$element['id']]);
                $upserts[$element['id']] = $element;
            }
            $order = $delta['order'];
        }

        return ['version' => $revision, 'delta' => [
            'upserts' => WorkspaceBoardPresenter::documentForClient($board, ['elements' => array_values($upserts)])['elements'],
            'deleted' => array_keys($deleted), 'order' => $order,
        ]];
    }

    public function clientState(WorkspaceBoard $board): array
    {
        $state = $this->currentState($board);
        $state['document'] = WorkspaceBoardPresenter::documentForClient($board, $state['document']);

        return $state;
    }

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
        ?string $requestId = null,
        ?array $operations = null,
    ): array {
        if ($operations !== null) {
            return $this->saveBatch($board, $actor, $operations, $baseVersion, $requestId);
        }
        // ทำความสะอาดก่อนเปิด transaction เพราะการตรวจข้อมูลไม่ต้องถือ lock
        // และ payload ที่ผิดรูปแบบต้องถูกปฏิเสธด้วย 422 ไม่ใช่ 409
        $clean = WorkspaceDocumentValidator::sanitize($document, $allowedAttachmentIds);
        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);

        return DB::transaction(function () use ($board, $actor, $clean, $encoded, $baseVersion): array {
            WorkspaceBoard::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $clean = WorkspaceDocumentValidator::sanitize($clean, $board->attachments()->pluck('id')->all());
            $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);
            $before = $this->documentRow($board)->document;
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

            $this->recordChange($board, $before, $clean, $baseVersion + 1);
            app(WorkspaceAttachmentLifecycle::class)->reconcile($board, $clean, $before);

            return [
                'version' => $baseVersion + 1,
                'element_count' => count($clean['elements']),
                'saved_at_label' => $this->savedAtLabel($board),
            ];
        });
    }

    private function saveBatch(WorkspaceBoard $board, User $actor, array $operations, int $baseVersion, ?string $requestId): array
    {
        $hash = hash('sha256', json_encode([$baseVersion, $operations], JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($board, $actor, $operations, $baseVersion, $requestId, $hash): array {
            $board = WorkspaceBoard::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('workspace_board_changes')->where('workspace_board_id', $board->id)->where('request_id', $requestId)->first();
            if ($receipt !== null) {
                if ($receipt->request_hash !== $hash || (int) $receipt->actor_id !== (int) $actor->id) {
                    throw ValidationException::withMessages(['request_id' => 'รหัสคำขอนี้ถูกใช้แล้ว']);
                }

                return [...$this->clientState($board), 'request_id' => $requestId, 'duplicate' => true];
            }
            $row = $this->documentRow($board);
            if ((int) $row->content_version !== $baseVersion) {
                throw new WorkspaceVersionConflict($this->currentState($board));
            }
            $next = WorkspaceOperations::apply($row->document, $operations);
            $allowed = collect($next['elements'])->contains('type', 'image') ? $board->attachments()->pluck('id')->all() : [];
            $clean = WorkspaceDocumentValidator::sanitize($next, $allowed);
            $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);
            if ($clean === $row->document) {
                return [...$this->clientState($board), 'request_id' => $requestId];
            }
            $affected = DB::table('workspace_board_documents')->where('workspace_board_id', $board->id)->where('content_version', $baseVersion)
                ->update(['document' => $encoded, 'byte_size' => strlen($encoded), 'content_version' => $baseVersion + 1, 'updated_at' => now()]);
            if ($affected !== 1) {
                throw new WorkspaceVersionConflict($this->currentState($board->fresh()));
            }
            $board->forceFill(['element_count' => count($clean['elements']), 'last_edited_by' => $actor->id, 'last_edited_at' => now()])->save();
            $this->recordChange($board, $row->document, $clean, $baseVersion + 1, $requestId, $actor->id, $hash);
            app(WorkspaceAttachmentLifecycle::class)->reconcile($board, $clean, $row->document);

            return ['version' => $baseVersion + 1, 'document' => WorkspaceBoardPresenter::documentForClient($board, $clean),
                'element_count' => count($clean['elements']), 'saved_by' => ['id' => $actor->id, 'name' => $actor->name],
                'saved_at_label' => $this->savedAtLabel($board), 'request_id' => $requestId];
        });
    }

    private function recordChange(WorkspaceBoard $board, array $before, array $after, int $revision, ?string $requestId = null, ?int $actor = null, ?string $hash = null): void
    {
        $encoded = json_encode(WorkspaceOperations::delta($before, $after), JSON_UNESCAPED_UNICODE);
        DB::table('workspace_board_changes')->insert(['workspace_board_id' => $board->id, 'revision' => $revision,
            'request_id' => $requestId, 'actor_id' => $actor, 'request_hash' => $hash,
            'delta' => $encoded, 'byte_size' => strlen($encoded), 'created_at' => now()]);
        $history = DB::table('workspace_board_changes')->where('workspace_board_id', $board->id)->orderByDesc('revision')->get(['id', 'byte_size']);
        $bytes = 0;
        $remove = [];
        foreach ($history as $index => $item) {
            $bytes += $item->byte_size;
            if ($index >= self::HISTORY_LIMIT || ($bytes > self::HISTORY_BYTES && $index > 0)) {
                $remove[] = $item->id;
            }
        }
        if ($remove !== []) {
            DB::table('workspace_board_changes')->whereIn('id', $remove)->delete();
        }
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

        $created = WorkspaceBoardDocument::query()->firstOrCreate(['workspace_board_id' => $board->id], [
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
