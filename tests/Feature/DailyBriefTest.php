<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\DailyBriefAcknowledgement;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\DailyBriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * สรุปประจำวัน (Daily Brief)
 *
 * เวลาถูกตรึงไว้ที่ 10:00 น. เวลาไทยของวันที่ 25 ก.ย. 2569 (03:00 UTC)
 * ก่อนรอบปิดงานประจำ 17:00 และห่างจากเที่ยงคืนไทยพอที่วัน UTC กับวันไทยตรงกัน
 */
class DailyBriefTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-25';

    private const MODAL = 'data-daily-brief';

    private Department $it;

    private Department $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);
    }

    public function test_employee_sees_the_brief_after_logging_in_until_they_acknowledge_it(): void
    {
        $employee = $this->user('user', $this->it, ['username' => 'brief.employee']);

        $this->post(route('login.submit'), ['username' => 'brief.employee', 'password' => 'password'])
            ->assertRedirect(route('mytasks.index'));

        $this->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee(self::MODAL, false)
            ->assertSee('data-brief-date="'.self::TODAY.'"', false)
            ->assertSee('สรุปประจำวัน')
            ->assertSee('วันศุกร์ที่ 25 กันยายน 2569');

        // ปิดด้วย X ไม่บันทึกอะไร หน้าถัดไปจึงยังเห็นสรุปอยู่
        $this->get(route('settings.index'))->assertSee(self::MODAL, false);

        $this->postJson(route('daily-brief.acknowledge'), ['brief_date' => self::TODAY])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->get(route('mytasks.index'))->assertOk()->assertDontSee(self::MODAL, false);

        // logout แล้ว login ใหม่ในวันเดียวกันต้องไม่แสดงซ้ำ
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['username' => 'brief.employee', 'password' => 'password']);
        $this->get(route('mytasks.index'))->assertOk()->assertDontSee(self::MODAL, false);

        $this->assertSame(1, DailyBriefAcknowledgement::where('user_id', $employee->id)->count());
    }

    public function test_brief_returns_on_the_next_bangkok_business_day(): void
    {
        $employee = $this->user('user', $this->it);
        $this->actingAs($employee)->postJson(route('daily-brief.acknowledge'), ['brief_date' => self::TODAY])->assertOk();

        // 16:59 UTC ยังเป็นวันที่ 25 ตามเวลาไทย (23:59)
        $this->travelTo(now()->parse(self::TODAY.' 16:59:00', 'UTC'));
        $this->actingAs($employee)->get(route('settings.index'))->assertDontSee(self::MODAL, false);

        // 17:01 UTC คือ 00:01 ของวันที่ 26 ตามเวลาไทย
        $this->travelTo(now()->parse(self::TODAY.' 17:01:00', 'UTC'));
        $this->actingAs($employee)->get(route('settings.index'))
            ->assertSee(self::MODAL, false)
            ->assertSee('data-brief-date="2026-09-26"', false);
    }

    public function test_department_head_sees_the_brief_but_admin_and_viewer_never_do(): void
    {
        $this->actingAs($this->user('user', $this->it, ['is_department_head' => true]))
            ->get(route('settings.index'))
            ->assertSee(self::MODAL, false);

        $this->actingAs($this->user('admin'))->get(route('board.index'))->assertOk()->assertDontSee(self::MODAL, false);
        $this->actingAs($this->user('viewer'))->get(route('board.index'))->assertOk()->assertDontSee(self::MODAL, false);
    }

    public function test_admin_and_viewer_cannot_acknowledge(): void
    {
        $this->actingAs($this->user('admin'))
            ->postJson(route('daily-brief.acknowledge'), ['brief_date' => self::TODAY])
            ->assertForbidden();
        $this->actingAs($this->user('viewer'))
            ->postJson(route('daily-brief.acknowledge'), ['brief_date' => self::TODAY])
            ->assertForbidden();

        $this->assertSame(0, DailyBriefAcknowledgement::count());
    }

    public function test_stale_brief_from_before_midnight_is_rejected(): void
    {
        $employee = $this->user('user', $this->it);

        $this->actingAs($employee)
            ->postJson(route('daily-brief.acknowledge'), ['brief_date' => '2026-09-24'])
            ->assertStatus(409)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, DailyBriefAcknowledgement::count());
        $this->actingAs($employee)->get(route('settings.index'))->assertSee(self::MODAL, false);
    }

    public function test_acknowledging_twice_keeps_a_single_row(): void
    {
        $employee = $this->user('user', $this->it);

        $this->actingAs($employee)->postJson(route('daily-brief.acknowledge'), ['brief_date' => self::TODAY])->assertOk();
        $this->actingAs($employee)->postJson(route('daily-brief.acknowledge'), ['brief_date' => self::TODAY])->assertOk();

        $this->assertSame(1, DailyBriefAcknowledgement::count());
    }

    public function test_project_section_lists_only_my_approved_work_for_today(): void
    {
        $employee = $this->user('user', $this->it);
        $colleague = $this->user('user', $this->it);
        $project = WorkOrderList::create(['user_id' => $employee->id, 'name' => 'ระบบสมาชิก']);
        $archived = WorkOrderList::create(['user_id' => $employee->id, 'name' => 'โปรเจกต์เก่า', 'archived_at' => now()]);

        $this->task($employee, 'พัฒนา API ระบบสมาชิก', ['work_order_list_id' => $project->id, 'job_priority' => 3]);
        $this->task($employee, 'งานรออนุมัติ', ['approval_status' => 'pending']);
        $this->task($employee, 'งานที่เสร็จแล้ว', ['job_status' => 4, 'job_completed_at' => now()]);
        $this->task($employee, 'งานของสัปดาห์หน้า', ['job_start_at' => now()->addDays(7), 'job_due_at' => now()->addDays(8)]);
        $this->task($employee, 'งานในโปรเจกต์ที่จัดเก็บ', ['work_order_list_id' => $archived->id]);
        $this->task($colleague, 'งานของเพื่อนร่วมงาน');
        $this->task($employee, 'งานที่เลยกำหนดแล้ว', [
            'job_start_at' => now()->subDays(3),
            'job_due_at' => now()->subDays(2),
        ]);

        $brief = app(DailyBriefService::class)->build($employee);
        $titles = collect($brief['project_tasks'])->pluck('title');

        $this->assertEqualsCanonicalizing(['พัฒนา API ระบบสมาชิก', 'งานที่เลยกำหนดแล้ว'], $titles->all());
        // งานล่าช้าขึ้นก่อน และถูกซิงก์เป็นสถานะล่าช้าจริงในฐานข้อมูล
        $this->assertSame('งานที่เลยกำหนดแล้ว', $titles->first());
        $this->assertSame(6, (int) WorkOrder::where('job_topic', 'งานที่เลยกำหนดแล้ว')->value('job_status'));

        $apiTask = collect($brief['project_tasks'])->firstWhere('title', 'พัฒนา API ระบบสมาชิก');
        $this->assertSame('ระบบสมาชิก', $apiTask['project']);
        $this->assertSame('สำคัญด่วน', $apiTask['priority']['label']);
        $this->assertStringContainsString('open_task=', $apiTask['url']);
    }

    public function test_see_all_lists_every_task_inside_the_brief_without_leaving_the_page(): void
    {
        $employee = $this->user('user', $this->it);
        foreach (range(1, 12) as $number) {
            $this->task($employee, sprintf('งานวันนี้ลำดับ %02d', $number));
        }

        $brief = app(DailyBriefService::class)->build($employee);
        $this->assertSame(12, $brief['project_total']);
        $this->assertCount(DailyBriefService::TASK_LIMIT, $brief['project_tasks']);
        $this->assertCount(12, $brief['project_all_tasks']);

        $this->actingAs($employee)->get(route('settings.index'))
            ->assertSee('data-daily-brief-expand="project"', false)
            ->assertSee('ดูงานวันนี้ทั้งหมด 12 งาน')
            ->assertSee('data-daily-brief-list="project"', false)
            ->assertSee('งานโปรเจกต์วันนี้ทั้งหมด')
            // งานที่เกินการ์ดสรุปต้องอยู่ในกล่อง "ดูทั้งหมด"
            ->assertSee('งานวันนี้ลำดับ 12')
            // ไม่มีลิงก์พาออกไปหน้างานของฉันแบบเดิมแล้ว
            ->assertDontSee('task_scope=today', false);
    }

    public function test_routine_section_lists_todays_routine_work(): void
    {
        $employee = $this->user('user', $this->it);
        WorkLogTemplate::create([
            'user_id' => $employee->id,
            'title' => 'ตรวจสอบ Backup ระบบ',
            'kind' => 'routine',
            'weekday_mask' => 127,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $brief = app(DailyBriefService::class)->build($employee);

        $this->assertSame(1, $brief['routine_total']);
        $this->assertSame('ตรวจสอบ Backup ระบบ', $brief['routine_tasks'][0]['title']);
        $this->assertSame('08:30', $brief['routine_tasks'][0]['time']);

        $this->actingAs($employee)->get(route('settings.index'))->assertSee('ตรวจสอบ Backup ระบบ');
    }

    public function test_announcement_sections_follow_audience_and_date_window(): void
    {
        $employee = $this->user('user', $this->it);
        $itHead = $this->user('user', $this->it, ['is_department_head' => true, 'name' => 'สมชาย ใจดี']);
        $hrHead = $this->user('user', $this->hr, ['is_department_head' => true, 'name' => 'สุภาวดี พงศ์ศิริ']);

        $this->announcement($itHead, ['title' => 'ปิดปรับปรุงเซิร์ฟเวอร์']);
        $this->announcement($hrHead, ['title' => 'อัปเดตข้อมูลบุคคล', 'audience' => 'all']);
        $this->announcement($hrHead, ['title' => 'ประชุมภายใน HR']);
        $this->announcement($itHead, ['title' => 'ประกาศล่วงหน้า', 'starts_on' => '2026-09-26']);
        $this->announcement($itHead, ['title' => 'ประกาศหมดอายุ', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-24']);
        $deleted = $this->announcement($itHead, ['title' => 'ประกาศที่ถูกลบ']);
        $deleted->delete();

        $brief = app(DailyBriefService::class)->build($employee);

        $this->assertSame(['ปิดปรับปรุงเซิร์ฟเวอร์'], collect($brief['department_announcements'])->pluck('title')->all());
        $this->assertSame(['อัปเดตข้อมูลบุคคล'], collect($brief['all_announcements'])->pluck('title')->all());

        $this->actingAs($employee)->get(route('settings.index'))
            ->assertSee('ประกาศจากแผนก IT')
            ->assertSee('สมชาย ใจดี')
            ->assertSee('หัวหน้าแผนก · IT')
            ->assertSee('สุภาวดี พงศ์ศิริ')
            ->assertSee('หัวหน้าแผนก · HR')
            ->assertDontSee('ประชุมภายใน HR')
            ->assertDontSee('ประกาศล่วงหน้า')
            ->assertDontSee('ประกาศหมดอายุ')
            ->assertDontSee('ประกาศที่ถูกลบ');
    }

    public function test_announcement_body_is_escaped_and_keeps_line_breaks(): void
    {
        $employee = $this->user('user', $this->it);
        $head = $this->user('user', $this->it, ['is_department_head' => true]);
        $this->announcement($head, ['body' => "บรรทัดแรก\n<script>alert(1)</script>"]);

        $this->actingAs($employee)->get(route('settings.index'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('บรรทัดแรก<br />', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_new_badge_marks_announcements_changed_since_the_last_acknowledgement(): void
    {
        $employee = $this->user('user', $this->it);
        $head = $this->user('user', $this->it, ['is_department_head' => true]);

        $this->travelTo(now()->parse('2026-09-24 03:00:00', 'UTC'));
        $old = $this->announcement($head, ['title' => 'ประกาศเดิม', 'starts_on' => '2026-09-24']);
        app(DailyBriefService::class)->acknowledge($employee, '2026-09-24');

        $this->travelTo(now()->parse(self::TODAY.' 02:00:00', 'UTC'));
        $this->announcement($head, ['title' => 'ประกาศใหม่']);

        $this->travelTo(now()->parse(self::TODAY.' 03:00:00', 'UTC'));
        $flags = collect(app(DailyBriefService::class)->build($employee)['department_announcements'])
            ->pluck('is_new', 'title');

        $this->assertFalse($flags[$old->title]);
        $this->assertTrue($flags['ประกาศใหม่']);
    }

    private function task(User $assignee, string $topic, array $attributes = []): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'assigned_by' => $assignee->id,
            'department_id' => $assignee->department_id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subHour(),
            'job_due_at' => now()->addHours(3),
            ...$attributes,
        ]);
    }

    private function announcement(User $author, array $attributes = []): Announcement
    {
        $announcement = new Announcement([
            'title' => 'ประกาศ',
            'body' => 'รายละเอียด',
            'audience' => Announcement::AUDIENCE_DEPARTMENT,
            'starts_on' => self::TODAY,
            ...$attributes,
        ]);
        $announcement->created_by = $author->id;
        $announcement->department_id = $author->department_id;
        $announcement->save();

        return $announcement;
    }

    private function user(string $role, ?Department $department = null, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
