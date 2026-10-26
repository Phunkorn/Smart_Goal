<?php

namespace App\Http\Requests\Tickets\Concerns;

use App\Services\TicketPhotoStore;
use App\Support\AttachmentPolicy;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * กติกา validation ของรูปประกอบ Ticket — ใช้ร่วมกันทุก Form Request ที่รับรูป
 * (แจ้งปัญหา และบันทึกการดำเนินงาน) ตัวเลขทั้งหมดมาจาก TicketPhotoStore / AttachmentPolicy
 */
trait ValidatesTicketPhotos
{
    /** @return array<string, array<int, mixed>> */
    protected function photoRules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:'.TicketPhotoStore::MAX_PHOTOS],
            'photos.*' => [
                'file',
                'mimes:'.AttachmentPolicy::imageMimesRule(),
                'max:'.AttachmentPolicy::IMAGE_MAX_KILOBYTES,
                function (string $attribute, mixed $file, Closure $fail): void {
                    $reason = AttachmentPolicy::imageRejectionReason($file);

                    if ($reason !== null) {
                        $fail($reason);
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    protected function photoMessages(): array
    {
        return [
            'photos.array' => 'รูปภาพที่แนบไม่ถูกต้อง',
            'photos.max' => 'แนบรูปได้ไม่เกิน :max รูป',
            'photos.*.file' => 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่',
            'photos.*.mimes' => 'แนบได้เฉพาะรูป JPG หรือ PNG',
            'photos.*.max' => 'รูปแต่ละรูปต้องมีขนาดไม่เกิน '.(int) (AttachmentPolicy::IMAGE_MAX_KILOBYTES / 1024).' MB',
        ];
    }

    /** @return array<int, UploadedFile> */
    public function photos(): array
    {
        return array_values((array) $this->file('photos', []));
    }
}
