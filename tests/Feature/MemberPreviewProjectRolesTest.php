<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\DepartmentTaskVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * แผง Preview สมาชิก — บทบาทในโปรเจกต์ และหมวด "สร้างวันนี้"
 *
 *   - บทบาท "หัวหน้าโปรเจกต์ / ผู้เข้าร่วม" มาจาก ProjectRoles เท่านั้น และแสดงกับทุกคนที่มีสิทธิ์
 *     ดูงานใบนั้น (WorkOrderPolicy::view) ไม่ว่าจะเป็น role หรือ visibility scope ใด
 *   - บทบาทในระบบ (หัวหน้าแผนก/พนักงาน) แสดงแยกในส่วนหัว ไม่ปนกับบทบาทในโปรเจกต์
 *   - "สร้างวันนี้" ใช้วันของเวลาไทย และงานหนึ่งใบอยู่ได้หมวดเดียว
 */
class MemberPreviewProjectRolesTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private User $head;

    private User $member;

    private User $collaborator;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 น. เวลาไทย
        $this->travelTo(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $this->it = Department::create(['department_name' => 'IT']);
        $this->head = $this->employee('หัวหน้าแผนก IT', head: true);
        $this->member = $this->employee('สมาชิกที่ถูกดู');
        $this->collaborator = $this->employee('ผู้ร่วมงานที่ถูกเชิญ');
    }

    public function test_every_viewer_who_may_view_the_task_sees_its_leader_and_participants(): void
    {
        $task = $this->task('งานที่หัวหน้ามอบหมาย', $this->head, ['created_at' => now()->subDays(3)]);
        $task->collaborators()->attach($this->collaborator->id, ['added_by' => $this->head->id, 'status' => 'accepted']);

        $grantee = $this->employee('ผู้ได้สิทธิ์ทั้งแผนก', scope: DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null, 'is_active' => true, 'must_change_password' => false]);

        $routes = [
            [$this->head, route('work-board.member', [$this->it, $this->member])],
            [$grantee, route('work-board.member', [$this->it, $this->member])],
            [$admin, route('admin.work-board.member.preview', [$this->it, $this->member])],
            // ผู้ร่วมงานเองก็มีสิทธิ์ดูงานใบนี้ จึงเห็นบทบาทเหมือนกัน แม้ไม่มีสิทธิ์พิเศษใด
            [$this->collaborator, route('work-board.member', [$this->it, $this->member])],
        ];

        foreach ($routes as [$viewer, $url]) {
            $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
            $card = $this->todaySection($html);

            $this->assertStringContainsString('data-preview-task-people', $card, $viewer->name);
            $this->assertMatchesRegularExpression('/data-preview-leader.*?<strong>หัวหน้าแผนก IT<\/strong>/s', $card, $viewer->name);
            $this->assertMatchesRegularExpression('/data-preview-participants="2"/', $card, $viewer->name);
            // สมาชิกที่ถูกดูเป็นผู้รับผิดชอบ ไม่ใช่ผู้สร้าง จึงเป็น "ผู้เข้าร่วม" ของงานนี้
            $this->assertStringContainsString('data-preview-member-role="participant"', $card, $viewer->name);
            $this->assertStringContainsString('data-preview-role-count="participant">1<', $html, $viewer->name);
            $this->assertStringContainsString('data-preview-role-count="leader">0<', $html, $viewer->name);
        }
    }

    public function test_the_member_who_created_the_task_is_its_project_leader(): void
    {
        $this->task('งานที่สมาชิกเปิดเอง', $this->member, ['created_at' => now()->subDay()]);

        $html = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-preview-member-role="leader"', $html);
        $this->assertStringContainsString('data-preview-role-count="leader">1<', $html);
        $this->assertStringContainsString('data-preview-role-count="participant">0<', $html);
        // ผู้สร้างที่มอบหมายให้ตัวเองเป็นหัวหน้าโปรเจกต์ ไม่ใช่ผู้เข้าร่วมของตัวเอง
        $this->assertStringContainsString('data-preview-participants="0"', $html);
    }

    /**
     * เพื่อนร่วมแผนกเห็นหัวข้องานวันนี้ได้ตามกติกาเดิมของ Preview แต่เปิดงานใบนั้นไม่ได้
     * รายชื่อทีมของงานจึงต้องไม่ถูกแสดง และตัวเลขสรุปบทบาทต้องไม่ถูกนับจากงานที่เขาเห็นไม่ได้
     */
    public function test_teammate_without_view_permission_sees_no_team_names(): void
    {
        $task = $this->task('งานวันนี้ของสมาชิก', $this->head, ['created_at' => now()->subDays(3)]);
        $task->collaborators()->attach($this->collaborator->id, ['added_by' => $this->head->id, 'status' => 'accepted']);
        $teammate = $this->employee('เพื่อนร่วมแผนก');

        $this->assertFalse($teammate->can('view', $task->fresh()));

        $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertSee('งานวันนี้ของสมาชิก')
            ->assertDontSee('data-preview-task-people', false)
            ->assertDontSee('ผู้ร่วมงานที่ถูกเชิญ')
            ->assertDontSee('data-preview-member-role', false)
            ->assertDontSee('data-preview-role-summary', false)
            // บทบาทในระบบของคนที่ถูกดูยังแสดงเหมือนเดิม — เป็นข้อมูลของคน ไม่ใช่ของงาน
            ->assertSee('wb-member-preview-panel__system-role', false);
    }

    public function test_header_separates_the_system_role_from_project_roles(): void
    {
        $this->task('งานใด ๆ', $this->member, ['created_at' => now()->subDay()]);

        $html = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->head]))
            ->assertOk()
            ->getContent();

        // บทบาทในระบบ • แผนก อยู่บรรทัดเดียวกันเป็นข้อความเรียบ ไม่ใช่ป้ายของบทบาทในโปรเจกต์
        $this->assertMatchesRegularExpression('/wb-member-preview-panel__system-role">หัวหน้าแผนก<\/span>\s*<span class="wb-member-preview-panel__separator" aria-hidden="true">•<\/span>/', $html);
        $this->assertStringContainsString('<template data-preview-header data-member-name="หัวหน้าแผนก IT">', $html);
        $this->assertStringContainsString('title="แผนก: IT">IT</span>', $html);
    }

    public function test_projects_created_today_are_listed_even_without_tasks(): void
    {
        $empty = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'โปรเจกต์ใหม่ยังว่าง']);
        $yesterday = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'โปรเจกต์เมื่อวาน']);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $archived = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'โปรเจกต์ที่จัดเก็บแล้ว']);
        $archived->forceFill(['archived_at' => now()])->save();

        $html = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();
        $created = $this->createdTodaySection($html);

        $this->assertStringContainsString('data-preview-created-project="'.$empty->id.'"', $created);
        $this->assertStringContainsString('ยังไม่มีรายการงาน', $created);
        $this->assertStringContainsString('สร้างเวลา 10:00 น.', $created, 'เวลาที่แสดงต้องเป็นเวลาไทย');
        $this->assertStringNotContainsString('โปรเจกต์เมื่อวาน', $created);
        $this->assertStringNotContainsString('โปรเจกต์ที่จัดเก็บแล้ว', $created);
        $this->assertStringContainsString('data-preview-created-count>1 รายการ<', $html);
    }

    /**
     * โปรเจกต์ที่ยังไม่มีงานเห็นได้เฉพาะคนที่เห็นงานทั้งแผนกของเจ้าของอยู่แล้ว
     * (WorkOrderListPolicy::view) — เพื่อนร่วมแผนกทั่วไปและหัวหน้าแผนกอื่นไม่เห็น
     */
    public function test_an_empty_project_is_hidden_from_viewers_without_permission(): void
    {
        $sales = Department::create(['department_name' => 'Sales']);
        $empty = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'โปรเจกต์ลับยังว่าง']);
        $teammate = $this->employee('เพื่อนร่วมแผนก');
        $grantee = $this->employee('ผู้ได้สิทธิ์ทั้งแผนก', scope: DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $salesHead = User::factory()->create(['role' => 'user', 'department_id' => $sales->id, 'is_department_head' => true, 'is_active' => true, 'must_change_password' => false]);

        $this->assertTrue($this->head->can('view', $empty));
        $this->assertTrue($grantee->can('view', $empty));
        $this->assertFalse($teammate->can('view', $empty));
        $this->assertFalse($salesHead->can('view', $empty));

        $this->actingAs($grantee)->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()->assertSee('โปรเจกต์ลับยังว่าง');
        foreach ([$teammate, $salesHead] as $viewer) {
            $this->actingAs($viewer)->get(route('work-board.member', [$this->it, $this->member]))
                ->assertOk()
                ->assertDontSee('โปรเจกต์ลับยังว่าง')
                ->assertSee('data-preview-created-empty', false);
        }

        // พอโปรเจกต์มีงานใบแรกในแผนกอื่น สิทธิ์กลับไปตัดสินจากงานตามเดิม
        $salesOwner = User::factory()->create(['role' => 'user', 'department_id' => $sales->id, 'is_active' => true, 'must_change_password' => false]);
        $this->task('งานแรกของแผนก Sales', $salesOwner, ['user_id' => $salesOwner->id, 'department_id' => $sales->id, 'work_order_list_id' => $empty->id]);
        $this->assertFalse($this->head->fresh()->can('view', $empty->fresh()));
    }

    public function test_a_task_created_today_that_starts_later_is_only_in_the_created_section(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'โปรเจกต์วันนี้']);
        $future = $this->task('งานเริ่มสัปดาห์หน้า', $this->member, [
            'work_order_list_id' => $project->id,
            'job_start_at' => now()->addWeek(),
            'job_due_at' => now()->addWeeks(2),
        ]);
        $startsToday = $this->task('งานสร้างและเริ่มวันนี้', $this->member, [
            'work_order_list_id' => $project->id,
            'job_start_at' => now(),
            'job_due_at' => now()->addDays(2),
        ]);
        $this->task('งานเก่าที่ยังทำอยู่', $this->member, ['created_at' => now()->subWeek()]);

        $html = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();
        $today = $this->todaySection($html);
        $created = $this->createdTodaySection($html);

        $this->assertStringNotContainsString('งานเริ่มสัปดาห์หน้า', $today, 'วันเริ่มในอนาคตต้องไม่กลายเป็นงานของวันนี้');
        $this->assertStringContainsString('งานเริ่มสัปดาห์หน้า', $created);
        $this->assertStringContainsString('data-preview-task-timing="created"', $created);

        // งานที่สร้างและต้องทำวันนี้อยู่หมวดวันนี้หมวดเดียว ไม่ซ้ำ
        $this->assertStringContainsString('งานสร้างและเริ่มวันนี้', $today);
        $this->assertStringNotContainsString('งานสร้างและเริ่มวันนี้', $created);
        $this->assertStringNotContainsString('งานเก่าที่ยังทำอยู่', $created);
        // "วันนี้" กับ "สร้างวันนี้" ไม่ซ้ำกัน ส่วน "งานทั้งหมด" เป็นภาพรวมทุกใบจึงมีงานนี้ด้วย
        $this->assertSame(1, substr_count($created, 'งานเริ่มสัปดาห์หน้า</h5>'));
        $this->assertSame(1, substr_count($this->panel($html, 'all'), 'งานเริ่มสัปดาห์หน้า</h5>'));

        // ตัวนับต้องตรงกับสิ่งที่วาด: วันนี้ 2 งาน / สร้างวันนี้ = 1 โปรเจกต์ + 1 งาน
        $this->assertStringContainsString('data-preview-task-count>2 งาน<', $html);
        $this->assertSame(2, preg_match_all('/data-preview-task(?![-\w])/', $html));
        $this->assertStringContainsString('data-preview-created-count>2 รายการ<', $html);
        $this->assertSame(1, preg_match_all('/data-preview-created-task(?![-\w])/', $html));
        // การ์ดโปรเจกต์นับเฉพาะงานที่แสดงในแผงนี้ (หนึ่งงานในวันนี้ + หนึ่งงานที่สร้างวันนี้)
        $this->assertMatchesRegularExpression('/data-preview-created-project="'.$project->id.'".*?data-preview-project-task-count>\s*2 งาน/s', $created);
        $this->assertNotNull($future->fresh());
        $this->assertNotNull($startsToday->fresh());
    }

    /**
     * "วันนี้" คือวันของเวลาไทย — 23:00 น. ของเมื่อวานตามเวลาไทยต้องไม่ถูกนับ ส่วน 00:30 น.
     * ของวันนี้ต้องถูกนับ แม้ทั้งสองจะเป็นวันเดียวกันในเวลา UTC
     */
    public function test_created_today_follows_the_bangkok_day_not_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 18:30:00', 'UTC')); // 01:30 น. ของ 15 ต.ค. เวลาไทย

        $lateYesterday = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'สร้างดึกเมื่อวาน']);
        $lateYesterday->forceFill(['created_at' => Carbon::parse('2026-10-14 16:00:00', 'UTC')])->save(); // 23:00 น. 14 ต.ค.
        $earlyToday = WorkOrderList::create(['user_id' => $this->member->id, 'name' => 'สร้างหลังเที่ยงคืน']);
        $earlyToday->forceFill(['created_at' => Carbon::parse('2026-10-14 17:30:00', 'UTC')])->save(); // 00:30 น. 15 ต.ค.

        $created = $this->createdTodaySection($this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('สร้างหลังเที่ยงคืน', $created);
        $this->assertStringContainsString('สร้างเวลา 00:30 น.', $created);
        $this->assertStringNotContainsString('สร้างดึกเมื่อวาน', $created);
    }

    public function test_empty_member_shows_both_empty_states(): void
    {
        $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertSee('data-preview-empty', false)
            ->assertSee('วันนี้ไม่มีงานที่ต้องติดตาม')
            ->assertSee('data-preview-created-empty', false)
            ->assertSee('วันนี้ยังไม่มีโปรเจกต์หรืองานใหม่')
            ->assertDontSee('data-preview-created-count', false)
            ->assertDontSee('data-preview-role-summary', false);
    }

    /** หัวข้อ สถานะ และกติกาของงานที่ยังไม่อนุมัติไม่เปลี่ยน — งานรออนุมัติไม่ขึ้นในหมวดใดเลย */
    public function test_pending_work_created_today_is_never_listed(): void
    {
        $this->task('งานรออนุมัติที่สร้างวันนี้', $this->member, [
            'approval_status' => 'pending',
            'job_start_at' => now()->addDays(3),
            'job_due_at' => now()->addDays(5),
        ]);

        $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertDontSee('งานรออนุมัติที่สร้างวันนี้');
    }

    /**
     * แท็บ "งานทั้งหมด" — งานทุกใบที่สมาชิกรับผิดชอบ ร่วมทำ หรือเปิดเอง รวมที่ปิดแล้ว
     * นับเฉพาะใบที่ผู้ดูมีสิทธิ์ดู (WorkOrderPolicy::view) เช่นเดียวกับตัวเลขสรุปและบทบาทในส่วนหัว
     */
    public function test_all_tab_lists_every_task_the_member_works_on_or_opened_within_view_permission(): void
    {
        $other = $this->employee('สมาชิกอีกคน');
        $this->task('งานล่าช้าของสมาชิก', $this->head, ['created_at' => now()->subWeek(), 'job_status' => 6, 'job_start_at' => now()->subWeek(), 'job_due_at' => now()->subDays(2), 'late_at' => now()->subDay()]);
        $this->task('งานกำลังทำวันนี้', $this->head, ['created_at' => now()->subWeek(), 'job_due_at' => now()->addDays(4)]);
        $nextMonth = $this->task('งานเริ่มเดือนหน้า', $this->head, ['created_at' => now()->subWeek(), 'job_start_at' => now()->addMonth(), 'job_due_at' => now()->addMonths(2)]);
        $this->task('งานที่สร้างวันนี้เริ่มพรุ่งนี้', $this->member, ['job_start_at' => now()->addDay(), 'job_due_at' => now()->addDays(3)]);
        $this->task('งานที่สมาชิกเปิดให้คนอื่นทำ', $this->member, ['created_at' => now()->subWeek(), 'user_id' => $other->id]);
        $this->task('งานที่ปิดแล้ว', $this->head, ['created_at' => now()->subWeek(), 'job_status' => 4, 'job_completed_at' => now()->subDay()]);
        $this->task('งานของคนอื่นที่ไม่เกี่ยวกับสมาชิก', $this->head, ['created_at' => now()->subWeek(), 'user_id' => $other->id]);

        $html = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();

        $all = $this->panel($html, 'all');
        $this->assertSame(6, preg_match_all('/data-preview-all-task(?![-\w])/', $all));
        $this->assertStringContainsString('งานที่สมาชิกเปิดให้คนอื่นทำ', $all, 'งานที่เปิดเองต้องอยู่ในงานทั้งหมด แม้ผู้รับผิดชอบเป็นคนอื่น');
        $this->assertStringContainsString('งานที่ปิดแล้ว', $all, 'งานทั้งหมดรวมงานที่ปิดแล้ว');
        $this->assertStringNotContainsString('งานของคนอื่นที่ไม่เกี่ยวกับสมาชิก', $all);
        // ล่าช้ามาก่อน งานที่ปิดแล้วไปท้ายสุด
        $this->assertTrue(strpos($all, 'งานล่าช้าของสมาชิก') < strpos($all, 'งานกำลังทำวันนี้'));
        $this->assertTrue(strpos($all, 'งานเริ่มเดือนหน้า') < strpos($all, 'งานที่ปิดแล้ว'));
        $this->assertStringContainsString('data-preview-task-timing="done"', $all);
        $this->assertMatchesRegularExpression('/data-preview-tab="all">\s*งานทั้งหมด <b>6<\/b>/', $html);
        $this->assertStringContainsString('data-preview-all-count>6 งาน<', $html);

        // งานที่เปิดเองแต่ไม่ได้รับผิดชอบ สมาชิกเป็นหัวหน้าโปรเจกต์ของงานนั้น
        $this->assertMatchesRegularExpression('/งานที่สมาชิกเปิดให้คนอื่นทำ<\/h5>.*?data-preview-member-role="leader"/s', $all);

        // ตัวเลขสรุปนับจากชุดเดียวกับแท็บงานทั้งหมด
        $this->assertStringContainsString('data-preview-summary-count="total">6<', $html);
        $this->assertStringContainsString('data-preview-summary-count="done">1<', $html);
        // บทบาทในส่วนหัว: งานที่ยังไม่ปิด 5 ใบ — สมาชิกเปิดเอง 2 ใบ หัวหน้าเปิด 3 ใบ
        $this->assertStringContainsString('data-preview-role-count="leader">2<', $html);
        $this->assertStringContainsString('data-preview-role-count="participant">3<', $html);

        // เพื่อนร่วมแผนกที่ไม่ได้อยู่ในงานใดเลย: งานทั้งหมดว่าง และไม่มีตัวเลขสรุป
        $teammate = $this->employee('เพื่อนร่วมแผนก');
        $teammateHtml = $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertDontSee('data-preview-summary', false)
            ->assertSee('data-preview-all-empty', false)
            ->getContent();
        $this->assertStringNotContainsString('งานที่สมาชิกเปิดให้คนอื่นทำ', $teammateHtml);

        // เมื่อถูกเชิญเข้างานหนึ่งใบ เห็นเฉพาะใบนั้น — ไม่ใช่งานทั้งหมดของสมาชิก
        $nextMonth->collaborators()->attach($teammate->id, ['added_by' => $this->head->id, 'status' => 'accepted']);
        $sharedHtml = $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();
        $sharedAll = $this->panel($sharedHtml, 'all');
        $this->assertSame(1, preg_match_all('/data-preview-all-task(?![-\w])/', $sharedAll));
        $this->assertStringContainsString('งานเริ่มเดือนหน้า', $sharedAll);
        $this->assertStringContainsString('data-preview-summary-count="total">1<', $sharedHtml);
    }

    /**
     * งานปฏิบัติวันนี้เป็นแท็บของตัวเอง ไม่ใช่หัวข้อท้ายแท็บวันนี้ที่ต้องเลื่อนลงไปหา
     * มีกับพนักงานทุกคนทุกแผนก (WorkLogPolicy::viewTodaySummary)
     */
    public function test_today_operations_live_in_their_own_tab_only_for_viewers_allowed_to_see_them(): void
    {
        $headHtml = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-preview-tab="operations">\s*งานปฏิบัติ <b>0<\/b>/', $headHtml);
        $this->assertMatchesRegularExpression('/data-preview-tabpanel="operations" hidden/', $headHtml);
        $operations = $this->panel($headHtml, 'operations');
        $this->assertStringContainsString('งานปฏิบัติวันนี้', $operations);
        $this->assertStringContainsString('data-preview-operations', $operations);
        $this->assertStringNotContainsString('data-preview-operations', $this->panel($headHtml, 'today'));
        // ลำดับแท็บ: วันนี้ → งานปฏิบัติ → สร้างวันนี้ → งานทั้งหมด
        $this->assertSeeInOrderString(['data-preview-tab="today"', 'data-preview-tab="operations"', 'data-preview-tab="created"', 'data-preview-tab="all"'], $headHtml);

        // พนักงานทั่วไปแผนกเดียวกันก็มีแท็บนี้ (ระดับภาพรวม — WorkLogPolicy::viewTodaySummary)
        $teammate = $this->employee('เพื่อนร่วมแผนก');
        $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertSee('data-preview-tab="operations"', false)
            ->assertSee('งานปฏิบัติวันนี้');

        // คนแผนกอื่นก็มีแท็บนี้เช่นกัน
        $sales = Department::create(['department_name' => 'Sales']);
        $outsider = User::factory()->create(['role' => 'user', 'department_id' => $sales->id, 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($outsider)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertSee('data-preview-tab="operations"', false)
            ->assertSee('งานปฏิบัติวันนี้');
    }

    /** สมาชิกที่มีงานหลายร้อยใบ: แท็บงานทั้งหมดวาดแค่ 50 ใบ แต่ตัวนับบอกจำนวนจริงและพาไปดูต่อในพื้นที่งาน */
    public function test_all_tab_draws_at_most_fifty_cards_but_counts_every_task(): void
    {
        foreach (range(1, 52) as $index) {
            $this->task('งานลำดับที่ '.$index, $this->head, ['created_at' => now()->subWeek(), 'job_due_at' => now()->addDays(2 + $index)]);
        }

        $html = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();
        $all = $this->panel($html, 'all');

        $this->assertSame(50, preg_match_all('/data-preview-all-task(?![-\w])/', $all));
        $this->assertMatchesRegularExpression('/data-preview-tab="all">\s*งานทั้งหมด <b>52<\/b>/', $html);
        $this->assertStringContainsString('แสดง 50 จาก 52 งาน', $all);
        $this->assertStringContainsString(e(route('work-board.member', [$this->it, $this->member, 'workspace' => 1])), $all);
        $this->assertStringContainsString('data-preview-summary-count="total">52<', $html);
    }

    /** @param  array<int, string>  $needles */
    private function assertSeeInOrderString(array $needles, string $haystack): void
    {
        $position = -1;
        foreach ($needles as $needle) {
            $found = strpos($haystack, $needle, $position + 1);
            $this->assertNotFalse($found, $needle);
            $this->assertGreaterThan($position, $found, $needle);
            $position = $found;
        }
    }

    public function test_tabs_start_on_today_and_the_workspace_button_lives_in_the_header_template(): void
    {
        $grantee = $this->employee('ผู้ได้สิทธิ์ทั้งแผนก', scope: DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $teammate = $this->employee('เพื่อนร่วมแผนก');

        $html = $this->actingAs($grantee)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*data-preview-tab="today"/', $html);
        $this->assertMatchesRegularExpression('/data-preview-tabpanel="all" hidden/', $html);
        $this->assertMatchesRegularExpression('/data-preview-tabpanel="created" hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-preview-tabpanel="today" hidden/', $html);

        $template = $this->between($html, '<template data-preview-header', '</template>');
        $this->assertStringContainsString('data-preview-header-action', $template);
        $this->assertStringContainsString('data-preview-workspace-link', $template);
        $this->assertStringContainsString('aria-label="เปิดพื้นที่งานของสมาชิก (ดูงานทั้งหมดแบบอ่านอย่างเดียว)"', $template);

        $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->assertDontSee('data-preview-header-action', false)
            ->assertDontSee('data-preview-workspace-link', false);
    }

    /**
     * การ์ดเป็นลิงก์เข้าพื้นที่งานเฉพาะผู้ที่เปิดพื้นที่งานได้ — ทุกแท็บ ไม่ใช่แค่แท็บวันนี้
     * แต่ละแท็บมี marker ของลิงก์แยกกัน data-preview-task-link จึงยังนับเฉพาะรายการวันนี้
     */
    public function test_task_links_follow_workspace_access_in_every_tab(): void
    {
        $this->task('งานวันนี้ที่ค้างอยู่', $this->member, ['created_at' => now()->subDay()]);
        $this->task('งานสร้างวันนี้เริ่มสัปดาห์หน้า', $this->member, ['job_start_at' => now()->addWeek(), 'job_due_at' => now()->addWeeks(2)]);
        $this->task('งานสร้างเมื่อวานเริ่มเดือนหน้า', $this->member, ['created_at' => now()->subDay(), 'job_start_at' => now()->addMonth(), 'job_due_at' => now()->addMonths(2)]);
        $workspaceUrl = route('work-board.member', [$this->it, $this->member, 'workspace' => 1]);

        $headHtml = $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($headHtml, 'data-preview-task-link'), 'รายการวันนี้มีงานเดียว');
        $this->assertSame(3, substr_count($headHtml, 'data-preview-all-task-link'), 'งานทั้งหมดสามใบเป็นลิงก์');
        $this->assertSame(1, substr_count($headHtml, 'data-preview-created-task-link'));
        $this->assertStringContainsString(e($workspaceUrl), $headHtml);

        $teammate = $this->employee('เพื่อนร่วมแผนก');
        $teammateHtml = $this->actingAs($teammate)
            ->get(route('work-board.member', [$this->it, $this->member]))
            ->assertOk()
            ->getContent();
        foreach (['data-preview-task-link', 'data-preview-all-task-link', 'data-preview-created-task-link'] as $marker) {
            $this->assertStringNotContainsString($marker, $teammateHtml);
        }
        $this->assertStringNotContainsString(e($workspaceUrl), $teammateHtml);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $end = strpos($html, $to, $start === false ? 0 : $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** HTML ของแผงแท็บเดียว — ตั้งแต่ data-preview-tabpanel ของแท็บนั้นถึงแผงถัดไป */
    private function panel(string $html, string $key): string
    {
        $start = strpos($html, 'data-preview-tabpanel="'.$key.'"');
        $this->assertNotFalse($start, 'ไม่พบแผง '.$key);
        $next = strpos($html, 'data-preview-tabpanel="', $start + 1);

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    private function todaySection(string $html): string
    {
        return $this->panel($html, 'today');
    }

    private function createdTodaySection(string $html): string
    {
        return $this->panel($html, 'created');
    }

    private function employee(string $name, bool $head = false, string $scope = DepartmentTaskVisibility::SCOPE_INVOLVED): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'user',
            'department_id' => $this->it->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
            'task_visibility_scope' => $scope,
        ])->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(string $topic, User $creator, array $overrides = []): WorkOrder
    {
        $attributes = array_merge([
            'user_id' => $this->member->id,
            'created_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'department_id' => $this->it->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDays(2),
        ], $overrides);
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $task = WorkOrder::create($attributes);

        if ($createdAt !== null) {
            $task->forceFill(['created_at' => $createdAt])->save();
        }

        return $task->fresh();
    }
}
