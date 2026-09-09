<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkLog;
use App\Models\WorkLogAttachment;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\ProtectedMedia;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ไฟล์แนบของบันทึกงานประจำวัน (รูปหน้างาน ใบเสร็จค่าเดินทาง ฯลฯ)
 *
 * ใช้ตารางแยกของตัวเองตาม precedent เดิมของระบบ ที่แต่ละโดเมนมีตารางไฟล์แนบ
 * ของตัวเอง แต่ใช้กติกาชนิดไฟล์และวิธีเก็บร่วมกันผ่าน ValidatesAttachments
 * ไฟล์เป็นไฟล์ส่วนตัว เสิร์ฟผ่าน MediaController::workLogAttachment() เท่านั้น
 */
class WorkLogAttachmentController extends Controller
{
    use RespondsWithTaskResult, ValidatesAttachments;

    public function store(Request $request, WorkLog $workLog)
    {
        // ผูกกับสิทธิ์แก้ไข ไม่ใช่สิทธิ์ดู — หัวหน้าและ admin เห็นบันทึกได้
        // แต่ต้องแนบไฟล์เข้าบันทึกของคนอื่นไม่ได้
        Gate::authorize('update', $workLog);

        $request->validate($this->attachmentRules('attachments', true));
        $this->assertAllowedAttachments($request, 'attachments');
        $this->assertRoomForMoreAttachments($workLog, $request);

        $directory = 'work-log-attachments/'.$workLog->id;
        $stored = $this->collectStoredAttachments($request, 'attachments', $directory);

        try {
            foreach ($stored as $file) {
                WorkLogAttachment::create([
                    'work_log_id' => $workLog->id,
                    'file_path' => $file['path'],
                    'original_name' => $file['original_name'],
                    'file_type' => $file['file_type'],
                    'uploaded_by' => Auth::id(),
                ]);
            }
        } catch (Throwable $exception) {
            // ถ้าเขียนแถวไม่สำเร็จ ต้องไม่ทิ้งไฟล์กำพร้าไว้ใน storage
            // (รูปแบบเดียวกับที่ MyTaskController::storeListAttachments() ทำ)
            foreach ($stored as $file) {
                ProtectedMedia::deleteAttachment($file['path']);
            }

            throw $exception;
        }

        return $this->jsonOrBack(
            $request,
            true,
            'แนบไฟล์เรียบร้อย',
            200,
            $this->payload($workLog)
        );
    }

    public function destroy(Request $request, WorkLog $workLog, WorkLogAttachment $attachment)
    {
        Gate::authorize('update', $workLog);

        // กันการส่ง id ของไฟล์แนบที่อยู่คนละบันทึกเข้ามา
        abort_unless((int) $attachment->work_log_id === (int) $workLog->id, 404);

        // เข้าถังขยะก่อน แล้วค่อยลบชั่วคราว ไฟล์จริงยังอยู่บนดิสก์จนกว่าจะลบถาวร
        // (KeepsFileUntilPurged ลบไฟล์เมื่อ forceDelete() เท่านั้น)
        AuditTrail::trash($attachment, $request->user(), [
            'attachment' => $attachment->attributesToArray(),
            'work_log' => ['id' => $workLog->id, 'title' => $workLog->title],
        ]);

        $attachment->delete();

        return $this->jsonOrBack(
            $request,
            true,
            'ลบไฟล์แนบเรียบร้อย',
            200,
            $this->payload($workLog)
        );
    }

    /**
     * ข้อมูลกลับในรูปแบบเดียวกับ endpoint อื่นของฟีเจอร์นี้
     *
     * ส่ง HTML ของแถวมาด้วย เพราะจำนวนไฟล์แนบแสดงอยู่ในไทม์ไลน์ หน้าจอจึงต้อง
     * รีเฟรชแถวให้ตรง โดยไม่ต้องมีเทมเพลตแถวชุดที่สองในฝั่ง JavaScript
     */
    private function payload(WorkLog $workLog): array
    {
        $fresh = $workLog->fresh(['category', 'project', 'task', 'attachments', 'user', 'participants']);
        $presented = WorkLogPresenter::forClient($fresh);

        return [
            'log' => $presented,
            'html' => view('daily-logs.components.log-card', [
                'log' => $fresh,
                'presented' => $presented,
                'capabilities' => ['canEdit' => true, 'isReadOnly' => false],
            ])->render(),
        ];
    }

    /**
     * เพดานไฟล์แนบต่อหนึ่งบันทึก
     *
     * ต่ำกว่าของงานโครงการโดยตั้งใจ (AttachmentPolicy::MAX_FILES) เพราะบันทึกงาน
     * ประจำวันเป็นรายการสั้น ๆ ไม่ใช่พื้นที่เก็บเอกสารโครงการ และต้องนับรวมไฟล์
     * ที่มีอยู่แล้ว ไม่ใช่นับเฉพาะที่ส่งมาในคำขอนี้ ไม่งั้นอัปหลายรอบก็ทะลุเพดานได้
     */
    private function assertRoomForMoreAttachments(WorkLog $workLog, Request $request): void
    {
        $incoming = count($request->file('attachments') ?? []);
        $existing = $workLog->attachments()->count();

        if ($existing + $incoming > WorkLogDesign::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'attachments' => sprintf(
                    'แนบไฟล์ได้ไม่เกิน %d ไฟล์ต่อหนึ่งบันทึก (ปัจจุบันมี %d ไฟล์)',
                    WorkLogDesign::MAX_ATTACHMENTS,
                    $existing
                ),
            ]);
        }
    }
}
