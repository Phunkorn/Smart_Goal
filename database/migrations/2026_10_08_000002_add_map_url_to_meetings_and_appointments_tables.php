<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลิงก์แผนที่ของสถานที่นัดพบ — ใช้ได้ทั้งการประชุมและนัดหมาย
 *
 * ทำไมเป็นคอลัมน์แยกจาก location
 * ---------------------------------------------------------------
 * location เป็นข้อความที่คนอ่าน ("ห้องประชุมชั้น 2") ส่วนนี่เป็นที่อยู่ที่เครื่องเปิดได้
 * การยัดสองอย่างลงช่องเดียวจะทำให้ต้องเดาเอาเองว่าข้อความไหนเป็นลิงก์ และหน้าจอ
 * จะต้องแยกวิเคราะห์ทุกครั้งที่แสดงผล
 *
 * 2048 ตัวอักษรเพราะลิงก์แชร์ของ Google Maps ที่ฝังพิกัดและชื่อสถานที่ยาวได้มาก
 * (ลิงก์ย่อ maps.app.goo.gl สั้นกว่ามาก แต่ต้องรองรับแบบเต็มด้วย)
 *
 * โปรโตคอลถูกจำกัดเป็น http/https ที่ชั้น validation ของ Controller
 * (กฎ url:http,https) ไม่ใช่ที่ฐานข้อมูล
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['meetings', 'appointments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'map_url')) {
                    $blueprint->string('map_url', 2048)->nullable()->after('location');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['meetings', 'appointments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('map_url');
            });
        }
    }
};
