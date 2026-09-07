<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\ProtectedMedia;
use App\Support\WorkspaceBoardPresenter;
use App\Support\WorkspaceDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * รูปภาพที่แนบบนกระดานไอเดีย
 *
 * ยังเรียก assertAllowedAttachments() ของ ValidatesAttachments เพื่อรักษาการ
 * ตรวจคู่ extension กับ MIME ที่อ่านจากเนื้อไฟล์จริง (กันไฟล์ที่เปลี่ยนนามสกุลมา
 * หลอก) แต่ไม่ใช้ attachmentRules() ตรง ๆ เพราะกติกาที่ใช้ร่วมกับใบงานอนุญาต
 * docx/xlsx/zip และไฟล์ขนาดถึง 1 GB ซึ่งไม่เหมาะกับรูปที่ต้องเรนเดอร์บนผืนผ้าใบ
 *
 * ห้ามแก้ AttachmentPolicy ให้แคบลงเพื่อฟีเจอร์นี้ เพราะใช้ร่วมกับงานโครงการ
 * การกรองที่แคบกว่าจึงประกอบขึ้นที่นี่ โดยยังผ่านการ์ดเดิมทุกชั้น
 */
class WorkspaceBoardAttachmentController extends Controller
{
    use RespondsWithTaskResult, ValidatesAttachments;

