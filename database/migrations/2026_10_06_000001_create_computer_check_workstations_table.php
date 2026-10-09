<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผังเครื่องคอมพิวเตอร์ของห้อง Call Center — แหล่งความจริงเดียวของตำแหน่งโต๊ะ
 *
 * ตำแหน่งถูกถอดมาจากแบบฟอร์มกระดาษจริง (IT26-0-01) ไม่ใช่การจัด grid ใหม่
 * เก็บเป็นตาราง ไม่ใช่ค่าคงที่ในโค้ด เพราะห้องจริงมีการย้ายโต๊ะได้ และการแก้ผัง
 * ไม่ควรต้อง deploy ใหม่ทุกครั้ง — เหตุผลเดียวกับ work_log_categories
 *
 * ทั้งผังบนเว็บ (SVG แบบกดได้) และผังในแบบตรวจ A4 อ่านตำแหน่งจากตารางนี้ชุดเดียว
 * ห้ามคัดลอกตัวเลขตำแหน่งไปไว้ใน Blade, CSS หรือ JavaScript อีกชุดหนึ่ง
 *
 * ระบบพิกัด: หน่วยของ viewBox ตาม App\Support\ComputerCheckDesign::ROOM_WIDTH
 * และ ROOM_HEIGHT (ไม่ใช่พิกเซลบนจอ) position_x/position_y คือมุมซ้ายบนของโต๊ะ
 *
 * แถวตั้งต้นถูก seed ใน up() ด้วยค่า literal ตามกฎของ CLAUDE.md
 * (ไม่ import ค่าคงที่จาก Model เข้ามาใน migration)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_check_workstations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('zone', 2);
            $table->unsignedSmallInteger('position_x');
            $table->unsignedSmallInteger('position_y');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'display_order'], 'computer_check_workstations_listing_index');
        });

        $now = now();

        /*
         * 20 เครื่องตามกระดาษจริง — Zone B อยู่ฝั่งซ้าย (9 เครื่อง)
         * Zone A อยู่ฝั่งขวา (11 เครื่อง)
         *
         * ความกว้างของโต๊ะไม่เท่ากันทุกคอลัมน์เพราะในกระดาษก็ไม่เท่ากัน
         * ห้ามปรับให้สมมาตรเพื่อให้ดูเรียบร้อยขึ้น
         */
        $rows = [
            // Zone A — คอลัมน์กลางขวา (x=514) และคอลัมน์ขวาสุด (x=794)
            ['code' => 'A-01', 'zone' => 'A', 'position_x' => 514, 'position_y' => 135, 'width' => 166, 'height' => 76, 'display_order' => 1],
            ['code' => 'A-02', 'zone' => 'A', 'position_x' => 794, 'position_y' => 135, 'width' => 178, 'height' => 76, 'display_order' => 2],
            ['code' => 'A-03', 'zone' => 'A', 'position_x' => 794, 'position_y' => 261, 'width' => 178, 'height' => 76, 'display_order' => 3],
            ['code' => 'A-04', 'zone' => 'A', 'position_x' => 794, 'position_y' => 389, 'width' => 178, 'height' => 76, 'display_order' => 4],
            ['code' => 'A-05', 'zone' => 'A', 'position_x' => 794, 'position_y' => 517, 'width' => 178, 'height' => 76, 'display_order' => 5],
            // โต๊ะเดี่ยวใต้บันได — ในกระดาษอยู่ต่ำกว่าแถวเดียวกันของฝั่งขวาเล็กน้อย
            ['code' => 'A-06', 'zone' => 'A', 'position_x' => 514, 'position_y' => 651, 'width' => 166, 'height' => 76, 'display_order' => 6],
            ['code' => 'A-07', 'zone' => 'A', 'position_x' => 794, 'position_y' => 644, 'width' => 178, 'height' => 76, 'display_order' => 7],
            ['code' => 'A-08', 'zone' => 'A', 'position_x' => 514, 'position_y' => 774, 'width' => 166, 'height' => 76, 'display_order' => 8],
            ['code' => 'A-09', 'zone' => 'A', 'position_x' => 794, 'position_y' => 774, 'width' => 178, 'height' => 76, 'display_order' => 9],
            ['code' => 'A-10', 'zone' => 'A', 'position_x' => 514, 'position_y' => 903, 'width' => 166, 'height' => 76, 'display_order' => 10],
            ['code' => 'A-11', 'zone' => 'A', 'position_x' => 794, 'position_y' => 903, 'width' => 178, 'height' => 76, 'display_order' => 11],

            // Zone B — คอลัมน์ซ้ายสุด (x=24) และคอลัมน์กลางซ้าย (x=312)
            ['code' => 'B-01', 'zone' => 'B', 'position_x' => 24, 'position_y' => 135, 'width' => 164, 'height' => 76, 'display_order' => 12],
            ['code' => 'B-02', 'zone' => 'B', 'position_x' => 312, 'position_y' => 135, 'width' => 164, 'height' => 76, 'display_order' => 13],
            ['code' => 'B-03', 'zone' => 'B', 'position_x' => 24, 'position_y' => 261, 'width' => 164, 'height' => 76, 'display_order' => 14],
            ['code' => 'B-04', 'zone' => 'B', 'position_x' => 24, 'position_y' => 389, 'width' => 164, 'height' => 76, 'display_order' => 15],
            ['code' => 'B-05', 'zone' => 'B', 'position_x' => 24, 'position_y' => 517, 'width' => 164, 'height' => 76, 'display_order' => 16],
            ['code' => 'B-06', 'zone' => 'B', 'position_x' => 24, 'position_y' => 644, 'width' => 164, 'height' => 76, 'display_order' => 17],
            ['code' => 'B-07', 'zone' => 'B', 'position_x' => 24, 'position_y' => 774, 'width' => 164, 'height' => 76, 'display_order' => 18],
            ['code' => 'B-08', 'zone' => 'B', 'position_x' => 312, 'position_y' => 774, 'width' => 164, 'height' => 76, 'display_order' => 19],
            ['code' => 'B-09', 'zone' => 'B', 'position_x' => 312, 'position_y' => 903, 'width' => 164, 'height' => 76, 'display_order' => 20],
        ];

        DB::table('computer_check_workstations')->insert(array_map(
            static fn (array $row): array => [...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            $rows
        ));
    }

    /**
     * down() ที่ทำลายข้อมูลต้องปฏิเสธแทนการลบเงียบ ๆ ตามกฎของ CLAUDE.md
     *
     * ตรวจเฉพาะเครื่องที่ไม่ใช่ของตั้งต้น เพราะ up() เป็นคนใส่ 20 เครื่องนั้นเอง
     * การย้อน migration ที่ยังไม่มีใครเพิ่มเครื่องจึงไม่ถือว่าเสียข้อมูล
     */
    public function down(): void
    {
        if (! Schema::hasTable('computer_check_workstations')) {
            return;
        }

        $seeded = [
            'A-01', 'A-02', 'A-03', 'A-04', 'A-05', 'A-06', 'A-07', 'A-08', 'A-09', 'A-10', 'A-11',
            'B-01', 'B-02', 'B-03', 'B-04', 'B-05', 'B-06', 'B-07', 'B-08', 'B-09',
        ];

        if (DB::table('computer_check_workstations')->whereNotIn('code', $seeded)->exists()) {
            throw new RuntimeException(
                'computer_check_workstations มีเครื่องที่เพิ่มเองอยู่ — ต้องสำรองข้อมูลก่อนย้อน migration นี้'
            );
        }

        Schema::dropIfExists('computer_check_workstations');
    }
};
