<?php

namespace App\Support;

use App\Rules\AllowedAttachmentType;
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
        'txt' => ['text/plain'],
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
     * MIME ที่ fileinfo รายงานสำหรับไฟล์ Office ที่ตั้งรหัสผ่านเปิดไฟล์ (Encrypt with Password)
     *
     * ไฟล์แบบนี้ไม่ใช่ ZIP แต่เป็นกล่อง OLE2 ที่ห่อเนื้อไฟล์ที่เข้ารหัสไว้ ระบบจึงอ่านไม่ได้ว่า
     * ข้างในเป็น xlsx docx หรือ pptx ได้แค่รู้ว่าเป็นไฟล์ Office ที่ถูกเข้ารหัส
     */
    public const PASSWORD_PROTECTED_MIME = 'application/encrypted';

    /**
     * นามสกุลที่ยอมรับแบบตั้งรหัสผ่านได้ — เปิดเฉพาะ Excel ตามที่ผู้ใช้งานต้องการ
     *
     * @var array<int, string>
     */
    private const PASSWORD_PROTECTED_EXTENSIONS = ['xlsx'];

    /** 8 ไบต์แรกของไฟล์ OLE2 (Compound File Binary) */
    private const COMPOUND_FILE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * นามสกุลที่ mimes: ของ Laravel บล็อกไว้เสมอ — คงพฤติกรรมเดิมไว้
     *
     * @var array<int, string>
     */
    private const PHP_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar'];

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
        return 'JPG, PNG, TXT, PDF, Word, Excel, PowerPoint, ZIP';
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

        if (! in_array((string) $file->getMimeType(), self::TYPES[$extension], true)
            && ! self::isPasswordProtectedSpreadsheet($file)) {
            return 'ไฟล์ "'.$name.'" มีเนื้อหาไม่ตรงกับนามสกุล .'.$extension;
        }

        if ($file->getSize() / 1024 > self::MAX_KILOBYTES) {
            return 'ไฟล์ "'.$name.'" มีขนาดเกิน '.self::maxSizeLabel();
        }

        return null;
    }

    /**
     * กฎ validation ของ Laravel สำหรับไฟล์แนบหนึ่งไฟล์ — ทุก controller ใช้ชุดนี้ชุดเดียว
     *
     * ใช้ AllowedAttachmentType แทน mimes: ของ Laravel เพราะ mimes: เดานามสกุลจาก MIME
     * และ application/encrypted ไม่มีนามสกุลคู่ ไฟล์ Excel ที่ตั้งรหัสผ่านจึงตกทุกครั้ง
     *
     * @return array<int, mixed>
     */
    public static function fileRules(): array
    {
        return ['file', new AllowedAttachmentType, 'max:'.self::MAX_KILOBYTES];
    }

    /**
     * ด่านแรกของ validation — ผลเหมือน mimes: เดิมทุกกรณี ต่างกันแค่ยอมรับ Excel ที่ตั้งรหัสผ่าน
     *
     * การจับคู่นามสกุล↔MIME แบบเข้มยังเป็นหน้าที่ของ rejectionReason() ที่ทำงานถัดไป
     */
    public static function contentTypeIsAllowed(UploadedFile $file): bool
    {
        if (in_array(strtolower((string) $file->getClientOriginalExtension()), self::PHP_EXTENSIONS, true)) {
            return false;
        }

        return in_array($file->guessExtension(), self::extensions(), true)
            || self::isPasswordProtectedSpreadsheet($file);
    }

    /**
     * ไฟล์ Excel ที่ตั้งรหัสผ่านเปิดไฟล์ไว้หรือไม่
     *
     * ต้องผ่านครบสามข้อ: นามสกุล .xlsx, fileinfo ตรวจเนื้อไฟล์แล้วได้ application/encrypted
     * (libmagic ให้ค่านี้เมื่อพบ stream EncryptionInfo/EncryptedPackage ในกล่อง OLE2)
     * และ 8 ไบต์แรกเป็นลายเซ็น OLE2 จริง
     *
     * ข้อจำกัด: ไม่มีรหัสผ่านจึงยืนยันไม่ได้ว่าข้างในเป็น xlsx ไม่ใช่ docx/pptx ที่ถูกเปลี่ยนชื่อ
     * แต่ทุกกรณียังเป็นไฟล์ Office ที่ถูกเข้ารหัส ไม่ใช่ไฟล์ชนิดอื่นปลอมตัวมา
     */
    public static function isPasswordProtectedSpreadsheet(UploadedFile $file): bool
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::PASSWORD_PROTECTED_EXTENSIONS, true)
            || $file->getMimeType() !== self::PASSWORD_PROTECTED_MIME) {
            return false;
        }

        $path = $file->getRealPath();
        $handle = $path === false ? false : @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, strlen(self::COMPOUND_FILE_SIGNATURE));
        fclose($handle);

        return $head === self::COMPOUND_FILE_SIGNATURE;
    }

    /** ใช้กับค่า file_type ที่บันทึกไว้แล้ว เพื่อบอกหน้าจอว่าไฟล์นี้ตั้งรหัสผ่าน */
    public static function isPasswordProtectedType(?string $fileType): bool
    {
        return $fileType === self::PASSWORD_PROTECTED_MIME;
    }

    /**
     * นามสกุลที่ต้องต่อท้ายชื่อไฟล์บนดิสก์ หรือ null เมื่อให้ Laravel เดาเองได้
     *
     * Laravel ตั้งชื่อไฟล์จาก guessExtension() ซึ่งไม่รู้จัก application/encrypted
     * ถ้าไม่ระบุ ไฟล์ Excel ที่ตั้งรหัสผ่านจะถูกเก็บโดยไม่มีนามสกุลเลย
     */
    public static function storedExtensionOverride(UploadedFile $file): ?string
    {
        return self::isPasswordProtectedSpreadsheet($file)
            ? strtolower((string) $file->getClientOriginalExtension())
            : null;
    }

    /*
     * ---------------------------------------------------------------
     * ชุด "รูปภาพเท่านั้น" — ใช้กับรูปประกอบของ Smart Ticket
     * ---------------------------------------------------------------
     * ใช้คู่นามสกุล↔MIME ชุดเดียวกับ TYPES ด้านบน แต่จำกัดเฉพาะ JPG/PNG และเพดานเล็กกว่า
     * ไฟล์แนบงานมาก เพราะฟอร์มนี้เปิดให้บุคคลภายนอกที่ไม่ได้เข้าสู่ระบบส่งได้
     *
     * IMAGE_MAX_DIMENSION / IMAGE_MAX_PIXELS กันไฟล์ขนาดเล็กที่ประกาศขนาดภาพมหาศาล
     * (decompression bomb) — ไฟล์ PNG ไม่กี่ร้อยไบต์ประกาศ 50,000×50,000 พิกเซลได้ และ
     * เครื่องใดที่ถอดรหัสภาพนั้นภายหลังจะใช้หน่วยความจำหลายกิกะไบต์ getimagesize()
     * อ่านเฉพาะส่วนหัวของไฟล์ จึงตรวจได้โดยไม่ต้องถอดรหัสภาพทั้งภาพ
     */

    /** @var array<int, string> */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /** ขนาดสูงสุดต่อรูป (KB) = 10 MB */
    public const IMAGE_MAX_KILOBYTES = 10240;

    /** ความกว้างหรือสูงสูงสุดที่ยอมรับ (พิกเซล) — รูปกล้องมือถือโหมดปกติ (≤ 50MP) กว้างราว 8,200 */
    public const IMAGE_MAX_DIMENSION = 10000;

    /** จำนวนพิกเซลรวมสูงสุด (≈ 50 ล้านพิกเซล) */
    public const IMAGE_MAX_PIXELS = 50_000_000;

    /** ค่าใส่ใน validation rule mimes: ของ Laravel */
    public static function imageMimesRule(): string
    {
        return implode(',', self::IMAGE_EXTENSIONS);
    }

    /** ค่าใส่ใน accept="" — ใช้ MIME เพื่อให้มือถือเปิดได้ทั้งกล้องและคลังรูป */
    public static function imageAcceptAttribute(): string
    {
        return 'image/jpeg,image/png';
    }

    public static function imageLimitsLabel(): string
    {
        return 'JPG หรือ PNG · รูปละไม่เกิน '.(int) (self::IMAGE_MAX_KILOBYTES / 1024).' MB';
    }

    /**
     * รูปแบบรูปภาพที่ระบบรู้จัก: นามสกุล => [MIME ที่อ่านจากเนื้อไฟล์, ชนิดที่ getimagesize รายงาน]
     *
     * แยกจาก TYPES เพราะ TYPES คือ allow-list ของไฟล์แนบงานทั่วไป การเพิ่ม webp ที่นี่จึงไม่ทำให้
     * ไฟล์แนบงานรับ WebP ไปด้วย แต่ละผู้เรียกเลือกชุดนามสกุลของตัวเองผ่าน imageRejectionReasonFor()
     *
     * @var array<string, array{mime: string, type: int}>
     */
    private const IMAGE_FORMATS = [
        'jpg' => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG],
        'jpeg' => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG],
        'png' => ['mime' => 'image/png', 'type' => IMAGETYPE_PNG],
        'webp' => ['mime' => 'image/webp', 'type' => IMAGETYPE_WEBP],
    ];

    /** ตรวจรูปหนึ่งรูป คืนข้อความผิดพลาดภาษาไทย หรือ null เมื่อผ่าน */
    public static function imageRejectionReason(UploadedFile $file): ?string
    {
        return self::imageRejectionReasonFor($file, self::IMAGE_EXTENSIONS, self::IMAGE_MAX_KILOBYTES, 'JPG หรือ PNG');
    }

    /**
     * ตรวจรูปตามชุดนามสกุลและเพดานขนาดของผู้เรียก — กติกาความปลอดภัยชุดเดียวของรูปทั้งระบบ
     * (Smart Ticket ใช้ JPG/PNG ผ่าน imageRejectionReason() ชุมชนใช้ JPG/PNG/WebP ผ่าน SocialImageRules)
     *
     * @param  list<string>  $extensions  ต้องเป็นคีย์ของ IMAGE_FORMATS
     */
    public static function imageRejectionReasonFor(UploadedFile $file, array $extensions, int $maxKilobytes, string $typesLabel): ?string
    {
        if (! $file->isValid()) {
            return 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
        }

        $name = $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $format = self::IMAGE_FORMATS[$extension] ?? null;

        if ($format === null || ! in_array($extension, $extensions, true)) {
            return 'แนบได้เฉพาะรูป '.$typesLabel.' ("'.$name.'" ไม่ใช่รูปที่รองรับ)';
        }

        // MIME จากเนื้อไฟล์จริง ไม่ใช่ Content-Type ที่ client ส่งมา
        if ((string) $file->getMimeType() !== $format['mime']) {
            return 'ไฟล์ "'.$name.'" มีเนื้อหาไม่ตรงกับนามสกุล .'.$extension;
        }

        if ($file->getSize() / 1024 > $maxKilobytes) {
            $megabytes = $maxKilobytes / 1024;

            return 'รูป "'.$name.'" มีขนาดเกิน '.(floor($megabytes) == $megabytes ? (int) $megabytes : round($megabytes, 1)).' MB';
        }

        $info = @getimagesize((string) $file->getRealPath());
        if ($info === false || $info[2] !== $format['type']) {
            return 'ไฟล์ "'.$name.'" ไม่ใช่รูปภาพที่เปิดได้';
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1
            || $width > self::IMAGE_MAX_DIMENSION
            || $height > self::IMAGE_MAX_DIMENSION
            || $width * $height > self::IMAGE_MAX_PIXELS) {
            return 'รูป "'.$name.'" มีขนาดภาพใหญ่เกินกว่าที่ระบบรับได้';
        }

        return null;
    }

    public static function tooManyMessage(int $existing): string
    {
        return 'แนบไฟล์ได้สูงสุด '.self::MAX_FILES.' ไฟล์ต่องาน (ขณะนี้มี '.$existing.' ไฟล์)';
    }
}
