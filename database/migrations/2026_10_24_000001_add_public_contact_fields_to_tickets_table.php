<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ฟอร์มแจ้งปัญหาสาธารณะ (/support): รหัสรีโมท และบัญชี LINE ของผู้แจ้ง
 *
 *   remote_access      รหัส AnyDesk / โปรแกรมรีโมท (ไม่บังคับ — ตัวเลขเท่านั้น ไม่เก็บรหัสผ่าน)
 *   line_user_id       userId จาก LINE Login (sub ของ ID token ที่ LINE ยืนยันแล้ว) ไม่เคยแสดงในหน้าเว็บ (Ticket::$hidden)
 *   line_display_name  ชื่อที่แสดงใน LINE ตอนแจ้ง ให้เจ้าหน้าที่เห็น
 *
 * หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว — บังคับที่ฐานข้อมูล
 * ---------------------------------------------------------------
 * open_line_user_id เป็นคอลัมน์คำนวณ (virtual) = line_user_id เมื่อสถานะยังไม่ปิด ไม่เช่นนั้น NULL
 * unique index บนคอลัมน์นี้ทำให้มีแถวที่ "ยังไม่ปิด" ของ userId เดียวกันได้แถวเดียว (NULL ซ้ำได้)
 * จึงครอบคลุมทุกทาง: สองแท็บส่งพร้อมกัน, เปิดงานใหม่ (reopen) ใบเก่าขณะมีใบเปิดอยู่ และการแก้ข้อมูลตรง
 * TicketSubmissionService / TicketWorkflowService ตรวจก่อนเพื่อให้ข้อความที่อ่านเข้าใจ index นี้เป็นด่านสุดท้าย
 * ค่า 'closed' เป็น literal ของ migration (ไม่อ้าง Ticket::STATUS_CLOSED) ตาม CLAUDE.md
 *
 * ข้อมูลเดิม: Ticket เดิมทั้งหมดมี line_user_id = NULL → open_line_user_id = NULL ไม่ชน unique และไม่ถูกแก้
 * ช่อง contact เดิมยังเป็นช่องเดิม (ฟอร์มสาธารณะบังคับเบอร์โทร 10 หลักที่ชั้น validation)
 */
return new class extends Migration
{
    private const INDEX = 'tickets_line_user_status_index';

    private const OPEN_UNIQUE = 'tickets_open_line_user_unique';

    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('remote_access', 100)->nullable()->after('contact');
            $table->string('line_user_id', 64)->nullable()->after('remote_access');
            $table->string('line_display_name', 150)->nullable()->after('line_user_id');
            $table->index(['line_user_id', 'status'], self::INDEX);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('open_line_user_id', 64)->nullable()
                ->virtualAs("case when status <> 'closed' then line_user_id end")
                ->after('line_display_name');
            $table->unique('open_line_user_id', self::OPEN_UNIQUE);
        });
    }

    /** หยุดถ้ามีข้อมูลในคอลัมน์ใหม่ — การลบคอลัมน์จะทำให้รหัสรีโมทและการผูก LINE ของ Ticket เหล่านั้นหายถาวร */
    public function down(): void
    {
        $hasData = DB::table('tickets')
            ->where(fn ($query) => $query->whereNotNull('remote_access')->orWhereNotNull('line_user_id')->orWhereNotNull('line_display_name'))
            ->exists();

        if ($hasData) {
            throw new RuntimeException(
                'ไม่สามารถถอยกลับ migration นี้ได้: tickets มีรหัสรีโมทหรือบัญชี LINE ของผู้แจ้งอยู่ ข้อมูลนี้จะหายถ้าลบคอลัมน์'
            );
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropUnique(self::OPEN_UNIQUE);
            $table->dropColumn('open_line_user_id');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn(['remote_access', 'line_user_id', 'line_display_name']);
        });
    }
};
