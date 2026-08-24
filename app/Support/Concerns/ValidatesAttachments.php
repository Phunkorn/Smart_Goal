<?php

namespace App\Support\Concerns;

use App\Models\JobImage;
use App\Models\WorkOrder;
use App\Support\ProtectedMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ตรวจสอบและจัดเก็บไฟล์แนบงาน (WorkOrder) แบบรวมศูนย์จุดเดียว
 * ใช้ allow-list เดียวกันทุกจุดที่รับไฟล์แนบ (สร้างงาน / ปิดงานพร้อมแนบไฟล์ /
 * อัปโหลดไฟล์เพิ่มทีหลัง ทั้งจากบอร์ดและจากหน้า "งานของฉัน") เพื่อไม่ให้มีช่องโหว่
 * หลุดจากจุดใดจุดหนึ่งที่ลืมเช็ค
 */
trait ValidatesAttachments
{
    /**
     * นามสกุลไฟล์แนบที่อนุญาต: รูปภาพ, Word, Excel, PowerPoint เท่านั้น
     * (ห้าม pdf, csv, zip หรือไฟล์ประเภทอื่นใดทั้งสิ้น)
     */
    private const ALLOWED_ATTACHMENT_EXTENSIONS = [
        'jpg', 'jpeg', 'png',
        'doc', 'docx',
        'xls', 'xlsx',
        'ppt', 'pptx',
    ];

    /**
     * MIME type จริงที่ตรวจจากเนื้อไฟล์ (ไม่ใช่จาก header ที่ client ส่งมา)
     * ใช้คู่กับนามสกุลไฟล์เพื่อกันการปลอมนามสกุล
     */
    private const ALLOWED_ATTACHMENT_MIMES = [
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** ขนาดไฟล์แนบสูงสุดต่อไฟล์ (KB) = 10 MB */
    private const ATTACHMENT_MAX_KB = 10240;

    /**
     * ป้องกันกรณีมีคนแก้ accept ฝั่ง client หรือปลอมนามสกุล/Content-Type มา
     */
    private function assertAllowedAttachments(Request $request, string $field): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        foreach ($request->file($field) as $file) {
            if (! $file->isValid()) {
                abort(422, 'ไฟล์แนบอัปโหลดไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            $realMime = (string) $file->getMimeType();
            $sizeKb = $file->getSize() / 1024;

            if (! in_array($extension, self::ALLOWED_ATTACHMENT_EXTENSIONS, true)) {
                abort(422, 'ไม่อนุญาตไฟล์นามสกุล .'.$extension.' — แนบได้เฉพาะไฟล์รูปภาพ (JPG, PNG), Word, Excel หรือ PowerPoint เท่านั้น');
            } elseif (! in_array($realMime, self::ALLOWED_ATTACHMENT_MIMES, true)) {
                abort(422, 'ไฟล์ "'.$file->getClientOriginalName().'" มีเนื้อหาไม่ตรงกับประเภทไฟล์ที่อนุญาต');
            } elseif ($sizeKb > self::ATTACHMENT_MAX_KB) {
                abort(422, 'ไฟล์ "'.$file->getClientOriginalName().'" มีขนาดเกิน '.(self::ATTACHMENT_MAX_KB / 1024).' MB');
            }
        }
    }

    private function storeFiles(Request $request, WorkOrder $job, string $field): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        foreach ($request->file($field) as $file) {
            $path = ProtectedMedia::storeAttachment($file, 'job-attachments/'.$job->job_id);

            JobImage::create([
                'job_id' => $job->job_id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType(),
                'uploaded_by' => Auth::id(),
            ]);
        }
    }
}
