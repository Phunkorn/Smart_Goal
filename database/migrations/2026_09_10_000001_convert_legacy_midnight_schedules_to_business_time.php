<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ย้ายกำหนดการงานเก่าให้เป็น "เวลาไทยจริง" ก่อนระบบเริ่มตัดสินความล่าช้าด้วยเวลา
 *
 * ก่อนหน้านี้หน้าจอมีแต่ช่องวันที่ ค่าที่ถูกบันทึกจึงเป็น 00:00:00 UTC ของวันนั้นเสมอ
 * ซึ่งเท่ากับ 07:00 น. เวลาไทย ตอนนั้นไม่มีปัญหาเพราะ TodayWorkspace ปัดกำหนดส่ง
 * เป็นสิ้นวันก่อนเทียบทุกครั้ง เวลาที่เก็บไว้จึงไม่เคยถูกใช้
 *
 * พอระบบเทียบเวลาจริง งานเก่าทุกใบจะกลายเป็นล่าช้าตั้งแต่ 07:00 น. ของวันครบกำหนด
 * ซึ่งไม่ใช่สิ่งที่ผู้ใช้เคยตกลงไว้ จึงย้ายค่าเดิมให้ตรงกับพฤติกรรมที่เขาเห็นมาตลอด:
 *
 *   - job_start_at : 00:00 น. เวลาไทยของวันเดิม
 *   - job_due_at   : 23:59 น. เวลาไทยของวันเดิม (เท่ากับ endOfDay ที่โค้ดเดิมใช้)
 *
 * แตะเฉพาะแถวที่เวลาเป็น 00:00:00 พอดี ซึ่งเป็นลายเซ็นของข้อมูลยุคช่องวันที่ล้วน
 * งานที่ถูกสร้างจากหน้ามอบหมายของ Admin (datetime-local อยู่แล้ว) มีเวลาจริงติดมา
 * จึงไม่เข้าเงื่อนไขและไม่ถูกแก้
 */
return new class extends Migration
{
    /** ตารางที่เก็บกำหนดการงานในรูปแบบเดียวกัน */
    private const TABLES = [
        ['work_orders', 'job_id'],
        ['work_order_list_task_requests', 'id'],
    ];

    private const LEGACY_TIME = '00:00:00';

    /**
     * เขียนเป็นค่าคงที่ในไฟล์นี้เอง ไม่อ้าง App\Support\TodayWorkspace
     *
     * migration ต้องอ่านผลลัพธ์เดิมได้เสมอแม้โค้ดแอปจะเปลี่ยนไปแล้ว การอ้างค่าคงที่
     * ของแอปทำให้ประวัติการย้ายข้อมูลเปลี่ยนความหมายตามโค้ดปัจจุบัน
     */
    private const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    /** เวลาในวัน (UTC) ของค่าที่ up() ผลิต — เวลาไทยคงที่ +7 ตลอดปี ไม่มี DST */
    private const MIGRATED_START_CLOCK = '17:00:00';

    private const MIGRATED_DUE_CLOCK = '16:59:00';

    public function up(): void
    {
        $this->each(function (string $table, string $key, object $row): void {
            $changes = [];

            if ($this->isLegacy($row->job_start_at)) {
                $changes['job_start_at'] = $this->businessTimeOfDay($row->job_start_at, '00:00:00');
            }

            if ($this->isLegacy($row->job_due_at)) {
                $changes['job_due_at'] = $this->businessTimeOfDay($row->job_due_at, '23:59:00');
            }

            if ($changes !== []) {
                DB::table($table)->where($key, $row->{$key})->update($changes);
            }
        });
    }

    /**
     * คืนค่าเฉพาะแถวที่ยังเป็นผลลัพธ์ของ up() ตรง ๆ เท่านั้น
     *
     * งานที่ผู้ใช้ตั้งเวลาเองหลังจากนี้จะไม่ตรงกับลายเซ็นด้านล่าง และต้องอยู่เหมือนเดิม
     * การบังคับให้ทุกแถวกลับไปเป็นเที่ยงคืน UTC จะทำลายเวลาที่ผู้ใช้ตั้งไว้จริง
     * ซึ่งเป็นข้อมูลที่กู้คืนไม่ได้ จึงไม่ทำ
     */
    public function down(): void
    {
        $this->each(function (string $table, string $key, object $row): void {
            $changes = [];

            if ($this->hasClock($row->job_start_at, self::MIGRATED_START_CLOCK)) {
                $changes['job_start_at'] = $this->legacyMidnight($row->job_start_at);
            }

            if ($this->hasClock($row->job_due_at, self::MIGRATED_DUE_CLOCK)) {
                $changes['job_due_at'] = $this->legacyMidnight($row->job_due_at);
            }

            if ($changes !== []) {
                DB::table($table)->where($key, $row->{$key})->update($changes);
            }
        });
    }

    private function hasClock(?string $value, string $clock): bool
    {
        return $value !== null && Carbon::parse($value)->format('H:i:s') === $clock;
    }

    /** เที่ยงคืน UTC ของวัน "ตามเวลาไทย" ซึ่งเป็นรูปแบบที่ข้อมูลเคยถูกเก็บไว้ */
    private function legacyMidnight(string $value): string
    {
        return Carbon::parse($value)->setTimezone(self::BUSINESS_TIMEZONE)->format('Y-m-d').' '.self::LEGACY_TIME;
    }

    /** เดินทุกแถวของทุกตารางแบบแบ่งชุด เพื่อไม่ดึงงานทั้งระบบขึ้นหน่วยความจำพร้อมกัน */
    private function each(callable $handle): void
    {
        foreach (self::TABLES as [$table, $key]) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            DB::table($table)->orderBy($key)->chunkById(500, function ($rows) use ($table, $key, $handle): void {
                foreach ($rows as $row) {
                    $handle($table, $key, $row);
                }
            }, $key);
        }
    }

    private function isLegacy(?string $value): bool
    {
        return $value !== null && Carbon::parse($value)->format('H:i:s') === self::LEGACY_TIME;
    }

    /** วันเดิมของค่า UTC นั้น บวกเวลาไทยที่ต้องการ แล้วแปลงกลับเป็น UTC สำหรับเก็บ */
    private function businessTimeOfDay(string $value, string $clock): string
    {
        $day = Carbon::parse($value)->format('Y-m-d');

        return Carbon::parse($day.' '.$clock, self::BUSINESS_TIMEZONE)->utc()->toDateTimeString();
    }
};
