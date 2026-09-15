<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Throwable;

/**
 * ช่วงเวลาของรายงานโปรเจกต์ — เดือนตามตัวเลือกเดิม หรือช่วงวันที่ที่ผู้ใช้กำหนดเอง
 *
 * แยกจาก ReportMonth เพราะรายงานปฏิบัติงานยังเป็นรายเดือนล้วน ถ้าเพิ่มช่วงกำหนดเองไว้ใน
 * ReportMonth ความหมายของ ?month= ในรายงานที่ไม่ได้ขอให้เปลี่ยนจะเปลี่ยนไปด้วย
 *
 * ช่วงกำหนดเองใช้ได้เมื่อ from และ to เป็นวันที่ที่มีจริงรูปแบบ Y-m-d, from ไม่หลัง to และยาว
 * ไม่เกิน MAX_DAYS วัน ค่าที่ไม่ผ่านข้อใดข้อหนึ่งถูกทิ้งทั้งคู่แล้วกลับไปใช้เดือนตามปกติ
 * การแก้ URL จึงไม่ได้อะไรนอกจากรายงานรายเดือน
 *
 * วันเริ่มและวันสิ้นสุดเป็นวันตามเวลากรุงเทพ ทั้งวัน (00:00:00 – 23:59:59)
 */
final class ReportPeriod
{
    public const CUSTOM = 'custom';

    /** ยาวสุด 1 ปี — กราฟแนวโน้มแสดงหนึ่งแท่งต่อเดือน เกินนี้แท่งจะแคบจนอ่านไม่ออก */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $isCustom,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::custom($request->query('from'), $request->query('to'))
            ?? self::month(ReportMonth::resolve($request->string('month')->toString()));
    }

    public static function month(CarbonImmutable $month): self
    {
        return new self($month->startOfMonth(), $month->endOfMonth(), false);
    }

    public static function custom(mixed $from, mixed $to): ?self
    {
        $start = self::parseDay($from);
        $end = self::parseDay($to);

        if ($start === null || $end === null || $start->gt($end)) {
            return null;
        }

        if ((int) round($start->diffInDays($end)) + 1 > self::MAX_DAYS) {
            return null;
        }

        return new self($start->startOfDay(), $end->endOfDay(), true);
    }

    /** ค่าของดร็อปดาวน์ช่วงเวลา: 'Y-m' ของเดือน หรือ 'custom' */
    public function selectValue(): string
    {
        return $this->isCustom ? self::CUSTOM : $this->start->format('Y-m');
    }

    /** "กันยายน 2569" หรือ "1 ก.ย. – 15 ก.ย. 2569" */
    public function label(): string
    {
        if (! $this->isCustom) {
            return ReportMonth::label($this->start);
        }

        return $this->start->year === $this->end->year
            ? self::day($this->start, false).' – '.self::day($this->end, true)
            : self::day($this->start, true).' – '.self::day($this->end, true);
    }

    /** ต่อท้ายคำได้ทันที: "งานใน".phrase() → "งานในเดือนกันยายน 2569" / "งานในช่วง 1 ก.ย. – 15 ก.ย. 2569" */
    public function phrase(): string
    {
        return ($this->isCustom ? 'ช่วง ' : 'เดือน').$this->label();
    }

    public function contains(?CarbonInterface $date): bool
    {
        return $date !== null
            && CarbonImmutable::instance($date)->setTimezone(ReportMetrics::BUSINESS_TIMEZONE)->betweenIncluded($this->start, $this->end);
    }

    /**
     * query string ของช่วงนี้ — ต่อกับลิงก์ แบ่งหน้า ฟอร์ม และ CSV
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->isCustom
            ? ['from' => $this->fromValue(), 'to' => $this->toValue()]
            : ['month' => $this->start->format('Y-m')];
    }

    public function fileKey(): string
    {
        return $this->isCustom
            ? $this->start->format('Ymd').'-'.$this->end->format('Ymd')
            : $this->start->format('Y-m');
    }

    /** ค่าเริ่มต้นของช่องวันที่ — รายเดือนได้วันแรกและวันสุดท้ายของเดือนนั้น พร้อมให้แก้ต่อทันที */
    public function fromValue(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function toValue(): string
    {
        return $this->end->format('Y-m-d');
    }

    public function trendTitle(): string
    {
        return $this->isCustom
            ? 'แนวโน้มงานรายเดือน '.$this->label()
            : 'แนวโน้มงานรายเดือน ปี '.($this->start->year + 543);
    }

    /**
     * ช่องของกราฟแนวโน้ม หนึ่งช่องต่อเดือน
     *
     * รายเดือน: มกราคม → เดือนที่เลือก เต็มเดือนทุกช่อง (พฤติกรรมเดิม)
     * ช่วงกำหนดเอง: เฉพาะเดือนที่ช่วงครอบคลุม และตัดขอบช่องแรกกับช่องสุดท้ายให้อยู่ในช่วง
     * ผลรวมของทุกช่องจึงเท่ากับ KPI ของช่วงเดียวกัน ไม่มีงานนอกช่วงหลุดเข้ามาในกราฟ
     *
     * @return list<array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function trendBuckets(): array
    {
        $first = $this->isCustom ? $this->start->startOfMonth() : $this->start->startOfYear();
        // ช่วงที่ข้ามปี ชื่อเดือนซ้ำกันได้ (ม.ค. สองครั้ง) จึงต้องมีปีกำกับ
        $spansYears = $this->isCustom && $this->start->year !== $this->end->year;
        $buckets = [];

        for ($month = $first; $month->lte($this->end); $month = $month->addMonthNoOverflow()) {
            $buckets[] = [
                'key' => $month->format('Y-m'),
                'label' => $month->locale('th')->translatedFormat('M').($spansYears ? ' '.substr((string) ($month->year + 543), -2) : ''),
                'start' => $this->isCustom ? $month->startOfMonth()->max($this->start) : $month->startOfMonth(),
                'end' => $this->isCustom ? $month->endOfMonth()->min($this->end) : $month->endOfMonth(),
            ];
        }

        return $buckets;
    }

    /** ป้ายวันที่ของช่องเลือกวัน "15 ก.ค. 2569" — ค่าที่อ่านไม่ออกได้ขีด */
    public static function dayLabel(?string $value): string
    {
        $date = self::parseDay($value);

        return $date === null ? '—' : self::day($date, true);
    }

    private static function day(CarbonImmutable $date, bool $withYear): string
    {
        return $date->locale('th')->translatedFormat('j M').($withYear ? ' '.($date->year + 543) : '');
    }

    private static function parseDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, ReportMetrics::BUSINESS_TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        // 2026-02-30 ถูก Carbon เลื่อนไปเป็นต้นเดือนถัดไปเงียบ ๆ จึงต้องเทียบกลับกับข้อความเดิม
        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
