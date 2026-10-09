<?php

namespace App\Support;

use App\Models\JobImage;
use App\Models\WorkOrder;

/**
 * รูปแบบข้อมูลไฟล์แนบของงานที่ฝั่งหน้าจอใช้ — แหล่งความจริงเดียว
 *
 * เดิม payload ชุดนี้ถูกเขียนซ้ำสามที่ (workspace-interactions.blade.php,
 * TaskAttachmentController และ TaskStatusController) ทั้งสามต้องคืนรูปเดียวกัน
 * เพราะ JavaScript นำผลลัพธ์ไปแทนรายการเดิมใน attachmentStore ตรง ๆ
 * การเพิ่มคีย์ใหม่จึงเคยต้องไล่แก้ครบทุกที่ และมีโอกาสหลงเหลือจุดที่ลืม
 * จนรายการไฟล์ที่วาดใหม่หลังอัปโหลดขาดคีย์ที่หน้าจอต้องใช้
 */
final class TaskAttachmentPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function files(WorkOrder $job, bool $canManage): array
    {
        return $job->images
            ->map(fn (JobImage $file) => self::file($file, $job->job_id, $canManage))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function file(JobImage $file, int|string $jobId, bool $canManage): array
    {
        return [
            'name' => self::name($file),
            'url' => route('media.task-attachments.show', $file),
            'download_url' => self::downloadUrl($file),
            'delete_url' => $canManage
                ? route('tasks.attachments.destroy', [$jobId, $file])
                : null,
        ];
    }

    public static function name(JobImage $file): string
    {
        return $file->original_name ?? basename((string) $file->file_path);
    }

    /**
     * ลิงก์ที่บังคับให้เบราว์เซอร์ "บันทึกไฟล์" แทนการเปิดดูในแท็บ
     *
     * ใช้ route เดียวกับการเปิดดู เพราะการตรวจสิทธิ์ต้องเป็นชุดเดียวกันเสมอ
     * ถ้าแยก endpoint ดาวน์โหลดออกไป จะมีจุดตรวจสิทธิ์ชุดที่สองให้เพี้ยนออกจากกันได้
     *
     * ชื่อไฟล์บนดิสก์ถูก hash ตอนอัปโหลด การดาวน์โหลดจึงต้องผ่านที่นี่
     * ไม่ใช่ใส่ attribute download ที่ฝั่ง client เท่านั้น ไม่อย่างนั้นไฟล์ที่ได้
     * จะชื่อเป็น hash และผู้ใช้เปิดไม่ออกเพราะไม่มีนามสกุล
     */
    public static function downloadUrl(JobImage $file): string
    {
        return route('media.task-attachments.show', [$file, 'download' => 1]);
    }
}
