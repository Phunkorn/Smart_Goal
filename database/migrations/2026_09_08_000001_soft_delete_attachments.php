<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ไฟล์แนบทุกชนิดลบแบบชั่วคราวได้
 *
 * ระบบสัญญากับผู้ใช้ว่าข้อมูลที่ถูกลบจะเก็บไว้ 30 วันก่อนลบถาวร แต่ไฟล์แนบเป็น
 * ข้อยกเว้นที่ไม่มีใครบอก คือถูกลบออกจากดิสก์ทันทีที่กดลบ ถังขยะจึงไม่เคยกู้ไฟล์
 * กลับมาได้เลยแม้แต่ครั้งเดียว
 *
 * คอลัมน์ deleted_at ทำให้แถวยังอยู่ให้กู้คืนได้ ส่วนตัวไฟล์บนดิสก์ถูกเก็บไว้โดย
 * App\Models\Concerns\KeepsFileUntilPurged ซึ่งลบไฟล์เฉพาะตอน forceDelete()
 *
 * เป็น migration แบบเพิ่มคอลัมน์ล้วน ไม่แตะข้อมูลเดิม แถวที่มีอยู่ได้ deleted_at
 * เป็น NULL ซึ่งแปลว่า "ยังไม่ถูกลบ" ตรงกับความจริงพอดี
 */
return new class extends Migration
{
    /**
     * ตารางไฟล์แนบทั้งหมดของระบบ
     *
     * เมื่อเพิ่มโดเมนที่มีไฟล์แนบใหม่ ต้องเพิ่มตารางที่นี่ด้วย ไม่เช่นนั้นไฟล์ของ
     * โดเมนนั้นจะกลับไปหายถาวรทันทีที่กดลบ โดยไม่มีอะไรฟ้อง
     */
    private const TABLES = [
        'job_images',
        'work_order_list_attachments',
        'work_log_attachments',
        'workspace_board_attachments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     *
     * แถวที่ deleted_at ไม่เป็น NULL คือไฟล์ที่อยู่ในถังขยะรอผู้ใช้ตัดสินใจ
     * การถอดคอลัมน์ทิ้งจะทำให้ไฟล์เหล่านั้นกลับมาปรากฏเป็นไฟล์ปกติทั้งหมด
     * ซึ่งอันตรายกว่าการทำ migration ล้มเสียอีก
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            if (DB::table($table)->whereNotNull('deleted_at')->exists()) {
                throw new RuntimeException(
                    $table.' ยังมีไฟล์แนบที่อยู่ในถังขยะ — ต้องกู้คืนหรือลบถาวรให้หมดก่อนย้อน migration นี้'
                );
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
