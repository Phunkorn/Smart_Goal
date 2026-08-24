<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * config('app.timezone') คือ UTC แต่ธุรกิจใช้ Asia/Bangkok
 * วันที่ที่ส่งให้ปฏิทินและบอร์ดจึงต้องแปลงก่อนเสมอ มิฉะนั้นงานจะถูกวางผิดไป 1 วัน
 */
class TaskCalendarTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-20 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_date_helper_uses_the_business_timezone(): void
    {
        // 17:00 UTC คือเที่ยงคืนพอดีของวันถัดไปตามเวลาไทย (UTC+7)
        $this->assertSame('2026-08-20', TodayWorkspace::calendarDate(Carbon::parse('2026-08-20 16:59:59', 'UTC')));
        $this->assertSame('2026-08-21', TodayWorkspace::calendarDate(Carbon::parse('2026-08-20 17:00:00', 'UTC')));
        $this->assertSame('', TodayWorkspace::calendarDate(null));
    }

    public function test_calendar_date_helper_does_not_mutate_the_given_instance(): void
    {
        $due = Carbon::parse('2026-08-20 18:00:00', 'UTC');

        TodayWorkspace::calendarDate($due);

        $this->assertSame('2026-08-20 18:00:00', $due->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $due->timezone->getName());
    }

    public function test_task_rows_and_board_cards_expose_bangkok_dates(): void
    {
        $user = $this->member();
        $project = WorkOrderList::create([
            'user_id' => $user->id,
            'name' => 'โปรเจกต์เขตเวลา',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        // เริ่ม 21 ส.ค. 01:00 น. ไทย · ครบกำหนด 23 ส.ค. 01:00 น. ไทย
        $this->task($project, $user, 'งานข้ามวัน', '2026-08-20 18:00:00', '2026-08-22 18:00:00');
        // ครบกำหนด 23:59 น. ของวันที่ 20 ส.ค. ตามเวลาไทย ต้องยังเป็นวันที่ 20
        $this->task($project, $user, 'งานก่อนเที่ยงคืน', '2026-08-20 02:00:00', '2026-08-20 16:59:00');

        $content = $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-start="2026-08-21" data-due="2026-08-23"', $content);
        $this->assertStringContainsString('data-due="2026-08-20"', $content);
        $this->assertStringNotContainsString('data-start="2026-08-20" data-due="2026-08-22"', $content);

        // บอร์ดโปรเจกต์ใช้ data-due ชุดเดียวกัน จึงต้องเลื่อนตามไปด้วย
        $this->assertMatchesRegularExpression(
            '/data-board-task[^>]*data-due="2026-08-23"/',
            $content,
            'การ์ดบนบอร์ดต้องใช้วันที่ตามเขตเวลาไทยเช่นกัน'
        );
    }

    private function member(): User
    {
        $department = Department::create(['department_name' => 'Timezone '.uniqid()]);

        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }

    private function task(WorkOrderList $project, User $user, string $topic, string $startUtc, string $dueUtc): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'leader_user_id' => $user->id,
            'department_id' => $user->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_details' => 'รายละเอียด',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'job_progress' => 0,
            'job_start_at' => Carbon::parse($startUtc, 'UTC'),
            'job_due_at' => Carbon::parse($dueUtc, 'UTC'),
        ]);
    }
}
