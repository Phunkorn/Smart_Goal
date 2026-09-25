<?php

namespace Tests\Feature;

use App\Support\ReportMonth;
use Tests\TestCase;

/**
 * ตัวเลือกเดือนย้อนหลังแบบข้ามปีของหน้าสรุปรายเดือน (บันทึกงานประจำวัน)
 */
class ReportMonthRecentOptionsTest extends TestCase
{
    public function test_recent_options_cross_the_year_boundary_newest_first_without_future_months(): void
    {
        $this->travelTo(now('Asia/Bangkok')->setDate(2026, 2, 15)->setTime(10, 0));

        $options = ReportMonth::recentOptions(4);

        $this->assertSame(['2026-02', '2026-01', '2025-12', '2025-11'], array_keys($options));
        $this->assertSame('กุมภาพันธ์ 2569', $options['2026-02']);
        $this->assertSame('ธันวาคม 2568', $options['2025-12']);
    }

    public function test_recent_options_include_an_older_viewed_month_in_order(): void
    {
        $this->travelTo(now('Asia/Bangkok')->setDate(2026, 2, 15)->setTime(10, 0));

        $options = ReportMonth::recentOptions(2, '2024-07');

        $this->assertSame(['2026-02', '2026-01', '2024-07'], array_keys($options));
        $this->assertSame(['2026-02', '2026-01'], array_keys(ReportMonth::recentOptions(2, 'not-a-month')));
        $this->assertSame(['2026-02', '2026-01'], array_keys(ReportMonth::recentOptions(2, '2026-01')));
    }
}
