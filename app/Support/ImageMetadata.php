<?php

namespace App\Support;

/**
 * ลบ metadata ส่วนตัวออกจากรูป JPEG / PNG แบบไม่ถอดรหัสภาพ (lossless)
 *
 * รูปจากมือถือมี EXIF ที่บอกพิกัด GPS รุ่นเครื่อง และเวลาถ่าย รูปที่ผู้แจ้งปัญหาส่งมาถูกเปิดดูโดย
 * ทีม IT หลายคน จึงไม่ควรพกข้อมูลเหล่านี้ไปด้วย
 *
 * ทำไมไม่ใช้ GD เข้ารหัสใหม่: เสียคุณภาพ ใช้หน่วยความจำตามจำนวนพิกเซล (รูป 50 ล้านพิกเซล ≈ 200 MB)
 * และไม่แน่ว่า production มี GD ที่นี่แค่ตัด segment / chunk ของไฟล์ พิกเซลไม่ถูกแตะ
 *
 * JPEG: ตัด APP1 (EXIF, XMP), APP13 (IPTC) และ COM — คง APP0 (JFIF), APP2 (ICC สี), APP14 (Adobe)
 *       แล้วใส่ EXIF ขนาดเล็กที่มีแค่ Orientation กลับไป ถ้าเดิมไม่ใช่ 1 รูปจึงไม่หมุนผิดหลังลบ
 * PNG:  ตัด eXIf, tEXt, zTXt, iTXt, tIME — chunk ที่เหลือคงไว้ทั้ง CRC
 * WebP: ตัด chunk EXIF และ XMP แล้วล้างธงใน VP8X ใส่ EXIF ที่มีแค่ Orientation กลับเมื่อไม่ใช่ 1
 *       (รูปของชุมชน — ทุกคนในองค์กรเปิดดูได้)
 *
 * อ่านไฟล์ไม่ออกตามโครงสร้าง → ไม่แก้ไฟล์และคืน false (ไฟล์ผ่าน getimagesize มาแล้ว จึงยังแสดงได้)
 */
final class ImageMetadata
{
    private const JPEG_DROP = [0xE1, 0xED, 0xFE];

    private const PNG_DROP = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    /** ธงใน VP8X: บิต 3 = มี EXIF, บิต 2 = มี XMP */
    private const WEBP_EXIF_FLAG = 0x08;

    private const WEBP_XMP_FLAG = 0x04;

    /** @return bool true เมื่อเขียนไฟล์ใหม่แล้ว (หรือไม่มีอะไรต้องลบ) */
    public static function strip(string $path, string $mimeType): bool
    {
        $data = @file_get_contents($path);
        if (! is_string($data)) {
            return false;
        }

        $clean = match ($mimeType) {
            'image/jpeg' => self::stripJpeg($data),
            'image/png' => self::stripPng($data),
            'image/webp' => self::stripWebp($data),
            default => null,
        };

        if ($clean === null) {
            return false;
        }

        if ($clean === $data) {
            return true;
        }

        return @file_put_contents($path, $clean, LOCK_EX) === strlen($clean);
    }

    private static function stripJpeg(string $data): ?string
    {
        $length = strlen($data);
        if ($length < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $kept = '';
        $orientation = 1;
        $offset = 2;

        while ($offset + 4 <= $length) {
            if ($data[$offset] !== "\xFF") {
                return null;
            }

            $marker = ord($data[$offset + 1]);

            // ไบต์เติม 0xFF ระหว่าง segment
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            // SOS: ต่อจากนี้คือข้อมูลภาพ — คัดลอกทั้งหมดตามเดิม
            if ($marker === 0xDA) {
                // JFIF กำหนดให้ APP0 มาก่อน — EXIF ใหม่จึงต่อท้าย APP0 ถ้ามี
                $app0 = str_starts_with($kept, "\xFF\xE0") ? 2 + unpack('n', substr($kept, 2, 2))[1] : 0;

                return "\xFF\xD8".substr($kept, 0, $app0).self::orientationSegment($orientation).substr($kept, $app0).substr($data, $offset);
            }

            // marker ที่ไม่มีความยาว (RSTn, TEM) ไม่ควรอยู่ก่อน SOS
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01 || $marker === 0xD8 || $marker === 0xD9) {
                return null;
            }

            $segmentLength = unpack('n', substr($data, $offset + 2, 2))[1];
            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return null;
            }

            $segment = substr($data, $offset, 2 + $segmentLength);

            if ($marker === 0xE1 && str_starts_with(substr($segment, 4), "Exif\0\0")) {
                $orientation = self::readOrientation(substr($segment, 10)) ?? $orientation;
            }

            if (! in_array($marker, self::JPEG_DROP, true)) {
                $kept .= $segment;
            }

            $offset += 2 + $segmentLength;
        }

