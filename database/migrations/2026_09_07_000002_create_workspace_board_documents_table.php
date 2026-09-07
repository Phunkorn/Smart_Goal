<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เนื้อหาของกระดานไอเดีย — เก็บเป็น JSON document ก้อนเดียวต่อหนึ่งกระดาน
 *
 * ทำไมไม่แตกเป็นตาราง element ต่อแถว
 * ---------------------------------------------------------------
 * ระบบนี้ตั้งใจไม่ทำ realtime การกันเขียนทับใช้การตรวจเวอร์ชันทั้งกระดาน
 * (optimistic concurrency) ไม่ใช่การ merge ทีละชิ้น เมื่อไม่มีการ merge
 * การแตกเป็นแถวจะกลายเป็น insert/update/delete จำนวน N ครั้งต่อการบันทึกอัตโนมัติ
 * หนึ่งครั้ง โดยไม่ได้อะไรกลับมา และแต่ละชิ้นก็ยังต้องมีคอลัมน์ JSON เก็บ property
 * เฉพาะประเภทอยู่ดี (points ของเส้น, text ของโน้ต, fill ของรูปทรง)
 *
 * ทำไมต้องแยกตารางจาก workspace_boards
 * ---------------------------------------------------------------
 * หน้ารายการกระดานต้อง SELECT ข้อมูลหัวเรื่องของหลายกระดานพร้อมกัน ถ้า document
 * อยู่ในตารางเดียวกัน การอ่านรายการจะลากเนื้อหาระดับเมกะไบต์ตามมาด้วยทุกครั้ง
 * และการบันทึกอัตโนมัติก็จะเขียนทับแถวที่กว้างโดยไม่จำเป็น
 *
 * กฎเหล็ก MySQL/SQLite parity
 * ---------------------------------------------------------------
 * ห้ามเขียน SQL ที่เจาะเข้าไปในคอลัมน์ document (ตัวดำเนินการ ->, JSON_CONTAINS,
 * การ orderBy คีย์ภายใน JSON) เพราะฐานข้อมูลทดสอบเป็น SQLite ที่เก็บเป็น TEXT
 * ส่วน production เป็น MySQL ที่เก็บเป็น JSON จริง ไวยากรณ์และพฤติกรรมต่างกัน
 * อะไรที่ต้องกรองหรือเรียงต้องยกขึ้นมาเป็นคอลัมน์จริงใน workspace_boards
 *
 * MySQL ไม่อนุญาตให้คอลัมน์ json มีค่า DEFAULT ค่าเริ่มต้น
 * {"schema":1,"elements":[]} จึงถูกเขียนโดย App\Services\WorkspaceBoardService
 * ตอนสร้างกระดาน ไม่ใช่โดย migration นี้
 *
 * content_version อยู่ที่ตารางนี้ไม่ใช่ที่ workspace_boards เพื่อให้คำสั่ง
 * UPDATE ... WHERE content_version = ? ที่ใช้กันการเขียนทับ แตะแถวเดียวพอดี
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_board_documents', function (Blueprint $table): void {
            // หนึ่งกระดานมี document เดียวเสมอ จึงใช้ FK เป็น primary key ไปเลย
            // ไม่ต้องมี id ของตัวเอง และได้ unique constraint ฟรีจาก primary key
            $table->foreignId('workspace_board_id')
                ->primary()
                ->constrained('workspace_boards')
                ->cascadeOnDelete();

            $table->json('document');
            $table->unsignedBigInteger('content_version')->default(1);
            $table->unsignedInteger('byte_size')->default(0);
            $table->timestamps();
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     */
    public function down(): void
    {
        if (Schema::hasTable('workspace_board_documents') && DB::table('workspace_board_documents')->exists()) {
            throw new RuntimeException(
                'workspace_board_documents ยังมีเนื้อหากระดานอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('workspace_board_documents');
    }
};
