<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * กติการูปภาพของโพสต์ในชุมชน — ที่เดียวทั้ง server, Blade และค่าที่ส่งให้ JavaScript
 *
 * การตรวจไฟล์ใช้ AttachmentPolicy::imageRejectionReasonFor() ชุดเดียวกับรูปของ Smart Ticket
 * (นามสกุลต้องตรงกับ MIME ที่อ่านจากเนื้อไฟล์ เปิดเป็นรูปได้จริง และขนาดภาพไม่เกินเพดาน)
 * ต่างกันแค่ชุดนามสกุลที่รับ (เพิ่ม WebP) และเพดานที่ตั้งได้จาก config/social.php
 */
final class SocialImageRules
{
    /** @var list<string> */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const TYPES_LABEL = 'JPG, PNG หรือ WebP';

    /** PHP รับไฟล์ได้ไม่เกิน max_file_uploads (ค่าตั้งต้น 20) ต่อ request — เกินนี้ไฟล์ส่วนเกินหายเงียบ */
    private const HARD_MAX_IMAGES = AttachmentPolicy::MAX_FILES;

    public static function maxImages(): int
    {
        return max(1, min(self::HARD_MAX_IMAGES, (int) config('social.images.max_per_post', 10)));
    }

    public static function maxKilobytes(): int
    {
        return max(1, (int) config('social.images.max_kilobytes', 10240));
    }

    public static function maxMegabytesLabel(): string
    {
        $megabytes = self::maxKilobytes() / 1024;

        return (floor($megabytes) == $megabytes ? (string) (int) $megabytes : number_format($megabytes, 1)).' MB';
    }

    public static function acceptAttribute(): string
    {
        return 'image/jpeg,image/png,image/webp';
    }

    public static function limitsLabel(): string
    {
        return self::TYPES_LABEL.' · สูงสุด '.self::maxImages().' รูป · รูปละไม่เกิน '.self::maxMegabytesLabel();
    }

    public static function tooManyMessage(): string
    {
        return 'แนบรูปได้ไม่เกิน '.self::maxImages().' รูปต่อโพสต์';
    }

    public static function rejectionReason(UploadedFile $file): ?string
    {
        return AttachmentPolicy::imageRejectionReasonFor($file, self::EXTENSIONS, self::maxKilobytes(), self::TYPES_LABEL);
    }
}
