<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รูปภาพที่แนบอยู่บนกระดานไอเดีย
 *
 * ทำไมต้องเป็นตารางแยก ไม่เก็บรวมใน JSON ของกระดาน
 * ---------------------------------------------------------------
 * เนื้อหากระดานถูกเขียนทับทั้งก้อนทุกครั้งที่บันทึก ถ้าข้อมูลไฟล์อยู่ในนั้นด้วย
 * ผู้ใช้ที่ยิง payload เองจะแก้ path ของไฟล์ให้ชี้ไปที่ไหนก็ได้ การแยกเป็นตาราง
 * ทำให้ path เป็นของเซิร์ฟเวอร์ล้วน ๆ ส่วนใน JSON เก็บแค่ attachmentId ที่
 * WorkspaceDocumentValidator ตรวจว่าเป็นของกระดานใบนี้จริง
 *
 * เรื่องขนาดและชนิดไฟล์
 * ---------------------------------------------------------------
 * App\Support\AttachmentPolicy ที่ใช้ร่วมกับใบงานอนุญาต docx/xlsx/zip และไฟล์
 * ขนาดถึง 1 GB ซึ่งไม่เหมาะกับรูปที่ต้องเรนเดอร์บนผืนผ้าใบ แต่ห้ามไปลดค่าที่นั่น
 * เพราะกระทบงานโครงการ การกรองให้แคบลงจึงอยู่ที่ WorkspaceDesign::IMAGE_EXTENSIONS
 * และ IMAGE_MAX_KILOBYTES โดยยังเรียกการตรวจคู่ extension กับ MIME ของเดิมอยู่
 *
 * image_width และ image_height ถูกเก็บไว้ตอนอัปโหลด เพื่อให้วางรูปบนกระดานได้
 * ตามสัดส่วนจริงโดยที่ฝั่งเบราว์เซอร์ไม่ต้องโหลดไฟล์มาวัดเองก่อน
 */
return new class extends Migration
{
    /** ความกว้างเดียวกับ work_order_list_attachments.file_type เพื่อไม่ให้ MIME ยาว ๆ ถูกตัด */
    private const FILE_TYPE_LENGTH = 255;

    public function up(): void
    {
        Schema::create('workspace_board_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_board_id')
                ->constrained('workspace_boards')
                ->cascadeOnDelete();

            $table->string('file_path', 255);
            $table->string('original_name', 255);
            $table->string('file_type', self::FILE_TYPE_LENGTH);
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('workspace_board_id', 'workspace_board_attachments_board_index');
        });
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     *
     * สำคัญเป็นพิเศษกับตารางนี้ เพราะการลบแถวทิ้งจะทำให้ไฟล์จริงใน storage
     * กลายเป็นไฟล์กำพร้าที่ไม่มีอะไรอ้างถึงและไม่มีใครลบได้อีก
     */
    public function down(): void
    {
        if (Schema::hasTable('workspace_board_attachments')
            && DB::table('workspace_board_attachments')->exists()) {
            throw new RuntimeException(
                'workspace_board_attachments ยังมีไฟล์แนบอยู่ — ต้องสำรองข้อมูลและไฟล์ก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('workspace_board_attachments');
    }
};
