<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderUpdate;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick View ของปฏิทิน — โหลดตอนคลิกเท่านั้น และตรวจสิทธิ์ที่ server ทุกครั้ง
 *
 * การซ่อนปุ่มฝั่ง client ไม่ถือเป็นการป้องกัน endpoint จึงต้องปฏิเสธเองได้
 */
class CalendarQuickViewTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Operations']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_open_task_quick_view(): void
    {
        [$member, , $task] = $this->scenario();

        $this->actingAs($member)
            ->get(route('mytasks.quickview.task', ['id' => $task->job_id, 'milestone' => 'end']))
            ->assertOk()
            ->assertSee('data-quick-view-type="task"', false)
            // ชื่อโปรเจกต์คือ kicker ของ popover shell (data-quick-view-kicker-text)
            ->assertSee('data-quick-view-kicker-text="โปรเจกต์ปฏิทิน"', false)
            ->assertSee('งานทดสอบปฏิทิน')
            ->assertSee('กำลังทำ')
            // ปุ่ม "ดูรายละเอียดทั้งหมด" ต้องไม่ถูกกำหนดจาก endpoint
            ->assertDontSee('data-quick-view-detail-url', false);
    }

    public function test_task_quick_view_rejects_users_without_view_permission(): void
    {
        [, , $task] = $this->scenario();
        $stranger = $this->member('Stranger');

        $this->actingAs($stranger)
            ->get(route('mytasks.quickview.task', ['id' => $task->job_id]))
            ->assertForbidden();
    }

    public function test_admin_can_open_the_same_task_quick_view_component(): void
    {
        [$member, $admin, $task] = $this->scenario();
        // ใส่ผู้ร่วมงานให้ส่วนรายชื่อถูก render จริง จะได้เทียบ component ได้ครบทุกบล็อก
        $task->collaborators()->attach($this->member('Mate')->id, ['added_by' => $member->id, 'status' => 'accepted']);

        $memberHtml = $this->actingAs($member)->get(route('mytasks.quickview.task', ['id' => $task->job_id]))->assertOk()->getContent();
        $adminHtml = $this->actingAs($admin)->get(route('mytasks.quickview.task', ['id' => $task->job_id]))->assertOk()->getContent();

        // component ชุดเดียวกัน ต่างกันได้เฉพาะข้อมูลและสิทธิ์
        foreach (['data-quick-view-type="task"', 'qv-summary', 'qv-people'] as $marker) {
            $this->assertStringContainsString($marker, $memberHtml);
            $this->assertStringContainsString($marker, $adminHtml);
        }
    }

    /**
     * ไม่มี test เดิมใดสร้าง WorkOrderUpdate จริงให้ endpoint นี้ ทำให้เส้นทาง
     * "อัปเดตล่าสุด" ของ partial ไม่เคยถูกเรียกจริง (created_at->translatedFormat()) เพิ่ม test
     * นี้เพื่อคุมไม่ให้พังเงียบ ๆ ถ้ามีคนแก้ property ผิดในอนาคต
     */
    public function test_quick_view_renders_the_latest_update_and_attachment_count_without_crashing(): void
    {
        [$member, , $task] = $this->scenario();
        WorkOrderUpdate::create(['work_order_id' => $task->job_id, 'user_id' => $member->id, 'note' => 'อัปเดตงาน']);

        $this->actingAs($member)
            ->get(route('mytasks.quickview.task', ['id' => $task->job_id]))
            ->assertOk()
            ->assertSee('อัปเดต', false)
            ->assertDontSee('ยังไม่มีอัปเดต')
            ->assertSee('ไม่มีไฟล์แนบ');
    }

    public function test_milestone_query_is_whitelisted(): void
    {
        [$member, , $task] = $this->scenario();

        // ค่าที่ถูกต้องต้องไม่ทำให้ endpoint ล้มเหลว (popover ไม่ได้แยกแสดงผลตาม milestone อีกต่อไป
        // แต่ controller ยังต้องรับเฉพาะค่าที่อยู่ใน whitelist เท่านั้น)
        $this->actingAs($member)
            ->get(route('mytasks.quickview.task', ['id' => $task->job_id, 'milestone' => 'start']))
            ->assertOk();

        // ค่ามั่วต้องตกไปที่ค่าตั้งต้นอย่างเงียบ ๆ ไม่ใช่หลุดออกไปแสดงดิบ ๆ ในหน้า
        $this->actingAs($member)
            ->get(route('mytasks.quickview.task', ['id' => $task->job_id, 'milestone' => '<script>alert(1)</script>']))
            ->assertOk()
            ->assertDontSee('<script>alert', false);
    }

    public function test_attendee_can_open_meeting_quick_view(): void
    {
        $creator = $this->member('Creator');
        $attendee = $this->member('Attendee');
        $meeting = $this->meeting($creator, 'ประชุมทดสอบปฏิทิน');
        $meeting->attendees()->attach($attendee->id);

        $this->actingAs($attendee)
            ->get(route('mytasks.quickview.meeting', $meeting))
            ->assertOk()
            ->assertSee('data-quick-view-type="meeting"', false)
            ->assertSee('ประชุมทดสอบปฏิทิน')
            ->assertSee('ห้องประชุมชั้น 2')
            ->assertSee($creator->name)
            ->assertDontSee('data-quick-view-detail-url', false);
    }

    public function test_meeting_quick_view_uses_the_existing_gate(): void
    {
        $creator = $this->member('Creator');
        $stranger = $this->member('Stranger');
        $meeting = $this->meeting($creator, 'ประชุมส่วนตัว');

        $this->actingAs($stranger)
            ->get(route('mytasks.quickview.meeting', $meeting))
            ->assertForbidden();

        // admin และ viewer เห็นได้ตาม MeetingPolicy เดิม ไม่ได้ถูกทำให้เข้มหรือหลวมขึ้น
        $this->actingAs($this->member('Admin', 'admin'))
            ->get(route('mytasks.quickview.meeting', $meeting))->assertOk();
        $this->actingAs($this->member('Viewer', 'viewer'))
            ->get(route('mytasks.quickview.meeting', $meeting))->assertOk();
    }

    public function test_task_and_meeting_with_the_same_numeric_id_stay_separate(): void
    {
        [$member, , $task] = $this->scenario();
        $meeting = $this->meeting($member, 'ประชุมเลขชนกัน');

        // จับคู่ให้เลขเท่ากันเพื่อพิสูจน์ว่า endpoint คนละเส้นทางและคนละ entity
        $this->assertSame(1, $meeting->id);

        $taskHtml = $this->actingAs($member)->get(route('mytasks.quickview.task', ['id' => $task->job_id]))->assertOk()->getContent();
        $meetingHtml = $this->actingAs($member)->get(route('mytasks.quickview.meeting', $meeting))->assertOk()->getContent();

        $this->assertStringContainsString('data-quick-view-type="task"', $taskHtml);
        $this->assertStringContainsString('data-quick-view-type="meeting"', $meetingHtml);
        $this->assertStringNotContainsString('ประชุมเลขชนกัน', $taskHtml);
        $this->assertStringNotContainsString('งานทดสอบปฏิทิน', $meetingHtml);
    }

    public function test_quick_view_partials_are_read_only_and_add_no_ids(): void
    {
        [$member, , $task] = $this->scenario();
        $meeting = $this->meeting($member, 'ประชุมอ่านอย่างเดียว');

        $responses = [
            $this->actingAs($member)->get(route('mytasks.quickview.task', ['id' => $task->job_id]))->assertOk()->getContent(),
            $this->actingAs($member)->get(route('mytasks.quickview.meeting', $meeting))->assertOk()->getContent(),
        ];

        foreach ($responses as $html) {
            // Quick View เป็นแบบอ่านอย่างเดียว จึงต้องไม่มีฟอร์มไปซ้อนกับฟอร์มของหน้าเดิม
            $this->assertStringNotContainsString('<form', $html);
            $this->assertStringNotContainsString('<input', $html);
            $this->assertStringNotContainsString('<textarea', $html);
            // เนื้อหาที่ถูกใส่ซ้ำทุกครั้งที่เปิดต้องไม่พก id ติดมา ไม่งั้นเปิดหลายรอบจะได้ id ซ้ำ
            $this->assertStringNotContainsString('id="', $html);
        }
    }

    public function test_calendar_page_supplies_the_detail_url_template_for_each_context(): void
    {
        [$member, $admin] = $this->scenario();

        // หน้า User ต้องชี้กลับมาที่ /my-tasks พร้อมรักษา query เดิม
        $this->actingAs($member)
            ->get(route('mytasks.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertSee('data-task-detail-template="'.e(route('mytasks.index', ['view' => 'calendar', 'open_task' => '__ID__'])).'"', false)
            ->assertSee('data-task-quickview-template="'.e(route('mytasks.quickview.task', ['id' => '__ID__'])).'"', false)
            ->assertSee('data-quick-view-popover', false);

        // หน้า Admin ต้องอยู่หน้าเดิม ห้ามถูกพาไป /my-tasks
        $adminUrl = route('admin.work-board.member', [$this->department, $member]);
        $this->actingAs($admin)
            ->get($adminUrl)
            ->assertOk()
            ->assertSee('data-task-detail-template="'.e($adminUrl.'?open_task=__ID__').'"', false)
            ->assertDontSee('data-task-detail-template="'.e(route('mytasks.index')).'?open_task=__ID__"', false);
    }

    public function test_meeting_calendar_payload_carries_its_own_detail_and_quick_view_urls(): void
    {
        $member = $this->member('Member');
        $meeting = $this->meeting($member, 'ประชุมพร้อมลิงก์');

        $this->actingAs($member)
            ->getJson(route('mytasks.calendar.meetings', ['start' => '2026-08-01', 'end' => '2026-08-31']))
            ->assertOk()
            ->assertJsonPath('meetings.0.type', 'meeting')
            ->assertJsonPath('meetings.0.entityId', $meeting->id)
            ->assertJsonPath('meetings.0.quickViewUrl', route('mytasks.quickview.meeting', $meeting))
            ->assertJsonPath('meetings.0.detailUrl', route('meetings.show', $meeting));
    }

    /**
     * @return array{0: User, 1: User, 2: WorkOrder}
     */
    private function scenario(): array
    {
        $member = $this->member('Owner');
        $admin = $this->member('Admin', 'admin');
        $project = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'โปรเจกต์ปฏิทิน',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $this->department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'งานทดสอบปฏิทิน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $member->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$member, $admin, $task];
    }

    private function member(string $name, string $role = 'user'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'department_id' => $this->department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function meeting(User $creator, string $title): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'description' => 'รายละเอียดการประชุม',
            'starts_at' => CarbonImmutable::parse('2026-08-24 10:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-24 11:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ]);
    }
}
