<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * กระดานไอเดีย (Workspace) — พื้นที่วาดเปล่าสำหรับระดมสมองของแต่ละแผนก
 *
 * ทำไมต้องผูกกับแผนก
 * ---------------------------------------------------------------
 * กติกาทางธุรกิจคือ "คนในแผนกเดียวกันแก้กระดานของแผนกตัวเองได้ทุกคน" ไม่ใช่
 * "เฉพาะคนสร้าง" การผูก department_id ไว้ที่กระดานจึงเป็นตัวตัดสินสิทธิ์แก้ไข
 * ตรง ๆ ไม่ต้องมีตารางสมาชิกกระดานแยกอีกชั้น และไม่ต้อง join ไปที่ users
 * ทุกครั้งที่ตรวจสิทธิ์
 *
 * restrictOnDelete บน department_id ตั้งใจให้เป็น restrict ไม่ใช่ cascade
 * เพราะกระดานเป็นงานที่คนทั้งแผนกช่วยกันทำ การลบแผนกแล้วกระดานหายตามไปเงียบ ๆ
 * เป็นการทำลายข้อมูลโดยไม่ตั้งใจ DepartmentController::destroy() มีการ์ดอยู่แล้ว
 * ว่าห้ามลบแผนกที่ยังมีข้อมูลผูกอยู่ ระดับฐานข้อมูลจึงย้ำกติกาเดียวกัน
 *
 * เรื่อง visibility
 * ---------------------------------------------------------------
 * 'organization' = แผนกอื่นเปิดดูได้แต่แก้ไม่ได้ (ค่าเริ่มต้น เพราะจุดประสงค์ของ
 * ฟีเจอร์คือการแชร์ไอเดียข้ามแผนก) ส่วน 'department' = เห็นเฉพาะคนในแผนกกับ admin
 * ธงนี้มีผลกับ "การอ่าน" เท่านั้น ไม่เคยเพิ่มสิทธิ์แก้ไขให้ใคร
 *
 * ค่าเป็น string literal ตามกฎ CLAUDE.md ห้าม import ค่าคงที่จาก Model/Support
 * เข้ามาใน migration เพราะ migration ต้องรันได้แม้โค้ดจะเปลี่ยนไปแล้วในอนาคต
 *
 * element_count เก็บซ้ำไว้ที่นี่แทนการนับจาก JSON ของ document เพราะหน้ารายการ
 * ต้องแสดงจำนวนชิ้นงานของทุกกระดานพร้อมกัน การอ่าน JSON ทั้งก้อนมานับจะดึงข้อมูล
 * ระดับเมกะไบต์มาเพื่อแสดงตัวเลขตัวเดียว ค่านี้เขียนโดย
 * App\Services\WorkspaceBoardDocumentService เท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();

            // กระดานอยู่ต่อได้แม้บัญชีคนสร้างถูกลบ เพราะเป็นงานของแผนกไม่ใช่ของบุคคล
            // เมื่อเป็น NULL สิทธิ์ "คนสร้าง" จะตกไปที่หัวหน้าแผนกกับ admin โดยอัตโนมัติ
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 160);
            $table->string('visibility', 20)->default('organization');
            $table->unsignedInteger('element_count')->default(0);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'visibility'], 'workspace_boards_department_visibility_index');
            $table->index(['department_id', 'last_edited_at'], 'workspace_boards_department_recent_index');
            $table->index('created_by', 'workspace_boards_creator_index');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('workspace_boards') && DB::table('workspace_boards')->exists()) {
            throw new RuntimeException(
                'workspace_boards ยังมีกระดานอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('workspace_boards');
    }
};
