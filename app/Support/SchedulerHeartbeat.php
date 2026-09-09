<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * สัญญาณชีพของงานตามเวลา
 *
 * ระบบนี้ตั้ง Schedule ไว้หลายรายการ (ล้างถังขยะ สร้างงานประจำ แจ้งเตือน) แต่ทั้งหมด
 * ทำงานได้ก็ต่อเมื่อเซิร์ฟเวอร์ตั้ง cron ให้เรียก `php artisan schedule:run` ทุกนาที
 * ซึ่ง deploy/README.md ของระบบนี้ไม่เคยมีขั้นตอนนั้น
 *
 * ผลคือไม่มีใครรู้ว่างานตามเวลาทำงานอยู่จริงหรือไม่ จนกว่าจะสังเกตว่าข้อมูลไม่ถูกล้าง
 * สักที คลาสนี้ให้ scheduler ประทับเวลาไว้ทุกครั้งที่ทำงาน หน้า Audit Log จึงตอบ
 * คำถามนี้ได้เองโดยไม่ต้องเข้าเซิร์ฟเวอร์ไปดู
 *
 * ใช้ cache เป็นที่เก็บ ไม่ใช่ตารางใหม่ เพราะเป็นค่าเดียวที่เขียนทับตลอดและหายได้
 * โดยไม่เสียหาย (ค่าที่หายอ่านได้ว่า "ไม่ทำงาน" ซึ่งเป็นฝั่งที่ปลอดภัยของความผิดพลาด)
 */
class SchedulerHeartbeat
{
    public const KEY = 'scheduler.last_run';

    /** เกินเท่านี้ถือว่าไม่ทำงาน เผื่อ cron ที่ตั้งห่างกว่าปกติและเวลาเครื่องคลาดเคลื่อน */
    public const STALE_MINUTES = 15;

    public static function record(): void
    {
        Cache::forever(self::KEY, CarbonImmutable::now()->toIso8601String());
    }

    /**
     * @return array{last_run: ?CarbonImmutable, is_healthy: bool}
     */
    public static function status(): array
    {
        $lastRun = self::lastRun();

        return [
            'last_run' => $lastRun,
            'is_healthy' => $lastRun !== null
                && $lastRun->greaterThanOrEqualTo(CarbonImmutable::now()->subMinutes(self::STALE_MINUTES)),
        ];
    }

    public static function lastRun(): ?CarbonImmutable
    {
        $stored = Cache::get(self::KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($stored);
        } catch (\Throwable) {
            return null;
        }
    }
}
