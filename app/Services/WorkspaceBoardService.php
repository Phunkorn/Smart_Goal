<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardDocument;
use App\Support\AuditTrail;
use App\Support\WorkspaceDesign;
use Illuminate\Support\Facades\DB;

/**
 * การเขียนข้อมูลระดับ "ตัวกระดาน" ของกระดานไอเดีย (สร้าง เปลี่ยนชื่อ สลับการมองเห็น ลบ)
 *
 * เนื้อหาบนผืนผ้าใบเป็นหน้าที่ของ WorkspaceBoardDocumentService แยกกันเพราะสอง
 * อย่างนี้มีจังหวะการเขียนต่างกันมาก ตัวกระดานถูกแก้นาน ๆ ครั้งโดยคนที่มีสิทธิ์
 * จัดการ ส่วนเนื้อหาถูกเขียนทุกไม่กี่วินาทีระหว่างที่มีคนวาด และมีกลไกตรวจเวอร์ชัน
 * ของตัวเอง
 */
class WorkspaceBoardService
{
    /**
     * สร้างกระดานเปล่าพร้อมเนื้อหาเริ่มต้น
     *
     * ทำใน transaction เดียวเพราะกระดานที่ไม่มีแถวใน workspace_board_documents
     * เป็นสถานะที่โค้ดส่วนอื่นไม่รองรับ การบันทึกอัตโนมัติจะหาแถวไม่เจอแล้วมองว่า
     * เป็นการชนกันของเวอร์ชัน ซึ่งผู้ใช้แก้เองไม่ได้
     *
     * เนื้อหาเริ่มต้นเขียนที่นี่ไม่ใช่ที่ migration เพราะ MySQL ไม่อนุญาตให้
     * คอลัมน์ json มีค่า DEFAULT
     */
    public function create(User $actor, Department $department, string $title, string $visibility): WorkspaceBoard
    {
        return DB::transaction(function () use ($actor, $department, $title, $visibility): WorkspaceBoard {
            $board = WorkspaceBoard::query()->create([
                'department_id' => $department->id,
                'created_by' => $actor->id,
                'title' => $title,
                'visibility' => $visibility,
            ]);

            $document = WorkspaceDesign::emptyDocument();
            $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);

            WorkspaceBoardDocument::query()->create([
                'workspace_board_id' => $board->id,
                'document' => $document,
                'byte_size' => strlen($encoded),
            ]);

            AuditTrail::log(
                'workspace_board_created',
                $board,
                'สร้างกระดานไอเดีย: '.$board->title,
                ['after' => $this->auditSnapshot($board)]
            );

            return $board;
        });
    }

    /**
     * เปลี่ยนชื่อและระดับการมองเห็น
     *
     * บันทึก audit เฉพาะเมื่อมีอะไรเปลี่ยนจริง เพื่อไม่ให้การกดบันทึกซ้ำ ๆ ในหน้า
     * ตั้งค่าทิ้งรายการที่อ่านไม่ได้ความไว้เต็ม audit log
     */
    public function updateSettings(WorkspaceBoard $board, string $title, string $visibility): WorkspaceBoard
    {
        $before = $this->auditSnapshot($board);

        $board->fill([
            'title' => $title,
            'visibility' => $visibility,
        ]);

        if (! $board->isDirty()) {
            return $board;
        }

        $board->save();

        AuditTrail::log(
            'workspace_board_updated',
            $board,
            'แก้ไขการตั้งค่ากระดานไอเดีย: '.$board->title,
            ['before' => $before, 'after' => $this->auditSnapshot($board)]
        );

        return $board;
    }

    /**
     * ลบกระดานแบบ soft delete
     *
     * ตั้งใจไม่ลบไฟล์แนบตามไปด้วย เพราะกระดานที่ลบแล้วต้องกู้คืนได้พร้อมรูปครบ
     * การลบไฟล์จริงจะเกิดตอน forceDelete ซึ่งเป็นสิทธิ์ของ admin เท่านั้น
     */
    public function delete(WorkspaceBoard $board): void
    {
        // เข้าถังขยะด้วย เพื่อให้กระดานที่ลบไปโผล่ในหน้า Audit Log และมีปุ่มกู้คืน
        // เหมือนข้อมูลชนิดอื่น เดิมบันทึกแค่กิจกรรม กระดานจึงกู้คืนผ่านหน้าจอไม่ได้เลย
        AuditTrail::trash($board, null, [
            'board' => $board->attributesToArray(),
        ]);

        AuditTrail::log(
            'workspace_board_deleted',
            $board,
            'ลบกระดานไอเดีย: '.$board->title,
            ['before' => $this->auditSnapshot($board)]
        );

        $board->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(WorkspaceBoard $board): array
    {
        return [
            'id' => $board->id,
            'title' => $board->title,
            'visibility' => $board->visibility,
            'department_id' => $board->department_id,
        ];
    }
}