        return null;
    }

    /** ค่า Orientation (1–8) จาก IFD0 ของข้อมูล TIFF ใน EXIF — อ่านไม่ได้ = null */
    private static function readOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $order = substr($tiff, 0, 2);
        $short = match ($order) {
            'II' => 'v',
            'MM' => 'n',
            default => null,
        };
        if ($short === null) {
            return null;
        }
        $long = $order === 'II' ? 'V' : 'N';

        $ifd = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }

        $entries = unpack($short, substr($tiff, $ifd, 2))[1];
        for ($index = 0; $index < $entries; $index++) {
            $entry = $ifd + 2 + $index * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }

            if (unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    /** EXIF ที่มีแค่ Orientation (big-endian) — 1 = ปกติ ไม่ต้องใส่ */
    private static function orientationSegment(int $orientation): string
    {
        if ($orientation === 1) {
            return '';
        }

        $payload = "Exif\0\0".self::orientationTiff($orientation);

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    /** ข้อมูล TIFF ที่มีแค่ Orientation (big-endian) — ใช้ทั้ง APP1 ของ JPEG และ chunk EXIF ของ WebP */
    private static function orientationTiff(int $orientation): string
    {
        return 'MM'.pack('n', 0x2A).pack('N', 8)       // header, IFD0 อยู่ที่ไบต์ 8
            .pack('n', 1)                                 // หนึ่ง entry
            .pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0) // Orientation, SHORT, 1 ค่า
            .pack('N', 0);                                // ไม่มี IFD ถัดไป
    }

    /**
     * WebP (RIFF): ไฟล์แบบธรรมดา (VP8 / VP8L) ไม่มีที่ให้ใส่ metadata จึงคืนไฟล์เดิม
     * ไฟล์แบบขยาย (VP8X) ตัด chunk EXIF / XMP แล้วล้างธงที่บอกว่ามี chunk เหล่านั้น
     */
    private static function stripWebp(string $data): ?string
    {
        $length = strlen($data);
        if ($length < 20 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') {
            return null;
        }

        $chunks = [];
        $orientation = 1;
        $offset = 12;

        while ($offset + 8 <= $length) {
            $type = substr($data, $offset, 4);
            $size = unpack('V', substr($data, $offset + 4, 4))[1];
            $total = 8 + $size + ($size % 2);

            if ($offset + 8 + $size > $length) {
                return null;
            }

            if ($type === 'EXIF') {
                $exif = substr($data, $offset + 8, $size);
                $orientation = self::readOrientation(str_starts_with($exif, "Exif\0\0") ? substr($exif, 6) : $exif) ?? $orientation;
            } elseif ($type !== 'XMP ') {
                $chunks[] = substr($data, $offset, min($total, $length - $offset));
            }

            $offset += $total;
        }

        if ($chunks === []) {
            return null;
        }

        if (str_starts_with($chunks[0], 'VP8X') && strlen($chunks[0]) >= 9) {
            $flags = (ord($chunks[0][8]) & ~(self::WEBP_EXIF_FLAG | self::WEBP_XMP_FLAG)) | ($orientation !== 1 ? self::WEBP_EXIF_FLAG : 0);
            $chunks[0][8] = chr($flags);

            if ($orientation !== 1) {
                $tiff = self::orientationTiff($orientation);
                $chunks[] = 'EXIF'.pack('V', strlen($tiff)).$tiff.(strlen($tiff) % 2 ? "\0" : '');
            }
        }

        $body = 'WEBP'.implode('', $chunks);

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    private static function stripPng(string $data): ?string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (! str_starts_with($data, $signature)) {
            return null;
        }

        $length = strlen($data);
        $offset = 8;
        $clean = $signature;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $total = 12 + $chunkLength;

            if ($offset + $total > $length) {
                return null;
            }

            if (! in_array($type, self::PNG_DROP, true)) {
                $clean .= substr($data, $offset, $total);
            }

            $offset += $total;

            if ($type === 'IEND') {
                return $clean;
            }
        }

        return null;
    }
}
