<?php

namespace App\Support\Concerns;

use App\Models\JobImage;
use App\Models\WorkOrder;
use App\Support\AttachmentPolicy;
use App\Support\ProtectedMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ตรวจสอบและจัดเก็บไฟล์แนบงาน (WorkOrder) แบบรวมศูนย์จุดเดียว
 * ใช้ allow-list เดียวกันทุกจุดที่รับไฟล์แนบ (สร้างงาน / ปิดงานพร้อมแนบไฟล์ /
 * อัปโหลดไฟล์เพิ่มทีหลัง ทั้งจากบอร์ดและจากหน้า "งานของฉัน") เพื่อไม่ให้มีช่องโหว่
 * หลุดจากจุดใดจุดหนึ่งที่ลืมเช็ค
 *
 * กติกาทั้งหมด (ชนิดไฟล์ ขนาด จำนวน) อยู่ที่ App\Support\AttachmentPolicy ที่เดียว
 * trait นี้เหลือหน้าที่เชื่อม Request เข้ากับกติกานั้นและเก็บไฟล์ลง storage เท่านั้น
 */
trait ValidatesAttachments
{
    /**
     * ป้องกันกรณีมีคนแก้ accept ฝั่ง client หรือปลอมนามสกุล/Content-Type มา
     */
    private function assertAllowedAttachments(Request $request, string $field): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        foreach ($request->file($field) as $file) {
            $reason = AttachmentPolicy::rejectionReason($file);

            if ($reason !== null) {
                abort(422, $reason);
            }
        }
    }

    /**
     * กฎ validation ของ Laravel สำหรับช่องไฟล์แนบหนึ่งช่อง
     * ให้ทุก controller ใช้ชุดเดียวกันแทนการเขียน max:5 / mimes:... ซ้ำเอง
     *
     * @return array<string, array<int, string>>
     */
    private function attachmentRules(string $field, bool $required = false): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'array', ...($required ? ['min:1'] : []), 'max:'.AttachmentPolicy::MAX_FILES],
            $field.'.*' => ['file', 'mimes:'.AttachmentPolicy::mimesRule(), 'max:'.AttachmentPolicy::MAX_KILOBYTES],
        ];
    }

    /**
     * เก็บไฟล์ลง storage แล้วคืนข้อมูลที่ต้องบันทึกลงตาราง โดยไม่ผูกกับตารางใด
     *
     * แยกออกมาเพราะแต่ละโดเมนมีตารางไฟล์แนบของตัวเอง (job_images ของงาน,
     * work_order_list_attachments ของโครงการ, work_log_attachments ของบันทึก
     * งานประจำวัน) แต่ "วิธีเก็บไฟล์และวิธีอ่าน MIME" ต้องเหมือนกันทุกที่
     * ก่อนหน้านี้ MyTaskController::storeListAttachments() ต้องคัดลอกลูปนี้ไป
     * เขียนเองเพราะ storeFiles() ผูกกับ WorkOrder
     *
     * @return array<int, array{path: string, original_name: string, file_type: string}>
     */
    private function collectStoredAttachments(Request $request, string $field, string $directory): array
    {
        if (! $request->hasFile($field)) {
            return [];
        }

        $stored = [];

        foreach ($request->file($field) as $file) {
            // getClientMimeType() คือค่า Content-Type ที่ client ส่งมา ปลอมได้และยาวไม่จำกัด
            // ต้องใช้ getMimeType() ที่ตรวจจากเนื้อไฟล์จริง ซึ่งเป็นแหล่งเดียวกับที่
            // AttachmentPolicy ใช้ตรวจ allow-list ค่าที่เก็บจึงถูกจำกัดชุดและความยาวเสมอ
            // ต้องอ่านก่อน storeAttachment() เพราะหลังย้ายไฟล์ออกจากที่พักชั่วคราวจะอ่านไม่ได้แล้ว
            $mimeType = $file->getMimeType();

            $stored[] = [
                'path' => ProtectedMedia::storeAttachment($file, $directory),
                'original_name' => $file->getClientOriginalName(),
                'file_type' => $mimeType,
            ];
        }

        return $stored;
    }

    private function storeFiles(Request $request, WorkOrder $job, string $field): void
    {
        foreach ($this->collectStoredAttachments($request, $field, 'job-attachments/'.$job->job_id) as $stored) {
            JobImage::create([
                'job_id' => $job->job_id,
                'file_path' => $stored['path'],
                'original_name' => $stored['original_name'],
                'file_type' => $stored['file_type'],
                'uploaded_by' => Auth::id(),
            ]);
        }
    }
}
