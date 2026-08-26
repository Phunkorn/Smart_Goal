<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * job_images.file_type ถูกสร้างไว้เป็น varchar(50) ตั้งแต่ migration แรก
 * แต่แอปเก็บ MIME type ที่ตรวจจากเนื้อไฟล์ ซึ่งของ Office 2007+ ยาว 65-73 ตัวอักษร
 * ทุกการแนบไฟล์ .docx .xlsx .pptx จึงล้มด้วย SQLSTATE[22001] บน MySQL
 *
 * ตารางพี่น้อง work_order_list_attachments.file_type เป็น varchar(255) อยู่แล้วและไม่มีปัญหา
 * migration นี้จึงเพียงขยายให้เท่ากัน เป็นการเพิ่มความจุ ไม่มีการแปลงหรือลบข้อมูลเดิม
 *
 * ความกว้างประกาศเป็นตัวเลขตรง ๆ โดยเจตนา migration ต้อง immutable
 * และต้องไม่ผูกกับค่าคงที่ใน Model ที่อาจถูกแก้ในอนาคตจนทำให้ประวัติการ migrate เพี้ยน
 */
return new class extends Migration
{
    private const WIDENED_LENGTH = 255;

    private const ORIGINAL_LENGTH = 50;

    public function up(): void
    {
        Schema::table('job_images', function (Blueprint $table) {
            $table->string('file_type', self::WIDENED_LENGTH)->nullable()->change();
        });
    }

    /**
     * ย้อนกลับได้ก็ต่อเมื่อไม่มีแถวใดเก็บค่ายาวเกินความกว้างเดิม
     * ถ้ามี ต้องหยุดพร้อมบอกจำนวนแถวที่กระทบ ไม่ปล่อยให้ฐานข้อมูลตัดข้อมูลทิ้งเงียบ ๆ
     */
    public function down(): void
    {
        $affected = DB::table('job_images')
            ->whereNotNull('file_type')
            ->whereRaw('LENGTH(file_type) > ?', [self::ORIGINAL_LENGTH])
            ->count();

        if ($affected > 0) {
            throw new RuntimeException(
                'ไม่สามารถย้อน migration นี้ได้: มี job_images จำนวน '.$affected.' แถว '
                .'ที่ค่า file_type ยาวเกิน '.self::ORIGINAL_LENGTH.' ตัวอักษร '
                .'การย้อนกลับจะทำให้ข้อมูลเดิมถูกตัดทิ้ง '
                .'กรุณาจัดการข้อมูลเหล่านั้นก่อนแล้วจึงย้อน migration อีกครั้ง'
            );
        }

        Schema::table('job_images', function (Blueprint $table) {
            $table->string('file_type', self::ORIGINAL_LENGTH)->nullable()->change();
        });
    }
};
