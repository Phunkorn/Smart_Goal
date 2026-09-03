<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * กติกาไฟล์แนบงานทั้งระบบ — แหล่งความจริงเดียว
 *
 * เดิมตัวเลข "5 ไฟล์" และ "10 MB" ถูกฮาร์ดโค้ดกระจายกว่า 20 จุด
 * (trait, controller 4 ตัว, Blade 7 จุด, JavaScript 3 ไฟล์ และข้อความ UI อีกหลายที่)
 * การแก้เพดานจึงต้องไล่แก้ทุกจุดและมีโอกาสหลงเหลือจุดที่ลืมจนกลายเป็นช่องโหว่
 *
 * จุดสำคัญด้านความปลอดภัย: นามสกุลกับ MIME ต้อง "จับคู่กัน" ไม่ใช่ตรวจเป็นสองรายการอิสระ
 * เพราะ .docx .xlsx .pptx คือไฟล์ ZIP โดยโครงสร้าง ถ้าเพิ่ม application/zip เข้าไป
 * ในรายการ MIME รวม ไฟล์ ZIP ที่เปลี่ยนนามสกุลเป็น .docx จะผ่านทันที
 * การจับคู่ทำให้ .zip ต้องเป็น MIME ของ zip และ .docx ต้องเป็น MIME ของ docx เท่านั้น
 */
class AttachmentPolicy
{
    /**
     * นามสกุลที่อนุญาต => MIME จริงที่ยอมรับสำหรับนามสกุลนั้น
     *
     * MIME ตรวจจากเนื้อไฟล์ด้วย finfo ไม่ใช่ Content-Type ที่ client ส่งมา
     * ZIP มีได้หลาย MIME เพราะ finfo แต่ละเวอร์ชันและแต่ละโปรแกรมบีบอัดรายงานไม่เหมือนกัน
     *
     * @var array<string, array<int, string>>
     */
    private const TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
    ];

    /**
     * จำนวนไฟล์สูงสุดต่อหนึ่งงาน
     *
     * ตั้งไว้ที่ 20 เพราะ PHP มี max_file_uploads เป็นเพดานแข็งที่ 20 ไฟล์ต่อหนึ่ง request
     * การตั้งเกินกว่านี้จะทำให้ไฟล์ส่วนเกินหายเงียบ ๆ โดยไม่มี validation error
     */
    public const MAX_FILES = 20;

    /**
     * ขนาดสูงสุดต่อไฟล์ (KB) = 1 GB
     *
     * ตั้งไว้ที่ 1 GB ไม่ใช่ 2 GB ทั้งที่ upload_max_filesize เป็น 2G เพราะ post_max_size
     * คุมขนาด "ทั้ง request" ไม่ใช่รายไฟล์ ถ้าเพดานรายไฟล์เท่ากับ post_max_size พอดี
     * การแนบไฟล์ใหญ่สุดเพียงไฟล์เดียวก็เกินแล้ว เพราะ multipart มี boundary และฟิลด์อื่นพ่วงมาด้วย
     * PHP จะทิ้งทั้ง request เงียบ ๆ โดย $_FILES ว่างเปล่าและไม่มี validation error ให้ผู้ใช้เห็น
     */
    public const MAX_KILOBYTES = 1048576;

    /** @return array<int, string> */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** ค่าใส่ใน accept="" ของ <input type="file"> เช่น ".jpg,.png,..." */
    public static function acceptAttribute(): string
    {
        return '.'.implode(',.', self::extensions());
    }

    /** ค่าใส่ใน validation rule mimes: ของ Laravel */
    public static function mimesRule(): string
    {
        return implode(',', self::extensions());
    }

    public static function maxMegabytes(): int
    {
        return (int) (self::MAX_KILOBYTES / 1024);
    }

    /**
     * ป้ายขนาดที่อ่านง่าย ใช้ GB เมื่อเพดานถึงหลักพันเมกะไบต์
     * ผู้ใช้อ่าน "1 GB" เข้าใจทันที ต่างจาก "1024 MB" ที่ต้องคิดต่อ
     */
    public static function maxSizeLabel(): string
    {
        $megabytes = self::maxMegabytes();

        if ($megabytes < 1024) {
            return $megabytes.' MB';
        }

        $gigabytes = $megabytes / 1024;

        return rtrim(rtrim(number_format($gigabytes, 1), '0'), '.').' GB';
    }

    /** ข้อความอธิบายชนิดไฟล์สำหรับผู้ใช้ ใช้ให้ตรงกันทุกหน้า */
    public static function typesLabel(): string
    {
        return 'JPG, PNG, PDF, Word, Excel, PowerPoint, ZIP';
    }

    /** ข้อความสรุปเพดานสำหรับผู้ใช้ */
    public static function limitsLabel(): string
    {
        return self::typesLabel()
            .' · ไฟล์ละไม่เกิน '.self::maxSizeLabel()
            .' · รวมไม่เกิน '.self::MAX_FILES.' ไฟล์';
    }

    /**
     * ตรวจไฟล์หนึ่งไฟล์ คืนข้อความผิดพลาดภาษาไทย หรือ null เมื่อผ่าน
     *
     * ใช้ทั้งตอนสร้างงาน ปิดงานพร้อมแนบไฟล์ และอัปโหลดเพิ่มทีหลัง
     * เพื่อไม่ให้มีทางเข้าใดหลุดการตรวจ
     */
    public static function rejectionReason(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'ไฟล์แนบอัปโหลดไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
        }

        $name = $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::TYPES)) {
            return 'ไม่อนุญาตไฟล์นามสกุล .'.$extension.' — แนบได้เฉพาะ '.self::typesLabel();
        }

        if (! in_array((string) $file->getMimeType(), self::TYPES[$extension], true)) {
            return 'ไฟล์ "'.$name.'" มีเนื้อหาไม่ตรงกับนามสกุล .'.$extension;
        }

        if ($file->getSize() / 1024 > self::MAX_KILOBYTES) {
            return 'ไฟล์ "'.$name.'" มีขนาดเกิน '.self::maxSizeLabel();
        }

        return null;
    }

    public static function tooManyMessage(int $existing): string
    {
        return 'แนบไฟล์ได้สูงสุด '.self::MAX_FILES.' ไฟล์ต่องาน (ขณะนี้มี '.$existing.' ไฟล์)';
    }
}
