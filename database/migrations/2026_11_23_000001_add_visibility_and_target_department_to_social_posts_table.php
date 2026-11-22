<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * การมองเห็นโพสต์ชุมชน — ทุกคนในองค์กร (everyone) หรือเฉพาะแผนก (department)
 *
 * โพสต์เดิมทั้งหมดได้ค่าตั้งต้น everyone จึงยังมองเห็นได้ทุกแผนกเหมือนก่อน migration นี้
 * target_department_id ถูกตรึงจากแผนกของผู้โพสต์ ณ เวลาที่เลือก "เฉพาะแผนก" (ฝั่ง server เท่านั้น)
 * FK แบบ restrict ตามแนวเดียวกับประกาศและกระดานไอเดีย — DepartmentController ดักการลบแผนก
 * ที่ยังมีโพสต์จำกัดแผนกไว้ก่อนถึงฐานข้อมูล
 *
 * ค่าในคอลัมน์เป็นค่าคงที่ ณ เวลา migration (ไม่ import ค่าคงที่ของ Model)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->string('visibility', 20)->default('everyone')->after('body');
            $table->foreignId('target_department_id')->nullable()->after('visibility')
                ->constrained('departments')->restrictOnDelete();

            $table->index(['visibility', 'target_department_id'], 'social_posts_visibility_department_index');
        });
    }

    public function down(): void
    {
        /*
         * ถอยกลับ = ทุกโพสต์กลายเป็น "ทุกคนเห็น" ซึ่งทำให้โพสต์ที่จำกัดแผนกรั่วไปทั้งองค์กร
         * จึงปฏิเสธเมื่อยังมีโพสต์จำกัดแผนกอยู่ (รวมโพสต์ในถังขยะ) แทนการลดสิทธิ์อย่างเงียบ ๆ
         */
        if (Schema::hasColumn('social_posts', 'visibility')
            && DB::table('social_posts')->where('visibility', '!=', 'everyone')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มีโพสต์ชุมชนที่จำกัดการมองเห็นเฉพาะแผนกอยู่');
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropIndex('social_posts_visibility_department_index');
            $table->dropConstrainedForeignId('target_department_id');
            $table->dropColumn('visibility');
        });
    }
};
