<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ช่องค้นหาบน Topbar (route('search') → GlobalSearchService)
 *
 * ผลค้นหาต้องอยู่ในขอบเขตเดียวกับที่ผู้ใช้เปิดดูได้จริงจากหน้าอื่น ไม่กว้างกว่า
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_search(): void
    {
        $this->getJson(route('search', ['q' => 'งาน']))->assertUnauthorized();
    }

    public function test_short_or_blank_queries_return_nothing_without_hitting_tasks(): void
    {
        $member = $this->member();
        $this->task($member, 'ก');

        foreach (['', ' ', 'ก'] as $query) {
            $this->actingAs($member)->getJson(route('search', ['q' => $query]))
                ->assertOk()
                ->assertExactJson([
                    'query' => trim($query),
                    'tasks' => [],
                    'projects' => [],
                    'more_tasks' => false,
                    'more_projects' => false,
                    'all_results_url' => null,
                ]);
        }
    }

    public function test_overlong_queries_are_rejected(): void
    {
        $this->actingAs($this->member())
            ->getJson(route('search', ['q' => str_repeat('ก', GlobalSearchService::MAX_LENGTH + 1)]))
            ->assertUnprocessable();
    }

    public function test_member_finds_own_tasks_by_title_details_and_project_name_in_thai_and_english(): void
    {
        $member = $this->member();
        $project = $this->project($member, 'Premium IT Stock');
        $byTitle = $this->task($member, 'ออกแบบหน้า Dashboard', $project);
        $byDetails = $this->task($member, 'งานทั่วไป', null, ['job_details' => 'ตรวจสอบ Bug หน้ารายงาน']);
        $byProject = $this->task($member, 'นับสต็อก', $project);

        $this->assertSame([$byTitle->job_id], $this->taskIds($member, 'dashboard'));
        $this->assertSame([$byTitle->job_id], $this->taskIds($member, 'ออกแบบ'));
        $this->assertSame([$byDetails->job_id], $this->taskIds($member, 'ตรวจสอบ bug'));
        $this->assertEqualsCanonicalizing([$byTitle->job_id, $byProject->job_id], $this->taskIds($member, 'premium it'));

        $result = $this->actingAs($member)->getJson(route('search', ['q' => 'ออกแบบ']))->assertOk()->json();
        $this->assertSame('ออกแบบหน้า Dashboard', $result['tasks'][0]['title']);
        $this->assertSame('Premium IT Stock', $result['tasks'][0]['project']);
        $this->assertSame(['key' => 'doing', 'label' => 'กำลังทำ', 'tone' => 'blue'], $result['tasks'][0]['status']);
        $this->assertSame(route('mytasks.index', ['open_task' => $byTitle->job_id]), $result['tasks'][0]['url']);
    }

    public function test_title_matches_rank_above_detail_matches(): void
    {
        $member = $this->member();
        $detail = $this->task($member, 'งานอื่น', null, ['job_details' => 'อ้างอิง Smart Goals']);
        $title = $this->task($member, 'Smart Goals Bug');
        // งานที่ตรงเฉพาะรายละเอียดถูกแก้ไขล่าสุด แต่ชื่องานที่ตรงยังต้องมาก่อน
        $detail->forceFill(['updated_at' => now()->addHour()])->saveQuietly();

        $this->assertSame([$title->job_id, $detail->job_id], $this->taskIds($member, 'smart goals'));
    }

    public function test_member_never_finds_tasks_or_projects_outside_their_scope(): void
    {
        $member = $this->member();
        $stranger = $this->member($this->department('Finance'));
        $privateProject = $this->project($stranger, 'Secret budget');
        $this->task($stranger, 'Secret salary review', $privateProject);
        $this->task($stranger, 'งานทั่วไป', null, ['job_details' => 'secret notes']);

        $this->actingAs($member)->getJson(route('search', ['q' => 'secret']))
            ->assertOk()
            ->assertJsonPath('tasks', [])
            ->assertJsonPath('projects', []);
    }

    public function test_accepted_collaborators_find_the_task_and_its_project(): void
    {
        $owner = $this->member();
        $helper = $this->member($this->department('Support'));
        $project = $this->project($owner, 'Joint launch');
        $task = $this->task($owner, 'Prepare launch deck', $project);
        $task->collaborators()->attach($helper->id, ['status' => 'accepted']);

        $this->assertSame([$task->job_id], $this->taskIds($helper, 'launch deck'));
        $this->assertSame([$project->id], array_column(
            $this->actingAs($helper)->getJson(route('search', ['q' => 'joint']))->json('projects'), 'id'));
    }

    public function test_unapproved_tasks_are_visible_to_the_requester_only(): void
    {
        $requester = $this->member();
        $assignee = $this->member($this->department('Ops'));
        $pending = $this->task($assignee, 'Pending cross team request', null, [
            'created_by' => $requester->id,
            'leader_user_id' => $requester->id,
            'approval_status' => 'pending',
        ]);

        $result = $this->actingAs($requester)->getJson(route('search', ['q' => 'pending cross']))->assertOk()->json();
        $this->assertSame([$pending->job_id], array_column($result['tasks'], 'id'));
        $this->assertNull($result['tasks'][0]['status'], 'งานที่ยังไม่อนุมัติไม่มีสถานะบนบอร์ด');

        $this->assertSame([], $this->taskIds($this->viewer(), 'pending cross'));
    }

    /**
     * viewer ไม่มีหน้ารายละเอียดงานรายใบ — ผลค้นหาพาไปหน้ารายละเอียดของรายงานโปรเจกต์ที่กรองไว้แล้ว
     * และหน้านั้นต้องแสดงงานที่ค้นเจอจริง ไม่ใช่ลิงก์ที่เปิดแล้วว่างเปล่า
     */
    public function test_viewer_reads_approved_work_and_lands_on_the_filtered_project_report(): void
    {
        $owner = $this->member();
        $project = $this->project($owner, 'Quarterly audit');
        $task = $this->task($owner, 'Collect audit evidence', $project);
        $viewer = $this->viewer();
        $today = now('Asia/Bangkok')->format('Y-m-d');

        $result = $this->actingAs($viewer)->getJson(route('search', ['q' => 'audit']))->assertOk()->json();

        $this->assertSame([$task->job_id], array_column($result['tasks'], 'id'));
        $this->assertSame(route('reports.projects.details', ['from' => $today, 'to' => $today, 'q' => 'Collect audit evidence']), $result['tasks'][0]['url']);
        $this->assertSame([$project->id], array_column($result['projects'], 'id'));
        $this->assertSame(route('reports.projects.details', [
            'from' => now('Asia/Bangkok')->subDays(365)->format('Y-m-d'),
            'to' => $today,
            'q' => 'Quarterly audit',
        ]), $result['projects'][0]['url']);

        foreach ([$result['tasks'][0]['url'], $result['projects'][0]['url']] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()->assertSee('Collect audit evidence');
        }
    }

    public function test_admin_finds_everything_and_opens_member_tasks_in_the_member_workspace(): void
    {
        $member = $this->member();
        $task = $this->task($member, 'Rotate firewall keys');

        $result = $this->actingAs($this->admin())->getJson(route('search', ['q' => 'firewall']))->assertOk()->json();

        $this->assertSame([$task->job_id], array_column($result['tasks'], 'id'));
        $this->assertSame(route('admin.work-board.member', [
            'department' => $member->department_id,
            'user' => $member->id,
            'open_task' => $task->job_id,
        ]), $result['tasks'][0]['url']);
    }

    public function test_department_head_opens_a_team_members_task_in_that_members_workspace(): void
    {
        $department = $this->department('Engineering');
        $head = $this->member($department, head: true);
        $member = $this->member($department);
        $task = $this->task($member, 'Upgrade build server');

        $result = $this->actingAs($head)->getJson(route('search', ['q' => 'build server']))->assertOk()->json();

        $this->assertSame([$task->job_id], array_column($result['tasks'], 'id'));
        $this->assertSame(route('work-board.member', [
            'department' => $department->id,
            'user' => $member->id,
            'workspace' => 1,
            'open_task' => $task->job_id,
        ]), $result['tasks'][0]['url']);
    }

    public function test_projects_link_to_the_filtered_task_table_and_archived_projects_are_left_out(): void
    {
        $member = $this->member();
        $live = $this->project($member, 'Website redesign');
        $archived = $this->project($member, 'Website legacy');
        $archived->forceFill(['archived_at' => now()])->save();
        $this->task($member, 'Old website copy', $archived);

        $result = $this->actingAs($member)->getJson(route('search', ['q' => 'website']))->assertOk()->json();

        $this->assertSame([$live->id], array_column($result['projects'], 'id'));
        $this->assertSame(route('mytasks.index', ['view' => 'board', 'search' => 'Website redesign']), $result['projects'][0]['url']);
        $this->assertSame([], $result['tasks'], 'งานในโปรเจกต์ที่เลื่อนออกไปแล้วเปิดจากบอร์ดไม่ได้');
    }

    public function test_like_wildcards_are_plain_characters(): void
    {
        $member = $this->member();
        $this->task($member, 'Ordinary task');
        $this->project($member, 'Ordinary project');

        foreach (['%%', '__', '%_'] as $wildcard) {
            $this->actingAs($member)->getJson(route('search', ['q' => $wildcard]))
                ->assertOk()
                ->assertJsonPath('tasks', [])
                ->assertJsonPath('projects', []);
        }
    }

    public function test_results_are_capped_and_report_that_more_exist(): void
    {
        $member = $this->member();
        foreach (range(1, GlobalSearchService::TASK_LIMIT + 2) as $index) {
            $this->task($member, 'Inventory count '.$index);
        }
        foreach (range(1, GlobalSearchService::PROJECT_LIMIT + 1) as $index) {
            $this->project($member, 'Inventory project '.$index);
        }

        $result = $this->actingAs($member)->getJson(route('search', ['q' => 'inventory']))->assertOk()->json();

        $this->assertCount(GlobalSearchService::TASK_LIMIT, $result['tasks']);
        $this->assertCount(GlobalSearchService::PROJECT_LIMIT, $result['projects']);
        $this->assertTrue($result['more_tasks']);
        $this->assertTrue($result['more_projects']);
        $this->assertSame(route('mytasks.index', ['view' => 'board', 'search' => 'inventory']), $result['all_results_url']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_results(): void
    {
        $member = $this->member();
        $project = $this->project($member, 'Bulk project');
        $helper = $this->member();
        foreach (range(1, GlobalSearchService::TASK_LIMIT) as $index) {
            $this->task($member, 'Bulk item '.$index, $project)->collaborators()->attach($helper->id, ['status' => 'accepted']);
        }

        $this->actingAs($member)->getJson(route('search', ['q' => 'zzz-nothing']))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($member)->getJson(route('search', ['q' => 'bulk item']))->assertOk()->assertJsonCount(GlobalSearchService::TASK_LIMIT, 'tasks');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // คิวรีหลักของงาน + ความสัมพันธ์ที่ eager-load + คิวรีของโปรเจกต์ ไม่ใช่หนึ่งชุดต่องาน
        $this->assertLessThan(GlobalSearchService::TASK_LIMIT + 4, $queries, "ใช้ {$queries} คิวรี");
    }

    public function test_the_json_carries_raw_text_for_the_client_to_render_as_text(): void
    {
        $member = $this->member();
        $this->task($member, '<img src=x onerror=alert(1)> payload');

        $this->actingAs($member)->getJson(route('search', ['q' => 'payload']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('tasks.0.title', '<img src=x onerror=alert(1)> payload');
    }

    private function taskIds(User $user, string $query): array
    {
        return array_column($this->actingAs($user)->getJson(route('search', ['q' => $query]))->assertOk()->json('tasks'), 'id');
    }

    private function department(string $name = 'Sales'): Department
    {
        return Department::create(['department_name' => $name.' '.uniqid()]);
    }

    private function member(?Department $department = null, bool $head = false): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => ($department ?? $this->department())->id,
            'is_department_head' => $head,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'must_change_password' => false, 'is_active' => true]);
    }

    private function project(User $owner, string $name): WorkOrderList
    {
        return WorkOrderList::create(['user_id' => $owner->id, 'name' => $name, 'is_visible' => true, 'sort_order' => 1]);
    }

    private function task(User $owner, string $topic, ?WorkOrderList $project = null, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'work_order_list_id' => $project?->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDays(3),
        ], $overrides));
    }
}
