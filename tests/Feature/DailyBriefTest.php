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

    public function test_overdue_section_lists_only_my_approved_overdue_work(): void
    {
        $employee = $this->user('user', $this->it);
        $colleague = $this->user('user', $this->it);
        $project = WorkOrderList::create(['user_id' => $employee->id, 'name' => 'ระบบสมาชิก']);
        $archived = WorkOrderList::create(['user_id' => $employee->id, 'name' => 'โปรเจกต์เก่า', 'archived_at' => now()]);

        $this->overdueTask($employee, 'พัฒนา API ระบบสมาชิก', 3, ['work_order_list_id' => $project->id, 'job_priority' => 3]);
        $this->task($employee, 'งานที่ยังไม่เลยกำหนด');
        $this->overdueTask($employee, 'งานล่าช้าที่รออนุมัติ', 4, ['approval_status' => 'pending']);
        $this->overdueTask($employee, 'งานล่าช้าที่ปิดไปแล้ว', 5, ['job_status' => 4, 'job_completed_at' => now()]);
        $this->overdueTask($employee, 'งานล่าช้าในโปรเจกต์ที่จัดเก็บ', 6, ['work_order_list_id' => $archived->id]);
        $this->overdueTask($colleague, 'งานล่าช้าของเพื่อนร่วมงาน', 7);

        $brief = app(DailyBriefService::class)->build($employee);

        $this->assertSame(['พัฒนา API ระบบสมาชิก'], collect($brief['overdue_tasks'])->pluck('title')->all());
        $this->assertSame(1, $brief['overdue_total']);

        $task = $brief['overdue_tasks'][0];
        $this->assertSame('ระบบสมาชิก', $task['project']);
        $this->assertSame(3, $task['overdue_days']);
        $this->assertSame('ล่าช้า 3 วัน', $task['overdue_label']);
        $this->assertSame('22 ก.ย. 2569', $task['due_label']);
        $this->assertSame('สำคัญด่วน', $task['priority']['label']);
        $this->assertStringContainsString('open_task=', $task['url']);

        // งานที่เลยกำหนดแต่ยังไม่ถูกดันเป็นล่าช้า ถูกซิงก์เป็นสถานะล่าช้าจริงในฐานข้อมูล
        $this->assertSame(6, (int) WorkOrder::where('job_topic', 'พัฒนา API ระบบสมาชิก')->value('job_status'));
    }

    public function test_overdue_section_keeps_only_the_five_latest_tasks_ranked_by_days(): void
    {
        $employee = $this->user('user', $this->it);
        foreach (range(1, 8) as $days) {
            $this->overdueTask($employee, 'ล่าช้า '.$days.' วัน', $days);
        }

        $brief = app(DailyBriefService::class)->build($employee);

        $this->assertSame(8, $brief['overdue_total']);
        $this->assertCount(DailyBriefService::OVERDUE_LIMIT, $brief['overdue_tasks']);
        // ล่าช้ามากที่สุดขึ้นก่อน และอันดับมาจากงานล่าช้าทั้งหมด ไม่ใช่จากห้าใบแรกที่เจอ
        $this->assertSame(
            ['ล่าช้า 8 วัน', 'ล่าช้า 7 วัน', 'ล่าช้า 6 วัน', 'ล่าช้า 5 วัน', 'ล่าช้า 4 วัน'],
            collect($brief['overdue_tasks'])->pluck('title')->all()
        );
        $this->assertSame([8, 7, 6, 5, 4], collect($brief['overdue_tasks'])->pluck('overdue_days')->all());
        $this->assertCount(8, $brief['overdue_all_tasks']);
    }

    public function test_overdue_section_shows_an_empty_state_when_nothing_is_late(): void
    {
        $employee = $this->user('user', $this->it);
        $this->task($employee, 'งานของวันนี้ที่ยังไม่เลยกำหนด');

        $brief = app(DailyBriefService::class)->build($employee);

        $this->assertSame([], $brief['overdue_tasks']);
        $this->assertSame(0, $brief['overdue_total']);

        $this->actingAs($employee)->get(route('settings.index'))
            ->assertSee('ไม่มีงานโปรเจกต์ล่าช้า')
            ->assertDontSee('data-daily-brief-expand="project"', false);
    }

    public function test_a_single_overdue_task_is_shown_without_the_top_five_heading(): void
    {
        $employee = $this->user('user', $this->it);
        $this->overdueTask($employee, 'งานล่าช้าใบเดียว', 2);

        $this->actingAs($employee)->get(route('settings.index'))
            ->assertSee('งานโปรเจกต์ล่าช้า')
            ->assertDontSee('งานโปรเจกต์ล่าช้าที่สุด')
            ->assertSee('งานล่าช้าใบเดียว')
            ->assertSee('ล่าช้า 2 วัน')
            ->assertSee('ครบกำหนด 23 ก.ย. 2569');
    }

    public function test_tasks_overdue_by_the_same_number_of_days_keep_a_stable_order(): void
    {
        $employee = $this->user('user', $this->it);
        $later = $this->overdueTask($employee, 'ล่าช้าเท่ากัน ใบที่สอง', 2);
        $urgent = $this->overdueTask($employee, 'ล่าช้าเท่ากัน ใบด่วน', 2, ['job_priority' => 3]);

        $first = app(DailyBriefService::class)->build($employee);
        $second = app(DailyBriefService::class)->build($employee);

        // งานด่วนชนะเมื่อล่าช้าเท่ากัน และลำดับไม่สลับระหว่างการเรียกสองครั้ง
        $this->assertSame(
            ['ล่าช้าเท่ากัน ใบด่วน', 'ล่าช้าเท่ากัน ใบที่สอง'],
            collect($first['overdue_tasks'])->pluck('title')->all()
        );
        $this->assertSame([$urgent->job_id, $later->job_id], collect($first['overdue_tasks'])->pluck('id')->all());
        $this->assertSame(
            collect($first['overdue_tasks'])->pluck('id')->all(),
            collect($second['overdue_tasks'])->pluck('id')->all()
        );
    }

    public function test_overdue_days_are_counted_from_the_bangkok_business_day(): void
    {
        $employee = $this->user('user', $this->it);

        // 16:59 UTC ของวันที่ 24 คือ 23:59 เวลาไทยของวันที่ 24 จึงล่าช้าหนึ่งวันตามวันไทย
        $task = $this->task($employee, 'งานที่หมดเวลาเมื่อคืน', [
            'job_start_at' => now()->subDays(2),
            'job_due_at' => now()->parse('2026-09-24 16:59:00', 'UTC'),
        ]);

        $brief = app(DailyBriefService::class)->build($employee);

        $this->assertSame($task->job_id, $brief['overdue_tasks'][0]['id']);
        $this->assertSame(1, $brief['overdue_tasks'][0]['overdue_days']);
        $this->assertSame('24 ก.ย. 2569', $brief['overdue_tasks'][0]['due_label']);
    }

    public function test_see_all_lists_every_overdue_task_inside_the_brief_without_leaving_the_page(): void
    {
        $employee = $this->user('user', $this->it);
        foreach (range(1, 12) as $days) {
            $this->overdueTask($employee, sprintf('งานล่าช้าลำดับ %02d', $days), $days);
        }

        $brief = app(DailyBriefService::class)->build($employee);
        $this->assertSame(12, $brief['overdue_total']);
        $this->assertCount(DailyBriefService::OVERDUE_LIMIT, $brief['overdue_tasks']);
        $this->assertCount(12, $brief['overdue_all_tasks']);

        $this->actingAs($employee)->get(route('settings.index'))
            ->assertSee('5 งานโปรเจกต์ล่าช้าที่สุด')
            ->assertSee('data-daily-brief-expand="project"', false)
            ->assertSee('ดูงานล่าช้าทั้งหมด 12 งาน')
            ->assertSee('data-daily-brief-list="project"', false)
            ->assertSee('งานโปรเจกต์ล่าช้าทั้งหมด')
            // งานที่เกินการ์ดสรุปต้องอยู่ในกล่อง "ดูทั้งหมด"
            ->assertSee('งานล่าช้าลำดับ 01')
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

    /** งานที่เลยกำหนดส่งมาแล้ว $days วันตามวันทำการไทย */
    private function overdueTask(User $assignee, string $topic, int $days, array $attributes = []): WorkOrder
    {
        return $this->task($assignee, $topic, [
            'job_start_at' => now()->subDays($days + 1),
            'job_due_at' => now()->subDays($days),
            ...$attributes,
        ]);
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
