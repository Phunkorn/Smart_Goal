<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTasksTaskScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_task_scopes_only_narrow_the_accessible_workspace(): void
    {
        $actor = $this->user();
        $assignee = $this->user();
        $other = $this->user();
        $list = $this->list($actor);

        $delegated = $this->task($assignee, $actor, $list, 'Delegated by actor');
        $selfAssigned = $this->task($actor, $actor, $list, 'Self assigned');
        $responsible = $this->task($actor, $other, $list, 'Assigned to actor');
        $createdButAssignedByOther = $this->task($assignee, $actor, $list, 'Created but assigned by other', [
            'assigned_by' => $other->id,
        ]);
        $legacyWithoutAssigner = $this->task($assignee, $actor, $list, 'Legacy without assigner', [
            'assigned_by' => null,
        ]);
        $collaborating = $this->task($other, $other, $list, 'Accepted collaboration');
        $collaborating->collaborators()->attach($actor->id, [
            'added_by' => $other->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $pending = $this->task($other, $other, $list, 'Pending collaboration');
        $pending->collaborators()->attach($actor->id, [
            'added_by' => $other->id,
            'status' => 'pending',
        ]);
        $unrelated = $this->task($assignee, $other, $this->list($other), 'Unrelated task');

        $expected = [
            'all' => [$delegated->job_id, $selfAssigned->job_id, $responsible->job_id, $createdButAssignedByOther->job_id, $legacyWithoutAssigner->job_id, $collaborating->job_id, $pending->job_id],
            'responsible' => [$selfAssigned->job_id, $responsible->job_id],
            'created' => [$delegated->job_id, $selfAssigned->job_id, $createdButAssignedByOther->job_id, $legacyWithoutAssigner->job_id],
            'assigned_by_me' => [$delegated->job_id],
            'collaborating' => [$collaborating->job_id],
        ];

        foreach ($expected as $scope => $taskIds) {
            $response = $this->actingAs($actor)
                ->get(route('mytasks.index', ['task_scope' => $scope]))
                ->assertOk()
                ->assertViewHas('taskScope', $scope);

            $this->assertEqualsCanonicalizing($taskIds, $this->workspaceTaskIds($response));

            // ปฏิทินต้องเห็นชุดเดียวกับตารางและบอร์ดเป๊ะ ๆ
            // เดิมปฏิทินใช้ชุดที่ยังไม่กรอง ผู้ใช้จึงกรองแล้วสลับไปปฏิทินแล้วเห็นงานทุกคนกลับมา
            $calendarIds = $response->viewData('calendarTasks')->pluck('job_id')->all();
            $this->assertEqualsCanonicalizing($taskIds, $calendarIds);
            $this->assertNotContains($unrelated->job_id, $calendarIds);
        }

        $invalid = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'someone_else', 'user_id' => $assignee->id]))
            ->assertOk()
            ->assertViewHas('taskScope', 'all');

        $this->assertEqualsCanonicalizing($expected['all'], $this->workspaceTaskIds($invalid));
        $this->assertEqualsCanonicalizing($expected['all'], $invalid->viewData('calendarTasks')->pluck('job_id')->all());
        $this->assertNotContains($unrelated->job_id, $invalid->viewData('calendarTasks')->pluck('job_id')->all());

        $this->actingAs($assignee)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertViewHas('activeTasks', fn ($tasks) => $tasks->contains('job_id', $delegated->job_id));
    }

    /** ตัวกรองต้องนำไปใช้กับทุกมุมมองรวมทั้งปฏิทิน ไม่ใช่แค่ตารางกับบอร์ด */
    public function test_assigned_scope_narrows_every_view_including_the_calendar(): void
    {
        $actor = $this->user();
        $assignee = $this->user();
        $list = $this->list($actor);
        $active = $this->task($assignee, $actor, $list, 'Active delegated');
        $completed = $this->task($assignee, $actor, $list, 'Completed delegated', [
            'job_status' => 4,
            'job_completed_at' => now(),
        ]);
        $calendarOnlyList = $this->list($actor);
        $selfAssigned = $this->task($actor, $actor, $calendarOnlyList, 'Calendar only self task');

        $response = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$active->job_id])
            ->assertViewHas('completedTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$completed->job_id])
            ->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->contains($active->job_id))
            ->assertViewHas('workspaceTaskLists', fn ($lists) => $lists->contains('id', $list->id)
                && ! $lists->contains('id', $calendarOnlyList->id))
            ->assertSee('data-completed-group', false);

        // งานที่ตัวเองรับผิดชอบต้องหลุดออกจากปฏิทินด้วย ไม่ใช่หลุดแค่ตารางกับบอร์ด
        $calendarIds = $response->viewData('calendarTasks')->pluck('job_id')->all();
        $this->assertContains($active->job_id, $calendarIds);
        $this->assertContains($completed->job_id, $calendarIds);
        $this->assertNotContains($selfAssigned->job_id, $calendarIds);
        $response->assertDontSee($selfAssigned->job_topic);

        // บรรทัดสรุปต้องบอกจำนวนงานที่กรองได้จริง ผู้ใช้จะได้รู้ว่าทำไมรายการสั้นลง
        $this->assertSame(2, $response->viewData('taskScopeCount'));
        $response->assertSee('data-task-scope-summary', false);
    }

    public function test_assigned_scope_reuses_viewer_specific_unread_badges_in_both_views(): void
    {
        $actor = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $actor, $this->list($actor), 'Delegated discussion');

        $this->actingAs($assignee)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Please review'])
            ->assertCreated();

        $response = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk();

        // บอร์ดและ Kanban ต้องบอกสถานะยังไม่ได้อ่านของผู้ชมคนเดียวกันตรงกัน
        $boardUnread = 'class="board-comments has-comments has-unread" data-open-task-modal data-task-id="'.$task->job_id.'"';
        $kanbanUnread = 'class="mytasks-kanban__comments" data-unread-comments="'.$task->job_id.'"';
        $this->assertStringContainsString($boardUnread, $response->getContent());
        $this->assertStringContainsString($kanbanUnread, $response->getContent());

        $this->actingAs($actor)->postJson(route('tasks.comments.read', $task))->assertOk();
        $content = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($boardUnread, $content);
        $this->assertStringNotContainsString($kanbanUnread, $content);
        // ช่องคอมเมนต์ของบอร์ดเป็นคอลัมน์หนึ่งของกริด จึงต้องคงอยู่พร้อมจำนวนรวมเดิม
        $this->assertStringContainsString('class="board-comments has-comments" data-open-task-modal data-task-id="'.$task->job_id.'"', $content);
    }

    /**
     * Admin ก็สร้างและมอบหมายงานเหมือนกัน จึงต้องกรองงานของตัวเองได้เท่าผู้ใช้ทั่วไป
     * เดิมถูกกันไว้ที่ role === 'user' ทำให้ Admin ไม่มีตัวกรองเลย
     */
    public function test_admin_gets_the_same_scope_filter_and_viewer_is_still_redirected(): void
    {
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertRedirect(route('board.index'));

        $admin = $this->user('admin');
        $adminResponse = $this->actingAs($admin)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->assertSee('data-task-scope-control', false);

        $this->assertSame('assigned_by_me', $adminResponse->viewData('taskScope'));
    }

    /**
     * หัวหน้าแผนกต้องเห็นงานของลูกทีมในบอร์ดของตัวเอง รวมงานที่ปิดไปแล้ว
     *
     * WorkOrderPolicy::view() อนุญาตให้หัวหน้าดูงานที่ปลายทางเป็นแผนกตนอยู่ก่อนแล้ว
     * แต่ scopeVisibleInProjectsFor() เคยคืนเฉพาะงานที่หัวหน้า "เกี่ยวข้องเอง"
     * หัวหน้าจึงเปิดบอร์ดแล้วไม่เห็นงานของลูกทีมเลย ทั้งที่ policy บอกว่าดูได้
     * ตอนนี้คิวรีกับ policy ตรงกันแล้ว และตัวกรอง "งานทั้งแผนก" จึงมีความหมายจริง
     */
    public function test_department_head_sees_team_work_including_what_is_already_finished(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user();
        $head->update(['department_id' => $department->id, 'is_department_head' => true]);
        $member = $this->user();
        $member->update(['department_id' => $department->id]);

        // โปรเจกต์ของลูกทีมเอง หัวหน้าไม่ได้สร้าง ไม่ได้รับผิดชอบ และไม่ได้เป็นผู้ร่วมงาน
        $teamProject = $this->list($member);
        $openTask = $this->task($member, $member, $teamProject, 'งานที่ลูกทีมยังทำอยู่');
        $openTask->update(['department_id' => $department->id]);
        $doneTask = $this->task($member, $member, $teamProject, 'งานที่ลูกทีมปิดไปแล้ว', [
            'job_status' => 4,
            'job_completed_at' => now()->subWeek(),
        ]);
        $doneTask->update(['department_id' => $department->id]);

        $head = $head->fresh();
        $response = $this->actingAs($head)->get(route('mytasks.index', ['view' => 'board']))->assertOk();

        // งานที่ปิดแล้วต้องอยู่ในกลุ่ม "งานที่เสร็จแล้ว" ของโปรเจกต์นั้นบนบอร์ด
        $this->assertTrue($response->viewData('completedTasks')->contains('job_id', $doneTask->job_id));
        $this->assertTrue($response->viewData('activeTasks')->contains('job_id', $openTask->job_id));
        $response->assertSee('board-completed-group', false)
            ->assertSee('งานที่ลูกทีมปิดไปแล้ว');

        // และโปรเจกต์ของลูกทีมต้องถูกดึงมาเป็นหัวกลุ่มด้วย ไม่เช่นนั้นงานจะไม่มีที่ให้วาง
        $this->assertTrue($response->viewData('workspaceTaskLists')->contains('id', $teamProject->id));
    }

    /**
     * ขอบเขต "ทั้งแผนก" เป็นของหัวหน้าแผนกเท่านั้น
     *
     * พนักงานทั่วไปเห็นเฉพาะงานที่ตนเกี่ยวข้อง ตัวเลือกนี้จึงไม่มีความหมายสำหรับเขา
     * และค่าที่ยัดมาเองทาง URL ต้องตกกลับเป็น all ไม่ใช่เปิดทางให้เห็นงานทั้งแผนก
     */
    public function test_department_scope_belongs_to_heads_and_cannot_be_forced_by_a_member(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user();
        $head->update(['department_id' => $department->id, 'is_department_head' => true]);
        $member = $this->user();
        $member->update(['department_id' => $department->id]);
        $teammate = $this->user();
        $teammate->update(['department_id' => $department->id]);

        $teamTask = $this->task($teammate, $teammate, $this->list($teammate), 'Team task');
        $teamTask->update(['department_id' => $department->id]);
        $ownTask = $this->task($member->fresh(), $member->fresh(), $this->list($member), 'Own task');

        // พนักงานทั่วไป: ไม่มีตัวเลือกนี้ และยัดค่ามาเองก็ต้องตกกลับเป็น all
        $memberResponse = $this->actingAs($member->fresh())
            ->get(route('mytasks.index', ['task_scope' => 'department']))
            ->assertOk()
            ->assertViewHas('taskScope', 'all');

        $this->assertNotContains('department', collect($memberResponse->viewData('taskScopeOptions'))->pluck('value')->all());
        $memberIds = $memberResponse->viewData('calendarTasks')->pluck('job_id')->all();
        $this->assertNotContains($teamTask->job_id, $memberIds, 'พนักงานทั่วไปต้องไม่เห็นงานของเพื่อนร่วมแผนก');
        $this->assertContains($ownTask->job_id, $memberIds);

        // หัวหน้าแผนก: มีตัวเลือกนี้ และกดแล้วได้งานของทั้งแผนกจริง
        $headResponse = $this->actingAs($head->fresh())
            ->get(route('mytasks.index', ['task_scope' => 'department']))
            ->assertOk()
            ->assertViewHas('taskScope', 'department')
            ->assertSee('งานทั้งแผนก');

        $this->assertContains('department', collect($headResponse->viewData('taskScopeOptions'))->pluck('value')->all());
        $this->assertContains($teamTask->job_id, $headResponse->viewData('calendarTasks')->pluck('job_id')->all());
    }

    /**
     * มุมมอง "ตาราง" ต้องแสดงงานทุกใบที่ตัวกรองบอกว่ามี
     *
     * ของเดิมตารางถูกกรองซ้ำด้วย TodayWorkspace::tasks() งานที่ยังไม่ถึงวันเริ่มจึงหายไปเงียบ ๆ
     * ผู้ใช้ที่เลือก "ฉันถูกชวนมาร่วมทำ" เห็นบรรทัดสรุปบอกว่ามี 1 งาน แต่ทุกคอลัมน์เป็น 0
     * ขณะที่มุมมองบอร์ดแสดงงานใบเดียวกันนั้นอยู่ ตัวเลขกับสิ่งที่เห็นจึงขัดกันเอง
     */
    public function test_table_view_lists_every_task_the_scope_summary_counts(): void
    {
        $actor = $this->user();
        $owner = $this->user();

        $futureTask = $this->task($owner, $owner, $this->list($owner), 'Starts tomorrow', [
            'job_start_at' => now()->addDay(),
            'job_due_at' => now()->addDays(2),
        ]);
        $futureTask->collaborators()->attach($actor->id, [
            'added_by' => $owner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $response = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'collaborating']))
            ->assertOk();

        $this->assertSame(1, $response->viewData('taskScopeCount'));
        $this->assertSame([$futureTask->job_id], $response->viewData('workspaceTasks')->pluck('job_id')->all());

        // งานยังไม่ถึงวันเริ่ม จึงไม่นับเป็นงานของ "วันนี้" แต่ต้องอยู่ในตารางอยู่ดี
        $this->assertEmpty($response->viewData('todayTasks')->pluck('job_id')->all());

        $html = $response->getContent();
        $tableStart = strpos($html, 'data-table-kanban');
        $tableHtml = substr($html, $tableStart, strpos($html, 'data-calendar', $tableStart) - $tableStart);
        $this->assertStringContainsString('data-id='.chr(34).$futureTask->job_id.chr(34), $tableHtml);
    }

    /**
     * คอลัมน์ "เสร็จแล้ว" ของมุมมองตารางเก็บเฉพาะงานที่ปิดวันนี้
     *
     * ตารางจัดคอลัมน์ตามสถานะ ช่อง "เสร็จแล้ว" จึงตอบคำถามว่า "วันนี้ปิดอะไรไปบ้าง"
     * ถ้าไม่ตัดตามวัน คอลัมน์นี้จะยาวขึ้นทุกวันจนกลบงานที่ยังต้องทำ
     * ประวัติงานที่ปิดแล้วไม่ได้หายไป มันอยู่ครบในกลุ่ม "งานที่เสร็จแล้ว" ของมุมมองบอร์ด
     */
    public function test_table_view_keeps_only_tasks_completed_today_in_the_done_column(): void
    {
        $actor = $this->user();
        $list = $this->list($actor);

        $open = $this->task($actor, $actor, $list, 'ยังทำอยู่');
        $closedToday = $this->task($actor, $actor, $list, 'ปิดวันนี้', [
            'job_status' => 4,
            'job_completed_at' => now(),
        ]);
        $closedYesterday = $this->task($actor, $actor, $list, 'ปิดเมื่อวาน', [
            'job_status' => 4,
            'job_completed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($actor)->get(route('mytasks.index'))->assertOk();

        $tableIds = $response->viewData('workspaceTasks')->pluck('job_id')->all();
        $this->assertEqualsCanonicalizing([$open->job_id, $closedToday->job_id], $tableIds);

        // งานที่ปิดไปแล้วยังอยู่ในบอร์ดและในตัวนับของตัวกรองครบเหมือนเดิม
        $this->assertSame(3, $response->viewData('taskScopeCount'));
        $this->assertTrue($response->viewData('completedTasks')->contains('job_id', $closedYesterday->job_id));
    }

    /**
     * ตัวกรอง "งานของวันนี้" ใช้กติกาวันทำงานไทยชุดเดียวกับ TodayWorkspace
     *
     * ผู้ใช้ขอตัวเลือกนี้เพราะ "งานทั้งหมด" แสดงทุกอย่างที่มีสิทธิ์เห็นรวมงานในโปรเจกต์ของคนอื่นด้วย
     * ซึ่งถูกต้องตามเงื่อนไข แต่ไม่ตอบคำถามว่า "วันนี้ต้องทำอะไร"
     */
    public function test_today_scope_narrows_the_workspace_to_the_business_day(): void
    {
        $actor = $this->user();
        $list = $this->list($actor);

        $activeToday = $this->task($actor, $actor, $list, 'อยู่ในช่วงวันนี้');
        $future = $this->task($actor, $actor, $list, 'ยังไม่ถึงวันเริ่ม', [
            'job_start_at' => now()->addDays(3),
            'job_due_at' => now()->addDays(5),
        ]);
        $closedYesterday = $this->task($actor, $actor, $list, 'ปิดเมื่อวาน', [
            'job_status' => 4,
            'job_completed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'today']))
            ->assertOk()
            ->assertViewHas('taskScope', 'today');

        $this->assertSame([$activeToday->job_id], $this->workspaceTaskIds($response));
        $this->assertSame(1, $response->viewData('taskScopeCount'));
        $this->assertNotContains($future->job_id, $response->viewData('calendarTasks')->pluck('job_id')->all());
        $this->assertNotContains($closedYesterday->job_id, $response->viewData('calendarTasks')->pluck('job_id')->all());

        // ตัวเลือกต้องมีอยู่จริงในเมนู ไม่ใช่รับได้แต่ค่าใน URL เท่านั้น
        $this->assertContains('today', collect($response->viewData('taskScopeOptions'))->pluck('value')->all());
        $response->assertSee('งานของวันนี้');
    }

    private function workspaceTaskIds($response): array
    {
        return $response->viewData('activeTasks')
            ->merge($response->viewData('completedTasks'))
            ->pluck('job_id')
            ->all();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function list(User $owner): WorkOrderList
    {
        return WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Scope project '.$owner->id.'-'.WorkOrderList::count(),
            'is_visible' => true,
            'sort_order' => WorkOrderList::count() + 1,
        ]);
    }

    private function task(User $assignee, User $creator, WorkOrderList $list, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'work_order_list_id' => $list->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ], $overrides));
    }
}
