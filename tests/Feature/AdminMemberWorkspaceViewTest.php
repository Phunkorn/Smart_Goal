<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin Member Workspace มี 4 มุมมอง (ตาราง / บอร์ด / ปฏิทิน / ประชุม)
 * โดยมุมมอง "ประชุม" ใช้ partial และ MeetingQueryService ชุดเดียวกับหน้า /meetings
 *
 * scope ของประชุมต้องมาจากสมาชิกที่ route ผูกไว้เท่านั้น ห้ามมาจาก `?employee=`
 * เพราะ Admin มองเห็นประชุมทั้งระบบผ่าน visibleQuery() อยู่แล้ว
 */
class AdminMemberWorkspaceViewTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Department $otherDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Technology']);
        $this->otherDepartment = Department::create(['department_name' => 'Finance']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_viewbar_offers_four_views_and_the_meeting_tab_navigates_instead_of_toggling(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertSee('notion-viewbar', false)
            ->assertSee('data-view="table"', false)
            ->assertSee('data-view="board"', false)
            ->assertSee('data-view="calendar"', false)
            ->assertSee('data-view="meeting"', false)
            ->assertSee('ประชุม')
            // panel ประชุมถูก render จาก server เท่านั้น ปุ่มจึงต้อง navigate
            ->assertSee('href="'.route('admin.work-board.member', [$this->department, $member, 'view' => 'meeting']).'"', false)
            ->assertSee('data-view-navigate', false)
            // หน้านี้อ่าน ?view= จริง จึงต้องประกาศให้ JS เขียน History ได้
            ->assertSee('data-view-history="true"', false);
    }

    public function test_default_view_is_still_the_today_table_and_unknown_views_fall_back_to_it(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();

        // ไม่มี ?view= ต้องได้ตารางเหมือนเดิม และ todayTasks ยังมาจาก TodayWorkspace ตามเดิม
        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertViewHas('workspaceView', 'table')
            ->assertSee('<section class="notion-database" data-view="table">', false)
            ->assertDontSee('mytasks-meeting-view', false);

        foreach (['', 'meetings', 'timeline', '../etc'] as $invalidView) {
            $this->actingAs($admin)
                ->get(route('admin.work-board.member', [$this->department, $member, 'view' => $invalidView]))
                ->assertOk()
                ->assertViewHas('workspaceView', 'table')
                ->assertDontSee('mytasks-meeting-view', false);
        }
    }

    public function test_each_requested_view_is_rendered_active_from_the_server(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();

        foreach (['table', 'board', 'calendar', 'meeting'] as $view) {
            $this->actingAs($admin)
                ->get(route('admin.work-board.member', [$this->department, $member, 'view' => $view]))
                ->assertOk()
                ->assertViewHas('workspaceView', $view)
                ->assertSee('<section class="notion-database" data-view="'.$view.'">', false);
        }
    }

    public function test_task_views_stay_scoped_to_the_member_regardless_of_the_selected_view(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Shared Project', 'priority' => 2]);
        $this->task($project, $admin, $member, 'งานของสมาชิกคนนี้');
        $this->task($project, $admin, $teammate, 'งานของเพื่อนร่วมทีม');

        foreach (['table', 'board', 'calendar'] as $view) {
            $this->actingAs($admin)
                ->get(route('admin.work-board.member', [$this->department, $member, 'view' => $view]))
                ->assertOk()
                ->assertSee('งานของสมาชิกคนนี้')
                ->assertDontSee('งานของเพื่อนร่วมทีม');
        }
    }

    public function test_task_views_use_only_primary_assignment_or_accepted_collaboration_and_deduplicate_membership(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();
        $external = User::factory()->create([
            'role' => 'user',
            'department_id' => $this->otherDepartment->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Direct Work', 'priority' => 2]);

        $primary = $this->task($project, $admin, $member, 'Primary assignment');
        $accepted = $this->task($project, $admin, $external, 'Accepted collaboration');
        $accepted->collaborators()->attach($member->id, ['added_by' => $admin->id, 'status' => 'accepted']);
        $duplicate = $this->task($project, $admin, $member, 'Primary and collaborator');
        $duplicate->collaborators()->attach($member->id, ['added_by' => $admin->id, 'status' => 'accepted']);

        foreach (['pending', 'rejected', 'removed'] as $status) {
            $excluded = $this->task($project, $admin, $external, ucfirst($status).' collaboration');
            $excluded->collaborators()->attach($member->id, ['added_by' => $admin->id, 'status' => $status]);
        }

        $this->task($project, $member, $teammate, 'Creator context only');
        $this->task($project, $admin, $teammate, 'Leader context only', ['leader_user_id' => $member->id]);
        $this->task($project, $admin, $teammate, 'Project sibling only');
        $pendingAssignment = $this->task($project, $admin, $member, 'Pending primary assignment', [
            'approval_status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertDontSee('data-kanban-column='.chr(34).'1'.chr(34), false)
            ->assertDontSee('data-modal-status-value='.chr(34).'1'.chr(34), false)
            ->assertDontSee('ยังไม่เริ่ม');

        $expected = collect([$primary, $accepted, $duplicate, $pendingAssignment])->pluck('job_id')->sort()->values()->all();
        $actual = $response->viewData('activeTasks')->pluck('job_id')->sort()->values()->all();
        $this->assertSame($expected, $actual);
        $this->assertSame(count($expected), $response->viewData('activeTasks')->unique('job_id')->count());

        $response
            ->assertSee('Primary assignment')
            ->assertSee('Accepted collaboration')
            ->assertSee('Primary and collaborator')
            ->assertSee('Pending primary assignment')
            ->assertDontSee('Creator context only')
            ->assertDontSee('Leader context only')
            ->assertDontSee('Project sibling only')
            ->assertDontSee('Pending collaboration')
            ->assertDontSee('Rejected collaboration')
            ->assertDontSee('Removed collaboration');
    }

    public function test_member_directory_counts_and_latest_activity_use_the_same_direct_workload_scope(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Directory Work', 'priority' => 2]);

        $primary = $this->task($project, $admin, $member, 'Directory primary');
        $primary->collaborators()->attach($member->id, ['added_by' => $admin->id, 'status' => 'accepted']);
        $accepted = $this->task($project, $admin, $teammate, 'Directory accepted');
        $accepted->collaborators()->attach($member->id, ['added_by' => $admin->id, 'status' => 'accepted']);
        $pendingPrimary = $this->task($project, $admin, $member, 'Directory pending primary', [
            'approval_status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ]);
        $pendingCollaborator = $this->task($project, $admin, $teammate, 'Directory pending collaborator');
        $pendingCollaborator->collaborators()->attach($member->id, ['added_by' => $admin->id, 'status' => 'pending']);
        $this->task($project, $member, $teammate, 'Directory creator context');

        $primary->update(['updated_at' => now()->subHour()]);
        $accepted->update(['updated_at' => now()]);

        $adminMember = $this->actingAs($admin)
            ->get(route('admin.work-board.department', $this->department))
            ->assertOk()
            ->viewData('members')
            ->firstWhere('id', $member->id);
        $this->assertSame(3, $adminMember->board_task_count);
        $this->assertTrue($adminMember->latest_activity_at->equalTo($accepted->fresh()->updated_at));

        $userMember = $this->actingAs($teammate)
            ->get(route('work-board.department', $this->department))
            ->assertOk()
            ->viewData('members')
            ->firstWhere('id', $member->id);
        $this->assertSame(2, $userMember->board_task_count);
        $this->assertNotNull($pendingPrimary);
    }

    public function test_meeting_view_shows_only_meetings_the_member_created_or_attends(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();

        $this->meeting($member, 'ประชุมที่สมาชิกสร้างเอง');
        $this->meeting($teammate, 'ประชุมที่สมาชิกเข้าร่วม')->attendees()->sync([$member->id]);
        $this->meeting($teammate, 'ประชุมของคนอื่นล้วน ๆ');
        // Admin เห็นประชุมทั้งระบบผ่าน visibleQuery() ประชุมของตัวเองจึงต้องถูกกรองออกด้วย
        $this->meeting($admin, 'ประชุมของผู้ดูแลเอง');

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'view' => 'meeting', 'period' => 'all']))
            ->assertOk()
            ->assertSee('mytasks-meeting-view', false)
            ->assertSee('การประชุมที่ '.$member->name.' เป็นผู้สร้างหรือผู้เข้าร่วม');

        $list = $this->meetingListSection($response->getContent());
        $this->assertStringContainsString('ประชุมที่สมาชิกสร้างเอง', $list);
        $this->assertStringContainsString('ประชุมที่สมาชิกเข้าร่วม', $list);
        $this->assertStringNotContainsString('ประชุมของคนอื่นล้วน ๆ', $list);
        $this->assertStringNotContainsString('ประชุมของผู้ดูแลเอง', $list);
    }

    public function test_crafted_employee_query_cannot_move_the_meeting_view_to_another_member(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();
        $this->meeting($member, 'ประชุมของสมาชิกในหน้านี้');
        $this->meeting($teammate, 'ประชุมของสมาชิกคนอื่น');

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [
                $this->department,
                $member,
                'view' => 'meeting',
                'period' => 'all',
                'employee' => $teammate->id,
            ]))
            ->assertOk();

        $list = $this->meetingListSection($response->getContent());
        $this->assertStringContainsString('ประชุมของสมาชิกในหน้านี้', $list);
        $this->assertStringNotContainsString('ประชุมของสมาชิกคนอื่น', $list);
    }

    public function test_inactive_member_meeting_view_never_widens_to_the_whole_organisation(): void
    {
        // route เปิดสมาชิกที่ปิดใช้งานได้ (ตรวจแค่แผนกกับ role) แต่ normalizeEmployeeId()
        // ตัดสมาชิกที่ is_active = false ทิ้ง ถ้าปล่อยให้ scope มาจาก ?employee= จะกลายเป็นเห็นทั้งระบบ
        $admin = $this->user('admin');
        $member = $this->user();
        $member->update(['is_active' => false]);
        $teammate = $this->user();
        $this->meeting($member, 'ประชุมของสมาชิกที่ปิดใช้งาน');
        $this->meeting($teammate, 'ประชุมที่ต้องไม่หลุดออกมา');

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'view' => 'meeting', 'period' => 'all']))
            ->assertOk();

        $list = $this->meetingListSection($response->getContent());
        $this->assertStringContainsString('ประชุมของสมาชิกที่ปิดใช้งาน', $list);
        $this->assertStringNotContainsString('ประชุมที่ต้องไม่หลุดออกมา', $list);
    }

    public function test_meeting_view_hides_the_employee_switcher_and_the_create_action(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $this->meeting($member, 'ประชุมของสมาชิก');

        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'view' => 'meeting', 'period' => 'all']))
            ->assertOk()
            // สลับไปดูคนอื่นจากในหน้าของสมาชิกไม่ได้
            ->assertDontSee('<select name="employee">', false)
            // สร้างประชุมจะ redirect ไป meetings.show ซึ่งพา Admin ออกจาก Member Workspace
            ->assertDontSee('data-meeting-create', false)
            ->assertDontSee('id="createMeetingModal"', false)
            ->assertDontSee('นัดประชุม');
    }

    public function test_meeting_filters_and_pagination_stay_inside_the_member_workspace(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        for ($index = 1; $index <= 25; $index++) {
            $this->meeting($member, 'ประชุมลำดับ '.$index);
        }

        $workspaceUrl = route('admin.work-board.member', [$this->department, $member]);
        $content = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'view' => 'meeting', 'period' => 'all']))
            ->assertOk()
            ->assertSee('action="'.$workspaceUrl.'"', false)
            ->assertSee('<input type="hidden" name="view" value="meeting">', false)
            ->assertSee('meetings-page__pagination', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#href="[^"]*/members/'.$member->id.'\?[^"]*view=meeting[^"]*page=2#',
            $content,
            'ลิงก์หน้าถัดไปต้องกลับมาที่ Member Workspace พร้อม view=meeting'
        );
        $this->assertStringNotContainsString('href="'.route('meetings.index').'?', $content);
    }

    public function test_meeting_search_never_escapes_the_member_scope(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();
        $this->meeting($member, 'ประชุมสรุปงาน');
        $this->meeting($teammate, 'ประชุมสรุปงบประมาณ');

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [
                $this->department,
                $member,
                'view' => 'meeting',
                'period' => 'all',
                'search' => 'ประชุมสรุป',
            ]))
            ->assertOk();

        $list = $this->meetingListSection($response->getContent());
        $this->assertStringContainsString('ประชุมสรุปงาน', $list);
        $this->assertStringNotContainsString('ประชุมสรุปงบประมาณ', $list);
    }

    public function test_non_admin_roles_can_never_reach_the_member_meeting_view(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $teammate = $this->user();
        $viewer = $this->user('viewer');
        $outsider = User::factory()->create([
            'role' => 'user',
            'department_id' => $this->otherDepartment->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $meetingUrl = route('admin.work-board.member', [$this->department, $member, 'view' => 'meeting']);

        $this->actingAs($teammate)->get($meetingUrl)->assertForbidden();
        $this->actingAs($member)->get($meetingUrl)->assertForbidden();
        $this->actingAs($viewer)->get($meetingUrl)->assertForbidden();

        // สมาชิกผิดแผนกต้องถูกปฏิเสธแม้ Admin จะแก้ URL เอง
        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $outsider, 'view' => 'meeting']))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $viewer, 'view' => 'meeting']))
            ->assertNotFound();
    }

    /**
     * Admin และหัวหน้าแผนกต้องมีตัวกรองขอบเขตงานตอนเปิดดูงานของสมาชิก
     *
     * ของเดิมตัวกรองมีเฉพาะหน้า "งานของฉัน" พอเข้ามาดูงานของ user ในแผนกตัวเองแล้วมันหายไป
     * ทั้งที่นี่คือหน้าที่ต้องแยกให้ออกที่สุดว่างานใบไหนสมาชิกรับผิดชอบเอง
     * และใบไหนเขาแค่ถูกชวนมาร่วมทำ เพราะ MemberWorkloadQuery::forMember() รวมทั้งสองแบบมาให้
     *
     * ถ้อยคำต้องพูดถึงสมาชิกคนนั้น ไม่ใช่ "ฉัน" มิฉะนั้นผู้ดูจะเข้าใจว่ากำลังกรองงานของตัวเอง
     */
    public function test_member_workspace_offers_a_subject_scoped_task_filter(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $owner = $this->user();
        $project = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'Scope project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $ownTask = $this->task($project, $member, $member, 'งานที่สมาชิกรับผิดชอบเอง');
        $joinedTask = $this->task($project, $owner, $owner, 'งานที่สมาชิกถูกชวนมาร่วม');
        $joinedTask->collaborators()->attach($member->id, [
            'added_by' => $owner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $unfiltered = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertSee('data-task-scope-control', false)
            ->assertSee('data-task-scope="all"', false)
            // ถ้อยคำอ้างถึงสมาชิกที่กำลังถูกดู ไม่ใช่ผู้ที่ล็อกอิน
            ->assertSee($member->name.' รับผิดชอบเอง')
            ->assertSee($member->name.' ถูกชวนมาร่วมทำ');

        $this->assertSame(2, $unfiltered->viewData('taskScopeCount'));

        $responsible = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'task_scope' => 'responsible']))
            ->assertOk()
            ->assertViewHas('taskScope', 'responsible');

        $this->assertSame(1, $responsible->viewData('taskScopeCount'));
        $this->assertSame(
            [$ownTask->job_id],
            $responsible->viewData('workspaceTasks')->pluck('job_id')->all()
        );

        $collaborating = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'task_scope' => 'collaborating']))
            ->assertOk();

        $this->assertSame(
            [$joinedTask->job_id],
            $collaborating->viewData('workspaceTasks')->pluck('job_id')->all()
        );

        // ค่าที่ยัดมาเองซึ่งหน้านี้ไม่มีให้เลือก ต้องตกกลับเป็น all ไม่ใช่คิวรีตามค่านั้น
        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->assertViewHas('taskScope', 'all');

        // หัวหน้าแผนกที่เปิดดูแบบอ่านอย่างเดียวต้องได้ตัวกรองชุดเดียวกัน
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();

        $this->actingAs($head)
            ->get(route('work-board.member', [$this->department, $member, 'workspace' => 1, 'task_scope' => 'responsible']))
            ->assertOk()
            ->assertSee('data-task-scope-control', false)
            ->assertViewHas('taskScope', 'responsible')
            ->assertViewHas('workspaceTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$ownTask->job_id]);
    }

    /**
     * "งานของวันนี้" ต้องใช้ได้ทั้งหน้า "งานของฉัน" และ Member Workspace
     *
     * เป็นคำถามหลักที่หน้าตรวจงานสมาชิกมีไว้ตอบ: ตอนนี้คนนี้กำลังทำอะไรอยู่
     * และคอลัมน์ "เสร็จแล้ว" ของตารางต้องตัดตามวันเหมือนกันทั้งสองหน้า
     */
    public function test_member_workspace_shares_the_today_scope_and_the_done_column_cutoff(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $project = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'Today project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $activeToday = $this->task($project, $member, $member, 'อยู่ในช่วงวันนี้');
        $future = $this->task($project, $member, $member, 'ยังไม่ถึงวันเริ่ม', [
            'job_start_at' => now()->addDays(3),
            'job_due_at' => now()->addDays(5),
        ]);
        $closedYesterday = $this->task($project, $member, $member, 'ปิดเมื่อวาน', [
            'job_status' => 4,
            'job_completed_at' => now()->subDay(),
        ]);

        // ตัวกรองปกติ: ตารางแสดงงานที่ยังไม่ปิดครบ แต่ไม่เก็บงานที่ปิดไปเมื่อวาน
        $all = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()
            ->assertSee('งานของวันนี้');

        $this->assertEqualsCanonicalizing(
            [$activeToday->job_id, $future->job_id],
            $all->viewData('workspaceTasks')->pluck('job_id')->all()
        );
        $this->assertSame(3, $all->viewData('taskScopeCount'), 'ตัวนับยังนับงานที่ปิดแล้วอยู่');

        $today = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member, 'task_scope' => 'today']))
            ->assertOk()
            ->assertViewHas('taskScope', 'today');

        $this->assertSame([$activeToday->job_id], $today->viewData('workspaceTasks')->pluck('job_id')->all());
        $this->assertSame(1, $today->viewData('taskScopeCount'));
        $this->assertNotContains($closedYesterday->job_id, $today->viewData('workspaceTasks')->pluck('job_id')->all());

        // หัวหน้าแผนกที่เปิดแบบอ่านอย่างเดียวต้องได้กติกาชุดเดียวกัน
        $head = $this->user();
        $head->forceFill(['is_department_head' => true])->save();

        $this->actingAs($head)
            ->get(route('work-board.member', [$this->department, $member, 'workspace' => 1, 'task_scope' => 'today']))
            ->assertOk()
            ->assertViewHas('workspaceTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$activeToday->job_id]);
    }

    private function meetingListSection(string $content): string
    {
        $start = strpos($content, 'meetings-page__list');
        $this->assertNotFalse($start, 'ไม่พบรายการประชุมในหน้า');
        $end = strpos($content, '</section>', $start);
        $this->assertNotFalse($end, 'รายการประชุมไม่ถูกปิดแท็ก');

        return substr($content, $start, $end - $start);
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $this->department->id,
            'must_change_password' => false,
            'is_active' => true,
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

    private function task(WorkOrderList $project, User $creator, User $assignee, string $topic, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_details' => 'รายละเอียดงาน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $creator->id,
            'approved_at' => now(),
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ], $extra));
    }
}
