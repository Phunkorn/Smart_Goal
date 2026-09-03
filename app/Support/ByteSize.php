<?php

namespace App\Support;

/**
 * แปลงค่าขนาดของ php.ini ("2G", "512M", "8192K") เป็นจำนวนไบต์ และกลับเป็นข้อความอ่านง่าย
 *
 * ค่าพวกนี้ไม่ใช่ตัวเลขล้วน ใช้ (int) ตรง ๆ จะได้ 2 จาก "2G" ซึ่งผิดไปพันล้านเท่า
 */
class ByteSize
{
    public static function fromIni(string $directive): int
    {
        return self::toBytes((string) ini_get($directive));
    }

    public static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function humanize(int $bytes): string
    {
        foreach ([['GB', 1024 ** 3], ['MB', 1024 ** 2], ['KB', 1024]] as [$unit, $size]) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' bytes';
    }
}
