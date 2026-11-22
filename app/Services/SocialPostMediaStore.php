<?php

namespace App\Services;

use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Support\ImageMetadata;
use App\Support\ProtectedMedia;
use App\Support\SocialImageRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * รูปภาพของโพสต์ในชุมชน — ตรวจ เก็บ และล้าง ที่เดียว (รูปแบบเดียวกับ TicketPhotoStore)
 *
 * store() ถูกเรียกภายในทรานแซกชันของ SocialPostService เพราะโฟลเดอร์ใช้ id ของโพสต์
 * store() ล้างไฟล์ของตัวเองถ้าล้มกลางลูป ส่วน discard() ล้างไฟล์ที่เก็บครบแล้ว
 * แต่ทรานแซกชันของผู้เรียกล้มภายหลัง จึงไม่มีไฟล์ค้างที่ไม่มีแถวอ้างถึง
 */
class SocialPostMediaStore
{
    /**
     * ตรวจซ้ำแม้ Form Request ตรวจแล้ว — กติกาความปลอดภัยของไฟล์ต้องไม่ขึ้นกับผู้เรียก
     *
     * @param  array<int, mixed>  $files
     * @return list<UploadedFile>
     */
    public function validated(array $files, int $alreadyAttached = 0): array
    {
        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));

        if ($alreadyAttached + count($files) > SocialImageRules::maxImages()) {
            throw ValidationException::withMessages(['images' => SocialImageRules::tooManyMessage()]);
        }

        foreach ($files as $file) {
            $reason = SocialImageRules::rejectionReason($file);

            if ($reason !== null) {
                throw ValidationException::withMessages(['images' => $reason]);
            }
        }

        return $files;
    }

    /**
     * @param  list<UploadedFile>  $files  ผ่าน validated() แล้ว
     * @return list<string> path ที่เก็บไว้ ให้ผู้เรียกส่งต่อ discard() เมื่อทรานแซกชันล้ม
     */
    public function store(SocialPost $post, array $files, int $startOrder = 0): array
    {
        $paths = [];

        try {
            foreach ($files as $offset => $file) {
                // อ่าน MIME ก่อนย้ายไฟล์ออกจากที่พักชั่วคราว — MIME จากเนื้อไฟล์ ไม่ใช่จาก client
                $mimeType = (string) $file->getMimeType();
                $temporary = (string) $file->getRealPath();

                // ลบ GPS / รุ่นเครื่อง / เวลาถ่าย ก่อนเก็บ — รูปในฟีดทุกคนในองค์กรเปิดดูได้
                if (! ImageMetadata::strip($temporary, $mimeType)) {
                    Log::warning('Social post image metadata could not be removed; stored as uploaded', [
                        'social_post_id' => $post->id,
                        'mime' => $mimeType,
                    ]);
                }

                clearstatcache(true, $temporary);
                $dimensions = @getimagesize($temporary) ?: [null, null];
                $size = (int) filesize($temporary);

                $path = ProtectedMedia::storeAttachment($file, SocialPostMedia::directoryFor($post));
                $paths[] = $path;

                (new SocialPostMedia)->forceFill([
                    'social_post_id' => $post->id,
                    'disk' => ProtectedMedia::ATTACHMENT_DISK,
                    'file_path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $mimeType,
                    'file_size' => $size,
                    'width' => $dimensions[0] ? (int) $dimensions[0] : null,
                    'height' => $dimensions[1] ? (int) $dimensions[1] : null,
                    'sort_order' => $startOrder + $offset,
                ])->save();
            }
        } catch (Throwable $exception) {
            $this->discard($paths);

            throw $exception;
        }

        return $paths;
    }

    /** @param  list<string>  $paths */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            ProtectedMedia::deleteAttachment($path);
        }
    }
}
