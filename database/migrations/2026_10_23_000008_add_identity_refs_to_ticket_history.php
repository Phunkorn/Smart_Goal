<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ตัวตนทางประวัติของ Smart Ticket — คงอยู่หลังบัญชีถูกลบถาวร
 *
 * ปัญหา
 * ---------------------------------------------------------------
 * user_id / closed_by เป็น FK แบบ nullOnDelete (คำสั่ง trash:purge-expired ลบผู้ใช้ถาวรได้)
 * หลังลบถาวรทุกแถวของคนนั้นกลายเป็น null รายงานจึงบอกไม่ได้ว่าแถวไหนเป็นคนเดียวกัน
 * และชื่ออย่างเดียวรวมไม่ได้ เพราะคนชื่อซ้ำกันได้
 *
 * ทางแก้
 * ---------------------------------------------------------------
 * เก็บ id ของผู้ใช้ซ้ำไว้ในคอลัมน์ที่ไม่มี FK — การลบผู้ใช้จึงไม่แตะค่านี้
 *   ticket_participants.user_ref_id  ← user_id ตอนเข้าร่วม
 *   ticket_activities.user_ref_id    ← user_id ตอนเกิดเหตุการณ์ (null = ผู้แจ้งภายนอก/ระบบ)
 *   tickets.closed_by_ref_id         ← closed_by ตอนปิดงาน (ล้างพร้อม closed_by เมื่อเปิดงานใหม่)
 * ตั้งค่าตอนสร้างประวัติ: TicketParticipant/TicketActivity::booted() (และ insertOrIgnore ของ
 * TicketParticipantService) ส่วนผู้ปิดตั้ง/ล้างใน TicketWorkflowService::changesFor()
 *
 * ข้อมูลเดิม
 * ---------------------------------------------------------------
 * คัดลอกจาก user_id / closed_by ที่ยังมีค่าเท่านั้น แถวที่เป็น null ไปแล้ว (ลบถาวรก่อน migration นี้)
 * ไม่ถูกเดาค่าให้ รายงานแสดงแถวเหล่านั้นแยกกันเหมือนเดิม
 *
 * ค่าที่ใช้เป็นค่าคงที่ของ migration (ไม่อ้าง constant ของ Model) ตาม CLAUDE.md
 */
return new class extends Migration
{
    /** ตาราง => [คอลัมน์ต้นทาง (FK), คอลัมน์อ้างอิง, ชื่อ index] */
    private const REFS = [
        'ticket_participants' => ['user_id', 'user_ref_id', 'ticket_participants_user_ref_id_index'],
        'ticket_activities' => ['user_id', 'user_ref_id', 'ticket_activities_user_ref_id_index'],
        'tickets' => ['closed_by', 'closed_by_ref_id', 'tickets_closed_by_ref_id_index'],
    ];

    public function up(): void
    {
        foreach (self::REFS as $table => [$source, $ref, $index]) {
            Schema::table($table, function (Blueprint $blueprint) use ($source, $ref, $index): void {
                $blueprint->unsignedBigInteger($ref)->nullable()->after($source);
                $blueprint->index($ref, $index);
            });

            DB::table($table)
                ->whereNotNull($source)
                ->whereNull($ref)
                ->update([$ref => DB::raw($source)]);
        }
    }

    /**
     * ถอยกลับได้เฉพาะเมื่อคอลัมน์อ้างอิงไม่มีข้อมูลที่หาจากที่อื่นไม่ได้แล้ว
     * แถวที่ต้นทางเป็น null แต่อ้างอิงยังมีค่า = บัญชีถูกลบถาวรหลัง migration นี้
     * ถ้าลบคอลัมน์ทิ้ง ตัวตนของประวัติเหล่านั้นจะหายถาวร จึงหยุดแทน
     */
    public function down(): void
    {
        foreach (self::REFS as $table => [$source, $ref]) {
            if (Schema::hasColumn($table, $ref) && DB::table($table)->whereNull($source)->whereNotNull($ref)->exists()) {
                throw new RuntimeException(
                    "ไม่สามารถถอยกลับ migration นี้ได้: {$table}.{$ref} เก็บตัวตนของบัญชีที่ถูกลบถาวรแล้ว ข้อมูลนี้จะหายถ้าลบคอลัมน์"
                );
            }
        }

        foreach (self::REFS as $table => [, $ref, $index]) {
            Schema::table($table, function (Blueprint $blueprint) use ($ref, $index): void {
                $blueprint->dropIndex($index);
                $blueprint->dropColumn($ref);
            });
        }
    }
};
