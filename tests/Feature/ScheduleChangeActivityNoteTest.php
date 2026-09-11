<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ScheduleChangeNote;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * แถบกิจกรรมของงานแสดงเฉพาะคอลัมน์ description
 *
 * ข้อความจึงต้องบอกวันเดิมกับวันใหม่ในตัวมันเอง ไม่ใช่ปล่อยให้ไปเปิดคอลัมน์
 * changes ที่มีแต่ผู้ดูแลระบบเข้าถึงได้ หัวหน้างานจะได้เห็นทันทีว่างานถูกเลื่อน
 * ออกไปกี่วันและเลื่อนจากวันไหน
 */
class ScheduleChangeActivityNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_date_change_records_the_old_and_new_date_in_the_activity_note(): void
    {
        $user = $this->user();
        $task = $this->task($user, '2026-09-03 16:15:00', '2026-09-03 16:15:00');

        // หน้าจอส่งเวลาไทยมาเสมอ ('Y-m-dTH:i' จากช่อง datetime-local) ไม่ใช่ UTC
        $this->actingAs($user)
            ->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => '2026-09-10T16:15'])
            ->assertOk();

        $note = ActivityLog::where('action', 'due_date_changed')->value('description');

        $this->assertStringContainsString('กำหนดส่ง 3 ก.ย. 2026 16:15 → 10 ก.ย. 2026 16:15', $note);
        $this->assertStringContainsString('(เลื่อนออก 7 วัน)', $note);
    }

    public function test_schedule_change_records_both_dates_and_the_direction_of_each_move(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, '2026-09-05 10:00:00', '2026-09-20 10:00:00');

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $task), [
                'job_start_at' => '2026-09-07T10:00',
                'job_due_at' => '2026-09-15T10:00',
            ])
            ->assertOk();

        $note = ActivityLog::where('action', 'schedule_changed')->value('description');

        $this->assertStringContainsString('วันเริ่ม 5 ก.ย. 2026 10:00 → 7 ก.ย. 2026 10:00 (เลื่อนออก 2 วัน)', $note);
        $this->assertStringContainsString('กำหนดส่ง 20 ก.ย. 2026 10:00 → 15 ก.ย. 2026 10:00 (เลื่อนเข้า 5 วัน)', $note);
    }

    /**
     * ค่าที่ไม่ถูกแก้ต้องหายไปจากข้อความ ไม่ใช่แสดงลูกศรชี้กลับไปที่ค่าเดิม
     */
    public function test_fields_that_did_not_move_are_left_out_of_the_note(): void
    {
        $note = ScheduleChangeNote::describe('เปลี่ยนช่วงเวลางาน: งานทดสอบ', [
            ['label' => 'วันเริ่ม', 'from' => '2026-09-05 03:00:00', 'to' => '2026-09-05 03:00:00'],
            ['label' => 'กำหนดส่ง', 'from' => '2026-09-20 03:00:00', 'to' => '2026-09-21 03:00:00'],
        ]);

        $this->assertStringNotContainsString('วันเริ่ม', $note);
        $this->assertStringContainsString('กำหนดส่ง 20 ก.ย. 2026 10:00 → 21 ก.ย. 2026 10:00 (เลื่อนออก 1 วัน)', $note);
    }

    public function test_a_save_that_changes_nothing_leaves_the_headline_alone(): void
    {
        $note = ScheduleChangeNote::describe('เปลี่ยนช่วงเวลางาน: งานทดสอบ', [
            ['label' => 'วันเริ่ม', 'from' => '2026-09-05 03:00:00', 'to' => '2026-09-05 03:00:00'],
        ]);

        $this->assertSame('เปลี่ยนช่วงเวลางาน: งานทดสอบ', $note);
    }

    /**
     * แก้เฉพาะเวลาในวันเดียวกันไม่ใช่การเลื่อนงาน จึงต้องไม่มีวงเล็บจำนวนวันต่อท้าย
     */
    public function test_a_time_only_change_shows_the_stamps_without_a_day_count(): void
    {
        $note = ScheduleChangeNote::describe('เปลี่ยนกำหนดส่งงาน: งานทดสอบ', [
            ['label' => 'กำหนดส่ง', 'from' => '2026-09-20 03:00:00', 'to' => '2026-09-20 09:00:00'],
        ]);

        $this->assertStringContainsString('กำหนดส่ง 20 ก.ย. 2026 10:00 → 20 ก.ย. 2026 16:00', $note);
        $this->assertStringNotContainsString('เลื่อน', $note);
    }

    /**
     * เวลาที่แสดงต้องเป็นเวลาไทย ไม่ใช่ UTC ที่เก็บในฐานข้อมูล
     */
    public function test_stamps_are_rendered_in_business_time_not_utc(): void
    {
        $this->assertSame('Asia/Bangkok', TodayWorkspace::BUSINESS_TIMEZONE);

        $note = ScheduleChangeNote::describe('หัวข้อ', [
            ['label' => 'กำหนดส่ง', 'from' => Carbon::parse('2026-09-20 17:30:00', 'UTC'), 'to' => Carbon::parse('2026-09-21 17:30:00', 'UTC')],
        ]);

        // 17:30 UTC ของวันที่ 20 คือ 00:30 ของวันที่ 21 ตามเวลาไทย
        $this->assertStringContainsString('21 ก.ย. 2026 00:30 → 22 ก.ย. 2026 00:30', $note);
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /** $start และ $due เขียนเป็นเวลาไทย ให้ตรงกับสิ่งที่ผู้ใช้กรอกและที่ข้อความกิจกรรมแสดง */
    private function task(User $owner, string $start, string $due): WorkOrder
    {
        $start = Carbon::parse($start, TodayWorkspace::BUSINESS_TIMEZONE)->utc()->toDateTimeString();
        $due = Carbon::parse($due, TodayWorkspace::BUSINESS_TIMEZONE)->utc()->toDateTimeString();

        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'งานทดสอบการเลื่อนวัน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => $start,
            'job_due_at' => $due,
        ]);
    }
}
