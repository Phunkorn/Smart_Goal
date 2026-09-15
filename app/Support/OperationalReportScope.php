<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ขอบเขตของรายงานปฏิบัติงานที่ตรวจสอบแล้ว — เดือนเดียว และคนเดียวหรือภาพรวมทีม
 *
 * สร้างได้จาก OperationalWorkloadReportService::scope() เท่านั้น ซึ่งอ่านค่าจาก request
 * แล้วบังคับด้วยสิทธิ์ของผู้ดู หน้า Overview, หน้า detail ทั้งสาม และ CSV ทั้งสามไฟล์
 * รับ object ตัวเดียวกันนี้ จึงอ้างอิงเดือนและคนเดียวกันเสมอ
 *
 * owner เป็น null เฉพาะ "ภาพรวมทีม" ของหัวหน้าแผนก/admin บนหน้า Overview
 * หน้า detail และ CSV เป็นรายบุคคลเสมอ จึงไม่มีทางได้ owner เป็น null
 */
final class OperationalReportScope
{
    /**
     * @param  Collection<int, User>  $owners  คนที่ผู้ดูเลือกดูรายงานได้
     * @param  array<string, string>  $monthOptions  'Y-m' => 'กันยายน 2569'
     */
    public function __construct(
        public readonly CarbonImmutable $month,
        public readonly ?User $owner,
        public readonly Collection $owners,
        public readonly ?int $departmentId,
        public readonly bool $canChooseOwner,
        public readonly bool $isOwnReport,
        public readonly array $monthOptions,
    ) {}

    public function isTeamView(): bool
    {
        return $this->owner === null;
    }

    public function monthKey(): string
    {
        return $this->month->format('Y-m');
    }

    public function startDate(): string
    {
        return $this->month->startOfMonth()->toDateString();
    }

    public function endDate(): string
    {
        return $this->month->endOfMonth()->toDateString();
    }

    public function monthLabel(): string
    {
        return self::thaiMonthLabel($this->month);
    }

    /**
     * query string ที่ลิงก์ "ดูทั้งหมด" และปุ่ม CSV ต้องพกไปด้วย
     *
     * ผู้ดูที่เลือกคนได้ (หัวหน้าแผนก/admin) ส่ง owner ไปทุกครั้ง แม้เป็นตัวเอง ไม่เช่นนั้น
     * การกลับมาที่ Overview จะกลายเป็นภาพรวมทีมแทนรายงานของคนที่กำลังดูอยู่
     * ค่าที่ส่งเป็นค่าที่ตรวจแล้ว และปลายทางตรวจซ้ำอีกรอบเสมอ ลิงก์จึงไม่ใช่ทางขยายสิทธิ์
     *
     * @return array<string, int|string>
     */
    public function query(): array
    {
        return array_filter([
            'month' => $this->monthKey(),
            'owner' => $this->canChooseOwner ? $this->owner?->id : null,
        ], fn ($value): bool => $value !== null);
    }

    /** "กันยายน 2569" — ปีพุทธศักราชตามการแสดงผลของระบบ */
    public static function thaiMonthLabel(CarbonImmutable $month): string
    {
        return ReportMonth::label($month);
    }
}
