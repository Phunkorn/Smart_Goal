<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Support\AttachmentPolicy;
use App\Support\ImageMetadata;
use App\Support\ProtectedMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * รูปประกอบของ Smart Ticket — ตรวจ เก็บ และล้าง ที่เดียว
 * ใช้ทั้งตอนแจ้งปัญหา (TicketSubmissionService) และตอนบันทึกการดำเนินงาน (TicketNoteService)
 *
 * วิธีใช้คู่กับทรานแซกชันของผู้เรียก:
 *
 *     $paths = [];
 *     try {
 *         DB::transaction(function () use (&$paths) { ...; $paths = $photos->store(...); });
 *     } catch (Throwable $e) {
 *         $photos->discard($paths);
 *         throw $e;
 *     }
 *
 * store() ล้างไฟล์ของตัวเองถ้าล้มกลางลูป ส่วน discard() ล้างไฟล์ที่เก็บครบแล้วแต่
 * ทรานแซกชันของผู้เรียกล้มภายหลัง จึงไม่มีไฟล์ค้างที่ไม่มีแถวอ้างถึงทั้งสองกรณี
 */
class TicketPhotoStore
{
    public const MAX_PHOTOS = 3;

    public const DIRECTORY = 'ticket-attachments';

    /**
     * ตรวจซ้ำแม้ Form Request ตรวจแล้ว — กติกาความปลอดภัยของไฟล์ต้องไม่ขึ้นกับผู้เรียก
     *
     * @param  array<int, mixed>  $photos
     * @return array<int, UploadedFile>
     */
    public function validated(array $photos): array
    {
        $photos = array_values(array_filter($photos, fn ($photo) => $photo instanceof UploadedFile));

        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'แนบรูปได้ไม่เกิน '.self::MAX_PHOTOS.' รูป']);
        }

        foreach ($photos as $photo) {
            $reason = AttachmentPolicy::imageRejectionReason($photo);

            if ($reason !== null) {
                throw ValidationException::withMessages(['photos' => $reason]);
            }
        }

        return $photos;
    }

    /**
     * เก็บรูปลง private storage และสร้างแถว — เรียกภายในทรานแซกชันของผู้เรียก
     *
     * @param  array<int, UploadedFile>  $photos  ผ่าน validated() แล้ว
     * @return array<int, string> path ที่เก็บไว้ ให้ผู้เรียกส่งต่อ discard() เมื่อทรานแซกชันล้ม
     */
    public function store(Ticket $ticket, array $photos, ?User $uploader, ?int $activityId = null): array
    {
        $paths = [];

        try {
            foreach ($photos as $photo) {
                // อ่าน MIME/ขนาดก่อนย้ายไฟล์ออกจากที่พักชั่วคราว (ดู ValidatesAttachments)
                $mimeType = (string) $photo->getMimeType();
                $temporary = (string) $photo->getRealPath();

                // ลบ GPS / รุ่นเครื่อง / เวลาถ่าย ก่อนเก็บ — คง Orientation ไว้ รูปจึงไม่หมุนผิด
                if (! ImageMetadata::strip($temporary, $mimeType)) {
                    Log::warning('Smart Ticket photo metadata could not be removed; stored as uploaded', [
                        'ticket_id' => $ticket->id,
                        'mime' => $mimeType,
                    ]);
                }

                clearstatcache(true, $temporary);
                $size = (int) filesize($temporary);
                $path = ProtectedMedia::storeAttachment($photo, self::DIRECTORY.'/'.$ticket->id);
                $paths[] = $path;

                TicketAttachment::query()->forceCreate([
                    'ticket_id' => $ticket->id,
                    'ticket_activity_id' => $activityId,
                    'file_path' => $path,
                    'original_name' => mb_substr($photo->getClientOriginalName(), 0, 255),
                    'file_type' => $mimeType,
                    'file_size' => $size,
                    'uploaded_by' => $uploader?->id,
                ]);
            }
        } catch (Throwable $exception) {
            $this->discard($paths);

            throw $exception;
        }

        return $paths;
    }

    /** @param  array<int, string>  $paths */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            ProtectedMedia::deleteAttachment($path);
        }
    }
}