    public function store(Request $request, WorkspaceBoard $board)
    {
        // ผูกกับสิทธิ์แก้ไข ไม่ใช่สิทธิ์ดู คนนอกแผนกเปิดกระดานสาธารณะได้
        // แต่ต้องแนบรูปเข้าไปไม่ได้
        Gate::authorize('update', $board);

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.WorkspaceDesign::MAX_IMAGES],
            'images.*' => [
                'file',
                'mimes:'.implode(',', WorkspaceDesign::IMAGE_EXTENSIONS),
                'max:'.WorkspaceDesign::IMAGE_MAX_KILOBYTES,
            ],
        ]);

        // การ์ดเดิมของระบบ ตรวจว่านามสกุลกับ MIME ที่อ่านจากเนื้อไฟล์เข้าคู่กัน
        $this->assertAllowedAttachments($request, 'images');

        // แล้วค่อยกรองให้แคบลงเหลือเฉพาะรูปที่กระดานรองรับ
        $this->assertImagesOnly($request);
        $this->assertRoomForMoreImages($board, $request);

        $directory = 'workspace-board-attachments/'.$board->id;
        $stored = $this->collectStoredAttachments($request, 'images', $directory);
        $created = [];

        try {
            foreach ($stored as $file) {
                $dimensions = $this->dimensionsOf($file['path']);

                $created[] = WorkspaceBoardAttachment::create([
                    'workspace_board_id' => $board->id,
                    'file_path' => $file['path'],
                    'original_name' => $file['original_name'],
                    'file_type' => $file['file_type'],
                    'image_width' => $dimensions['width'],
                    'image_height' => $dimensions['height'],
                    'byte_size' => $dimensions['bytes'],
                    'uploaded_by' => Auth::id(),
                ]);
            }
        } catch (Throwable $exception) {
            // ถ้าเขียนแถวไม่สำเร็จ ต้องไม่ทิ้งไฟล์กำพร้าไว้ใน storage
            // (รูปแบบเดียวกับ WorkLogAttachmentController::store())
            foreach ($stored as $file) {
                ProtectedMedia::deleteAttachment($file['path']);
            }

            throw $exception;
        }

        return $this->jsonOrBack($request, true, 'แนบรูปเรียบร้อย', 200, [
            'attachments' => array_map(
                fn (WorkspaceBoardAttachment $attachment): array => WorkspaceBoardPresenter::attachmentForClient($attachment),
                $created
            ),
        ]);
    }

    public function destroy(Request $request, WorkspaceBoard $board, WorkspaceBoardAttachment $attachment)
    {
        Gate::authorize('update', $board);

        // กันการส่ง id ของไฟล์แนบที่อยู่คนละกระดานเข้ามา
        abort_unless((int) $attachment->workspace_board_id === (int) $board->id, 404);

        // เข้าถังขยะก่อน แล้วค่อยลบชั่วคราว ไฟล์จริงยังอยู่บนดิสก์จนกว่าจะลบถาวร
        // (KeepsFileUntilPurged ลบไฟล์เมื่อ forceDelete() เท่านั้น)
        AuditTrail::trash($attachment, $request->user(), [
            'attachment' => $attachment->attributesToArray(),
            'board' => ['id' => $board->id, 'title' => $board->title],
        ]);

        $attachment->delete();

        return $this->jsonOrBack($request, true, 'ลบรูปเรียบร้อย', 200, [
            'attachment_id' => $attachment->id,
        ]);
    }

    /**
     * กรองให้เหลือเฉพาะนามสกุลรูปที่กระดานรองรับ
     *
     * ซ้อนอยู่หลัง assertAllowedAttachments() ซึ่งอนุญาตเอกสารและ zip ด้วย
     * ต้องเช็คทั้งสองชั้น ไม่ใช่เลือกอย่างใดอย่างหนึ่ง ชั้นแรกคือการกันไฟล์
     * ปลอมนามสกุล ชั้นนี้คือการจำกัดขอบเขตของฟีเจอร์
     */
    private function assertImagesOnly(Request $request): void
    {
        foreach ($request->file('images') ?? [] as $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($extension, WorkspaceDesign::IMAGE_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'images' => 'กระดานรองรับเฉพาะไฟล์ '.implode(', ', WorkspaceDesign::IMAGE_EXTENSIONS),
                ]);
            }
        }
    }

    /**
     * เพดานจำนวนรูปต่อหนึ่งกระดาน
     *
     * ต้องนับรวมรูปที่มีอยู่แล้ว ไม่ใช่นับเฉพาะที่ส่งมาในคำขอนี้ ไม่งั้นอัปหลายรอบ
     * ก็ทะลุเพดานได้ (บทเรียนเดียวกับไฟล์แนบของบันทึกงานประจำวัน)
     */
    private function assertRoomForMoreImages(WorkspaceBoard $board, Request $request): void
    {
        $incoming = count($request->file('images') ?? []);
        $existing = $board->attachments()->count();

        if ($existing + $incoming > WorkspaceDesign::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => sprintf(
                    'แนบรูปได้ไม่เกิน %d รูปต่อหนึ่งกระดาน (ปัจจุบันมี %d รูป)',
                    WorkspaceDesign::MAX_IMAGES,
                    $existing
                ),
            ]);
        }
    }

    /**
     * ขนาดจริงของรูปที่เพิ่งเก็บลง storage
     *
     * อ่านจากไฟล์ที่เก็บแล้ว ไม่ใช่เชื่อค่าที่ฝั่งเบราว์เซอร์ส่งมา เพื่อให้การวาง
     * รูปบนกระดานได้สัดส่วนจริงเสมอ getimagesize() เป็นฟังก์ชันของ core PHP
     * ไม่ต้องพึ่งส่วนขยาย GD จึงใช้ได้แม้ในเครื่องที่ยังไม่ได้เปิด GD
     *
     * @return array{width: ?int, height: ?int, bytes: int}
     */
    private function dimensionsOf(string $path): array
    {
        $absolute = ProtectedMedia::attachmentAbsolutePath($path);

        if ($absolute === null) {
            return ['width' => null, 'height' => null, 'bytes' => 0];
        }

        $size = @getimagesize($absolute);

        return [
            'width' => $size === false ? null : (int) $size[0],
            'height' => $size === false ? null : (int) $size[1],
            'bytes' => (int) (@filesize($absolute) ?: 0),
        ];
    }
}
