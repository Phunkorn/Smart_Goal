<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Member Preview (offcanvas) แสดงเฉพาะ "งานที่ต้องจัดการวันนี้"
 *
 * สมาชิกภาพของรายการต้องมาจาก App\Support\TodayWorkspace::tasks() ตัวเดิม
 * Preview เพิ่มเพียงสองข้อจำกัดของตัวเอง: เอาเฉพาะงานที่อนุมัติแล้ว และตัดงานที่เสร็จแล้วออก
 * เพราะ Preview ตอบคำถามว่า "วันนี้ต้องทำอะไร" ไม่ใช่ประวัติงานทั้งหมด
 */
class AdminMemberPreviewTodayTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $admin;

    private User $member;

    private WorkOrderList $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-08-20 10:00:00'));
        $this->department = Department::create(['department_name' => 'Preview Today']);
        $this->admin = $this->user('admin', 'Admin');
        $this->member = $this->user('user', 'Member');
        $this->project = WorkOrderList::create([
            'user_id' => $this->admin->id,
            'name' => 'Preview Project',
            'priority' => 2,
        ]);
    }

    public function test_actionable_today_tasks_are_listed_and_finished_or_future_work_is_not(): void
    {
        $overdue = $this->task('งานล่าช้า', 6, now()->subWeek(), now()->subDays(2), ['late_at' => now()->subDay()]);
        $dueToday = $this->task('งานครบกำหนดวันนี้', 2, now()->subDays(3), now());
        $activeToday = $this->task('งานกำลังทำอยู่', 2, now()->subDay(), now()->addDays(3));
        $startsToday = $this->task('งานเริ่มวันนี้', 1, now(), now()->addDays(5));
        $paused = $this->task('งานพักไว้', 5, now()->subWeek(), now()->addWeek(), ['paused_at' => now()->subDay()]);

        $future = $this->task('งานอนาคต', 1, now()->addDay(), now()->addDays(4));
        $finished = $this->task('งานที่จบไปแล้ว', 4, now()->subWeeks(2), now()->subWeek(), ['job_completed_at' => now()->subWeek()]);
        $doneToday = $this->task('งานที่เพิ่งเสร็จวันนี้', 4, now()->subDays(3), now(), ['job_completed_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('งานที่ต้องจัดการวันนี้')
            ->assertSee('งานล่าช้า')
            ->assertSee('งานครบกำหนดวันนี้')
            ->assertSee('งานกำลังทำอยู่')
            ->assertSee('งานเริ่มวันนี้')
            // งานพักยังนับเป็น Today ตามนิยามเดิมของ TodayWorkspace
            ->assertSee('งานพักไว้')
            ->assertDontSee('งานอนาคต')
            ->assertDontSee('งานที่จบไปแล้ว')
            // Preview คือ actionable work งานที่เสร็จแล้วจึงถูกตัดออกแม้จะเสร็จวันนี้
            ->assertDontSee('งานที่เพิ่งเสร็จวันนี้');

        $this->assertSame(5, $this->previewTaskCount($response->getContent()));
        $this->assertNotNull($overdue->fresh());
        $this->assertNotNull($dueToday->fresh());
        $this->assertNotNull($activeToday->fresh());
        $this->assertNotNull($startsToday->fresh());
        $this->assertNotNull($paused->fresh());
        $this->assertNotNull($future->fresh());
        $this->assertNotNull($finished->fresh());
        $this->assertNotNull($doneToday->fresh());
    }

    public function test_overdue_task_still_flagged_todo_in_the_database_is_synchronised_and_listed(): void
    {
        // Preview ไม่เคยเรียก synchronizeLate() มาก่อน งานที่เลยกำหนดจึงยังเป็น status 1
        // แล้วตกเงื่อนไข isWithinActiveRange() ของ TodayWorkspace หายไปจากรายการทั้งที่ล่าช้าจริง
        $stale = $this->task('งานเลยกำหนดแต่ยังไม่ถูกตีเป็นล่าช้า', 1, now()->subWeek(), now()->subDays(2));
        $shouldStart = $this->task('งานถึงกำหนดเริ่มแล้ว', 1, now()->subDay(), now()->addDays(2));

        $this->actingAs($this->admin)
            ->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('งานเลยกำหนดแต่ยังไม่ถูกตีเป็นล่าช้า')
            ->assertSee('งานถึงกำหนดเริ่มแล้ว');

        $this->assertSame(6, (int) $stale->fresh()->job_status);
        $this->assertSame(2, (int) $shouldStart->fresh()->job_status);
    }

    public function test_tasks_are_ordered_overdue_then_due_today_then_active_then_starting_today(): void
    {
        // สร้างสลับลำดับเพื่อพิสูจน์ว่าเรียงจริง ไม่ใช่บังเอิญได้ลำดับการสร้าง
        $this->task('D เริ่มวันนี้', 1, now(), now()->addDays(6));
        $this->task('C กำลังทำอยู่', 2, now()->subDays(2), now()->addDays(2));
        $this->task('B ครบกำหนดวันนี้', 2, now()->subDays(3), now());
        $this->task('A2 ล่าช้าน้อยกว่า', 6, now()->subWeek(), now()->subDay(), ['late_at' => now()]);
        $this->task('A1 ล่าช้านานกว่า', 6, now()->subMonth(), now()->subDays(9), ['late_at' => now()]);

        $content = $this->actingAs($this->admin)
            ->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->getContent();

        $order = collect(['A1 ล่าช้านานกว่า', 'A2 ล่าช้าน้อยกว่า', 'B ครบกำหนดวันนี้', 'C กำลังทำอยู่', 'D เริ่มวันนี้'])
            ->map(fn (string $topic) => strpos($content, $topic));

        $order->each(fn ($position, $index) => $this->assertNotFalse($position, 'ไม่พบงานลำดับที่ '.$index));
        $this->assertSame($order->sort()->values()->all(), $order->values()->all(), 'ลำดับงานใน Preview ไม่ตรงกับกลุ่มความเร่งด่วน');
    }

    public function test_preview_never_leaks_unapproved_work_or_another_members_tasks(): void
    {
        $otherMember = $this->user('user', 'Other');
        $this->task('งานที่อนุมัติแล้ว', 2, now()->subDay(), now()->addDay());

        $pending = $this->task('งานรออนุมัติ', 1, now()->subDay(), now()->addDay());
        $pending->update(['approval_status' => 'pending', 'approved_by' => null, 'approved_at' => null]);

        $rejected = $this->task('งานที่ถูกปฏิเสธ', 1, now()->subDay(), now()->addDay());
        $rejected->update(['approval_status' => 'rejected', 'approved_by' => null, 'approved_at' => null]);

        $foreign = $this->task('งานของสมาชิกคนอื่น', 2, now()->subDay(), now()->addDay());
        $foreign->update(['user_id' => $otherMember->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('งานที่อนุมัติแล้ว')
            ->assertDontSee('งานรออนุมัติ')
            ->assertDontSee('งานที่ถูกปฏิเสธ')
            ->assertDontSee('งานของสมาชิกคนอื่น');

        $this->assertSame(1, $this->previewTaskCount($response->getContent()));
    }

    public function test_heading_count_matches_the_rendered_list(): void
    {
        $this->task('งานที่หนึ่ง', 2, now()->subDay(), now()->addDay());
        $this->task('งานที่สอง', 2, now()->subDay(), now());
        $this->task('งานที่เสร็จแล้ว', 4, now()->subDays(3), now(), ['job_completed_at' => now()]);

        $content = $this->actingAs($this->admin)
            ->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('2 งาน')
            ->getContent();

        // จำนวนที่หัวข้อต้องมาจากรายการ Today จริง ไม่ใช่จำนวนงานทั้งหมดของสมาชิก
        $this->assertSame(2, $this->previewTaskCount($content));
    }

    public function test_member_whose_work_is_all_finished_gets_the_clear_empty_state_and_keeps_the_cta(): void
    {
        $this->task('งานเก่าที่เสร็จแล้ว', 4, now()->subWeeks(2), now()->subWeek(), ['job_completed_at' => now()->subWeek()]);
        $this->task('งานอนาคต', 1, now()->addDays(2), now()->addDays(5));

        $this->actingAs($this->admin)
            ->get(route('admin.work-board.member.preview', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('data-preview-empty', false)
            ->assertSee('วันนี้ไม่มีงานที่ต้องติดตาม')
            ->assertDontSee('งานเก่าที่เสร็จแล้ว')
            ->assertDontSee('งานอนาคต')
            // CTA ต้องอยู่เสมอเพื่อให้ Admin เข้าไปดูงานทั้งหมดในพื้นที่งานเต็มได้
            ->assertSee('เปิดพื้นที่งานของสมาชิก')
            ->assertSee('href="'.route('admin.work-board.member', [$this->department, $this->member]).'"', false);
    }

    public function test_full_member_workspace_still_shows_finished_work(): void
    {
        $done = $this->task('งานที่เสร็จแล้วต้องยังอยู่ใน Workspace', 4, now()->subWeeks(2), now()->subWeek(), ['job_completed_at' => now()->subWeek()]);
        $this->task('งานที่ยังทำอยู่', 2, now()->subDay(), now()->addDay());

        $this->actingAs($this->admin)
            ->get(route('admin.work-board.member', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('งานที่เสร็จแล้วต้องยังอยู่ใน Workspace')
            ->assertSee('งานที่ยังทำอยู่')
            ->assertViewHas('completedTasks', fn ($tasks) => $tasks->contains('job_id', $done->job_id))
            ->assertViewHas('totals', ['projects' => 1, 'tasks' => 2]);
    }

    public function test_user_preview_uses_the_same_today_scope_and_stays_read_only(): void
    {
        $teammate = $this->user('user', 'Teammate');
        $this->task('งานวันนี้ของเพื่อนร่วมทีม', 2, now()->subDay(), now()->addDay());
        $this->task('งานที่เสร็จแล้ว', 4, now()->subDays(3), now(), ['job_completed_at' => now()]);

        $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->department, $this->member]))
            ->assertOk()
            ->assertSee('งานที่ต้องจัดการวันนี้')
            ->assertSee('งานวันนี้ของเพื่อนร่วมทีม')
            ->assertDontSee('งานที่เสร็จแล้ว')
            ->assertSee('data-preview-readonly', false)
            ->assertDontSee('data-preview-task-link', false)
            ->assertDontSee('เปิดพื้นที่งานของสมาชิก');
    }

    /** นับเฉพาะ attribute data-preview-task ตัวจริง ไม่ให้ไปตรงกับ -list / -link / -count / -timing */
    private function previewTaskCount(string $content): int
    {
        return preg_match_all('/data-preview-task(?![-\w])/', $content);
    }

    private function user(string $role, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'department_id' => $this->department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function task(string $topic, int $status, $start, $due, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $this->member->id,
            'created_by' => $this->admin->id,
            'assigned_by' => $this->admin->id,
            'leader_user_id' => $this->member->id,
            'department_id' => $this->department->id,
            'work_order_list_id' => $this->project->id,
            'job_topic' => $topic,
            'job_details' => 'รายละเอียดงาน',
            'job_priority' => 2,
            'job_status' => $status,
            'approval_status' => 'approved',
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'job_start_at' => $start,
            'job_due_at' => $due,
        ], $extra));
    }
}
