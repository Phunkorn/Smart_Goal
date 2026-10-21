<?php

namespace App\Support;

/**
 * สถานะแรกของการ์ดกราฟในหน้ารายงาน (data-chart-state) — ตัดสินที่ server ตั้งแต่ HTML แรก
 *
 * กราฟที่ไม่มีข้อมูลต้องเริ่มเป็น "empty" ทันที ถ้ารอให้ chart-lifecycle.js เปลี่ยนเอง เบราว์เซอร์จะวาด
 * กรอบโครงกราฟสูงเต็มก่อน แล้วหดลงเป็นกล่องว่างหลังสคริปต์ทำงาน ซึ่งดันทุกอย่างด้านล่างขึ้น (layout shift)
 *
 * เกณฑ์ตรงกับ chartHasData() ใน resources/js/pages/reports/chart-lifecycle.js: มีค่ามากกว่าศูนย์อย่างน้อยหนึ่งค่า
 * กราฟที่มีข้อมูลเริ่มเป็น "loading" (โครงกราฟ) แล้วสคริปต์พาไป "ready" เมื่อวาดเฟรมแรก
 */
final class ReportChartState
{
    /**
     * @param  iterable<int|float|string|null>  ...$series
     */
    public static function initial(iterable ...$series): string
    {
        foreach ($series as $values) {
            foreach ($values as $value) {
                if (is_numeric($value) && $value > 0) {
                    return 'loading';
                }
            }
        }

        return 'empty';
    }
}
