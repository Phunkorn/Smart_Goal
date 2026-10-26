<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ตาราง Smart Ticket ถูก migrate แล้วหรือยัง
 *
 * ทำไมต้องมี
 * ---------------------------------------------------------------
 * layout ของทุกหน้าถามสิทธิ์ agent และนับ Ticket ที่รอรับงาน ถ้าโค้ดใหม่ถูกรันกับฐานที่ยัง
 * ไม่ได้ migrate ทุกหน้าของ Smart Goals จะ error เพราะไม่มีตาราง ticket_agents
 * ส่วนที่แตะตาราง Ticket จากทุกหน้าจึงต้องถามที่นี่ก่อน (ไม่ใช่จับ exception ของฐานข้อมูลทิ้ง)
 *
 * ต้นทุน
 * ---------------------------------------------------------------
 * - จำผลภายใน request (bind แบบ scoped) ถามกี่ครั้งก็ตรวจครั้งเดียว
 * - จำ "ติดตั้งแล้ว" ไว้ใน cache store ของ config('tickets.schema_cache_store') ค่าเริ่มต้นคือ file
 *   หลังติดตั้งจึงไม่มีคิวรีฐานข้อมูลเพิ่มใน request ใดเลย
 * - ไม่จำผลลบ — ช่วงก่อน migrate ตรวจทุก request แต่เป็นช่วงสั้น และทันทีที่ migrate เสร็จก็เห็นผล
 * - ค่าที่จำไว้ถูกล้างเองเมื่อ migrate/rollback จบ (AppServiceProvider ฟัง MigrationsEnded)
 */
class TicketSchemaStatus
{
    /** ทุกอย่างที่ migration ของ Smart Ticket สร้าง — ตรวจครบเพื่อไม่ตัดสินจากการติดตั้งครึ่งทาง */
    private const TABLES = ['ticket_locations', 'tickets', 'ticket_participants', 'ticket_activities', 'ticket_attachments', 'ticket_agents'];

    /**
     * คอลัมน์ที่ migration ภายหลังเพิ่มเข้าตารางเดิม — โค้ดเขียนลงคอลัมน์เหล่านี้ทุกครั้งที่สร้างประวัติ
     * ถ้าขึ้นโค้ดก่อน migrate จะต้องตอบว่า "ยังไม่ติดตั้ง" (503) ไม่ใช่ปล่อยให้บันทึกล้มเป็น 500
     */
    private const COLUMNS = [
        'departments' => 'ticket_responsible',
        'ticket_participants' => 'user_ref_id',
        'ticket_activities' => 'user_ref_id',
        'tickets' => 'closed_by_ref_id',
    ];

    /**
     * เพิ่มเลขเมื่อ TABLES/COLUMNS เปลี่ยน — ค่า "ติดตั้งแล้ว" ที่ cache ไว้จากโค้ดรุ่นก่อน (ซึ่งตรวจน้อยกว่า)
     * จะไม่ถูกเชื่อ และถูกตรวจใหม่กับรายการปัจจุบัน
     */
    private const SCHEMA_VERSION = 2;

    private ?bool $installed = null;

    public function isInstalled(): bool
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        if ($this->store()->get($this->cacheKey()) === true) {
            return $this->installed = true;
        }

        $installed = collect(self::TABLES)->every(fn (string $table) => Schema::hasTable($table))
            && collect(self::COLUMNS)->every(fn (string $column, string $table) => Schema::hasColumn($table, $column));

        if ($installed) {
            $this->store()->forever($this->cacheKey(), true);
        }

        return $this->installed = $installed;
    }

    /** ลืมผลที่จำไว้ — เรียกหลัง migrate/rollback */
    public function forget(): void
    {
        $this->installed = null;
        $this->store()->forget($this->cacheKey());
    }

    private function store(): Repository
    {
        return Cache::store(config('tickets.schema_cache_store'));
    }

    /** แยกตามฐานข้อมูล เผื่อหลายสภาพแวดล้อมใช้โค้ดชุดเดียวกัน */
    private function cacheKey(): string
    {
        $connection = config('database.default');

        return 'smart-ticket:installed:v'.self::SCHEMA_VERSION.':'.$connection.':'.config("database.connections.{$connection}.database");
    }
}
