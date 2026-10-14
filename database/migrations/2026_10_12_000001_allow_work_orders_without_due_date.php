<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "ไม่มีกำหนดส่ง" — งานที่ไม่มีวันครบกำหนดเลย
 *
 * คนละเรื่องกับ "ไม่ระบุเวลากำหนดส่ง" (2026_10_10_000002) ซึ่งยังมีวันส่งอยู่
 * และต้องมีขณะเวลาไว้ให้วงจรสถานะล่าช้าเทียบ งานที่ไม่มีกำหนดส่งไม่มีขณะเวลา
 * ให้เทียบตั้งแต่ต้น ค่าที่ตรงความหมายที่สุดจึงเป็น NULL ไม่ใช่ธงอีกหนึ่งคอลัมน์
 *
 * ทำไมไม่ใช้ธง + วันที่ค้างไว้
 * ---------------------------------------------------------------
 * ทุกจุดที่อ่าน job_due_at (ล่าช้า รายงาน ปฏิทิน การเรียง Daily Brief) จะต้องรู้จักธงนั้น
 * จุดที่ลืมจะแสดงวันส่งที่ผู้ใช้ยกเลิกไปแล้วแบบเงียบ ๆ ส่วน NULL ถูกตัดออกจากการเทียบ
 * `job_due_at < now()` โดยฐานข้อมูลเอง และโค้ดที่อ่านคอลัมน์นี้กัน null ไว้อยู่แล้ว
 * (TodayWorkspace::synchronizeLate ใช้ whereNotNull มาตั้งแต่ต้น)
 *
 * งานที่มีอยู่ทุกใบยังมีกำหนดส่งเหมือนเดิม migration นี้ไม่แตะข้อมูล
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dateTime('job_due_at')->nullable()->change();
        });
    }

    /**
     * ย้อนกลับได้เฉพาะเมื่อยังไม่มีงานที่เลือก "ไม่มีกำหนดส่ง"
     *
     * การบังคับ NOT NULL กลับจะล้มกลางทางบน MySQL โหมด strict หรือถูกเติมเป็น
     * '0000-00-00' ในโหมดอื่น ซึ่งคือการตั้งกำหนดส่งให้งานที่ผู้ใช้บอกว่าไม่มี
     * จึงหยุดและให้คนตัดสินใจก่อน
     */
    public function down(): void
    {
        $withoutDue = DB::table('work_orders')->whereNull('job_due_at')->count();

        if ($withoutDue > 0) {
            throw new RuntimeException(
                'ยกเลิก migration นี้ไม่ได้: มีงาน '.$withoutDue.' รายการที่เลือก "ไม่มีกำหนดส่ง" '
                .'คอลัมน์ job_due_at จะกลับเป็น NOT NULL ไม่ได้ขณะที่ยังมีค่าว่าง '
                .'ให้ตั้งกำหนดส่งของงานเหล่านั้นก่อน แล้วจึงย้อน migration'
            );
        }

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dateTime('job_due_at')->nullable(false)->change();
        });
    }
};
