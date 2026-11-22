<?php

namespace Tests\Unit;

use App\Support\SocialTime;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * เวลาแบบอ่านง่ายของชุมชน — "เมื่อวาน" ตัดสินจากวันตามเวลาไทย ไม่ใช่ 24 ชั่วโมงย้อนหลัง
 */
class SocialTimeTest extends TestCase
{
    public function test_relative_labels_follow_the_bangkok_business_day(): void
    {
        // 09:30 น. วันที่ 22 พ.ย. 2026 เวลาไทย = 02:30 UTC
        $now = Carbon::parse('2026-11-22 02:30:00', 'UTC');

        $this->assertSame('เมื่อสักครู่', SocialTime::relative($now->copy()->subSeconds(30), $now));
        $this->assertSame('5 นาทีที่แล้ว', SocialTime::relative($now->copy()->subMinutes(5), $now));
        $this->assertSame('2 ชั่วโมงที่แล้ว', SocialTime::relative($now->copy()->subHours(2), $now));

        // 23:50 น. ของวันก่อน (เวลาไทย) — ห่างไม่ถึง 10 ชั่วโมงแต่เป็น "เมื่อวาน"
        $this->assertSame('เมื่อวาน เวลา 23:50 น.', SocialTime::relative(Carbon::parse('2026-11-21 16:50:00', 'UTC'), $now));

        // 06:00 UTC ของวันที่ 21 คือ 13:00 น. ไทยวันที่ 21 ส่วน 18:00 UTC ของวันที่ 20 คือ 01:00 น. ไทยวันที่ 21
        $this->assertSame('เมื่อวาน เวลา 01:00 น.', SocialTime::relative(Carbon::parse('2026-11-20 18:00:00', 'UTC'), $now));
        $this->assertSame('3 พ.ย. เวลา 10:15 น.', SocialTime::relative(Carbon::parse('2026-11-03 03:15:00', 'UTC'), $now));
        $this->assertSame('7 ต.ค. 2568 เวลา 14:32 น.', SocialTime::relative(Carbon::parse('2025-10-07 07:32:00', 'UTC'), $now));
    }

    public function test_full_and_iso_values_are_in_bangkok_time(): void
    {
        $moment = Carbon::parse('2026-10-07 07:32:00', 'UTC');

        $this->assertSame('วันพุธที่ 7 ตุลาคม 2569 เวลา 14:32 น.', SocialTime::full($moment));
        $this->assertSame('2026-10-07T14:32:00+07:00', SocialTime::iso($moment));
    }
}
