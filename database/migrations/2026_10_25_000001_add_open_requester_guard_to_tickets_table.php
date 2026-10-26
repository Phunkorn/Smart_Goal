<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผู้แจ้งภายใน (บัญชี Smart Goals) มี Ticket ที่ยังไม่ปิดได้ใบเดียว — บังคับที่ฐานข้อมูล
 *
 * open_requester_id เป็นคอลัมน์คำนวณ (virtual) = requester_id เมื่อเป็น Ticket ภายในที่ยังไม่ปิด ไม่เช่นนั้น NULL
 * unique index บนคอลัมน์นี้ทำให้มีแถว "ยังไม่ปิด" ของผู้แจ้งคนเดียวกันได้แถวเดียว (NULL ซ้ำได้)
 * ครอบคลุมสองแท็บส่งพร้อมกัน, การเปิดงานใหม่ (reopen) ใบเก่าขณะมีใบเปิดอยู่ และการเขียนข้อมูลตรง
 * TicketSubmissionService / StoreTicketRequest / TicketWorkflowService ตรวจก่อนเพื่อให้ข้อความที่อ่านเข้าใจ
 * กติกาเดียวกับ open_line_user_id ของฟอร์มสาธารณะ (2026_10_24_000001) ซึ่ง migration นี้ไม่แตะ
 *
 * ข้อมูลเดิม: Ticket สาธารณะ / ปิดแล้ว / ผู้แจ้งถูกลบถาวร (requester_id = NULL) ได้ค่า NULL ไม่ชนกัน
 * ถ้ามีผู้แจ้งที่มี Ticket ภายในเปิดอยู่มากกว่าหนึ่งใบอยู่แล้ว up() หยุดและบอกเลข Ticket — ไม่ปิดหรือแก้ข้อมูลเอง
 * ค่า 'internal' / 'closed' เป็น literal ของ migration (ไม่อ้าง constant ของ Model) ตาม CLAUDE.md
 */
return new class extends Migration
{
    private const OPEN_UNIQUE = 'tickets_open_requester_unique';

    public function up(): void
    {
        $duplicates = DB::table('tickets')
            ->where('source', 'internal')
            ->where('status', '<>', 'closed')
            ->whereNotNull('requester_id')
            ->select('requester_id')
            ->groupBy('requester_id')
            ->havingRaw('count(*) > 1')
            ->pluck('requester_id');

        if ($duplicates->isNotEmpty()) {
            $numbers = DB::table('tickets')
                ->where('source', 'internal')
                ->where('status', '<>', 'closed')
                ->whereIn('requester_id', $duplicates)
                ->orderBy('requester_id')
                ->pluck('ticket_number')
                ->implode(', ');

            throw new RuntimeException(
                'ไม่สามารถเพิ่มกติกาหนึ่งผู้แจ้งหนึ่ง Ticket ที่เปิดอยู่ได้: มีผู้แจ้งที่มี Ticket เปิดอยู่มากกว่าหนึ่งใบ ('.$numbers.') '
                .'ให้เจ้าหน้าที่ปิดใบที่ซ้ำก่อน แล้วรัน migration อีกครั้ง'
            );
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('open_requester_id')->nullable()
                ->virtualAs("case when source = 'internal' and status <> 'closed' then requester_id end")
                ->after('requester_id');
            $table->unique('open_requester_id', self::OPEN_UNIQUE);
        });
    }

    /** คอลัมน์คำนวณ — ลบได้โดยไม่มีข้อมูลหาย */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropUnique(self::OPEN_UNIQUE);
            $table->dropColumn('open_requester_id');
        });
    }
};
