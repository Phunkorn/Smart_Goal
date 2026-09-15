<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Services\OperationalWorkloadReportService;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * บอร์ดทีมบอกได้ว่าวันนี้ใครทำงานประจำหรือออกนอกสถานที่ และตัวเลขตรงกับรายงานปฏิบัติงาน
 *
 * สิทธิ์เดียวกับหน้าบันทึกงาน (WorkLogPolicy::viewDay): ตัวเอง / หัวหน้าแผนกนั้น / admin
 */
class WorkBoardTodayOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $head;

    private User $member;

    private User $teammate;

    protected function setUp(): void
    {
        parent::setUp();
        // จันทร์ 7 ก.ย. 2026 เวลา 09:00 ที่กรุงเทพ
        $this->travelTo(CarbonImmutable::parse('2026-09-07 02:00:00', 'UTC'));

        $this->department = Department::create(['department_name' => 'IT']);
        $this->head = $this->user(['name' => 'หัวหน้าไอที', 'is_department_head' => true]);
        $this->member = $this->user(['name' => 'ภูมิ']);
        $this->teammate = $this->user(['name' => 'บีม']);

        // งานประจำทุกวัน 2 งาน: ปิดแล้ว 1 ยังไม่เปิดระบบ 1 + งานนอกสถานที่ 1
        $daily = WorkLogTemplate::create([
            'user_id' => $this->member->id, 'title' => 'เช็คเซิร์ฟเวอร์', 'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK, 'is_active' => true,
        ]);
        WorkLogTemplate::create([
            'user_id' => $this->member->id, 'title' => 'สำรองข้อมูล', 'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK, 'is_active' => true,
        ]);
        $this->log(['work_log_template_id' => $daily->id, 'title' => 'เช็คเซิร์ฟเวอร์', 'status' => 'done', 'source' => 'template']);
        $this->log(['kind' => 'field', 'title' => 'ติดตั้งเครื่องพิมพ์', 'location' => 'สาขาบางนา']);
    }

    public function test_the_department_head_sees_todays_routine_and_field_work_on_the_member_card(): void
    {
        $response = $this->actingAs($this->head)->get(route('work-board.department', $this->department))->assertOk();

        $response->assertSee('data-member-operations', false)
            ->assertSee('งานประจำ 1/2')
            ->assertSee('นอกสถานที่ · สาขาบางนา');

        $members = $response->viewData('members')->keyBy('id');
        // ปิดไปแล้ว 1 จาก 2 = กำลังทำ (กติกาเดียวกับภาพรวมทีมของรายงาน)
        $this->assertSame('progress', $members[$this->member->id]->board_operations['routine']['key']);
        $this->assertSame('none', $members[$this->teammate->id]->board_operations['routine']['key']);

        $this->actingAs($this->head)->get(route('work-board.member', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('งานปฏิบัติวันนี้')
            ->assertSee('ติดตั้งเครื่องพิมพ์')
            ->assertSee('สำรองข้อมูล')
            ->assertSee('ยังไม่เริ่ม');
    }

    public function test_a_teammate_does_not_see_other_peoples_daily_work(): void
    {
        $response = $this->actingAs($this->teammate)->get(route('work-board.department', $this->department))->assertOk();
        $members = $response->viewData('members')->keyBy('id');

        $this->assertNull($members[$this->member->id]->board_operations);
        // เห็นของตัวเองได้ เท่ากับที่เห็นในหน้าบันทึกงานของตัวเอง
        $this->assertNotNull($members[$this->teammate->id]->board_operations);
        $response->assertDontSee('นอกสถานที่ · สาขาบางนา');

        $this->actingAs($this->teammate)->get(route('work-board.member', [$this->department, $this->member]))
            ->assertOk()
            ->assertDontSee('งานปฏิบัติวันนี้')
            ->assertDontSee('ติดตั้งเครื่องพิมพ์');
    }

    public function test_admin_sees_the_same_status_on_the_admin_board(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->get(route('admin.work-board.department', $this->department))
            ->assertOk()
            ->assertSee('งานประจำ 1/2');
        $this->actingAs($admin)->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('ติดตั้งเครื่องพิมพ์');
    }

    public function test_the_board_and_the_team_report_show_the_same_today_numbers(): void
    {
        $board = $this->actingAs($this->head)->get(route('work-board.department', $this->department))
            ->viewData('members')->keyBy('id')[$this->member->id]->board_operations['routine'];

        $report = $this->actingAs($this->head)->get(route('reports.operational'))->assertOk();
        $person = collect($report->viewData('people'))->firstWhere('id', $this->member->id);

        $this->assertSame(
            [$board['key'], $board['closed'], $board['total']],
            [$person['today']['key'], $person['today']['closed'], $person['today']['total']]
        );
    }

    private function pendingOf(): int
    {
        $report = $this->actingAs($this->head)->get(route('reports.operational', ['owner' => $this->member->id, 'month' => '2026-09']))->assertOk();

        return (int) preg_replace('/\D/', '', $report->viewData('kpis')['pending']['value']);
    }

    private function log(array $attributes): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $this->member->id,
            'created_by' => $this->member->id,
            'department_id' => $this->department->id,
            'kind' => 'routine',
            'status' => 'open',
            'source' => 'manual',
            'work_date' => '2026-09-07',
        ], $attributes));
    }

    private function user(array $attributes): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'department_id' => $this->department->id,
            'is_active' => true,
            'must_change_password' => false,
        ], $attributes));
    }
}
