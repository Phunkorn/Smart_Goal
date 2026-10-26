<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket แจ้งปัญหา IT
 *
 * Snapshot ณ เวลาที่แจ้ง
 * ---------------------------------------------------------------
 * requester_name, requester_department_name และ location_name ถูกคัดลอกไว้ตอนสร้าง
 * เพราะผู้ใช้ แผนก และสาขาเปลี่ยนชื่อหรือถูกลบได้ภายหลัง แต่รายงานย้อนหลังต้องคงค่าเดิม
 * location_id เป็น null เมื่อผู้แจ้งเลือก "อื่น ๆ" และชื่อที่พิมพ์เองอยู่ใน location_name ช่องเดียว
 *
 * FK ไปที่ users เป็น nullOnDelete ทั้งหมด
 * ---------------------------------------------------------------
 * คำสั่ง trash:purge-expired ลบผู้ใช้ถาวรเมื่อพ้นระยะเก็บ ถ้าเป็น cascade ประวัติ Ticket
 * จะหายไปด้วย ถ้าเป็น restrict คำสั่ง purge จะล้ม ชื่อที่ต้องแสดงจึงอยู่ใน snapshot แทน
 *
 * ticket_number เป็น nullable เพราะเลขคำนวณจาก id ซึ่งรู้หลัง insert
 * ตั้งค่าในทรานแซกชันเดียวกับการสร้าง (ดู TicketSubmissionService)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 20)->nullable()->unique();
            $table->string('source', 10);
            $table->text('description');
            $table->string('status', 20)->default('new');

            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 150);
            $table->foreignId('requester_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('requester_department_name', 150)->nullable();
            $table->string('contact', 150)->nullable();

            $table->foreignId('location_id')->nullable()->constrained('ticket_locations')->nullOnDelete();
            $table->string('location_name', 150);

            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_date')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->string('submitter_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets') && DB::table('tickets')->exists()) {
            throw new RuntimeException('ไม่สามารถถอยกลับ migration นี้ได้ มี Ticket บันทึกไว้แล้ว');
        }

        Schema::dropIfExists('tickets');
    }
};
