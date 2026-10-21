<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\OperationalWorkloadReportService;
use App\Services\ProjectReportService;
use App\Support\ReportMetrics;
use App\Support\TodayWorkspace;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * กล่องรายละเอียดของการ์ดตัวเลข — รายงานโปรเจกต์และรายงานปฏิบัติงาน
 *
 * สามเรื่องที่ไฟล์นี้ตรึงไว้
 * 1. กล่องแสดงรายการชุดเดียวกับที่การ์ดนับ — จำนวนรวมในกล่องเท่ากับเลขบนการ์ดทุกใบ
 * 2. ขอบเขตและสิทธิ์ชุดเดียวกับหน้ารายงาน — แก้ URL ของกล่องแล้วไม่เห็นข้อมูลนอกขอบเขต
 * 3. งานข้ามแผนก — นับงานที่มีผู้ร่วมงานจากแผนกอื่นและงานที่ไปร่วมทำ โดยไม่นับซ้ำ
 */
class ReportKpiDetailTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $sales;

    private User $head;

    private User $itMember;

    private User $itPeer;

    private User $salesMember;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', ReportMetrics::BUSINESS_TIMEZONE));

        $this->it = Department::create(['department_name' => 'IT']);
        $this->sales = Department::create(['department_name' => 'Sales']);
        $this->head = $this->user($this->it, ['name' => 'หัวหน้าไอที', 'is_department_head' => true]);
        $this->itMember = $this->user($this->it, ['name' => 'พนักงานไอที']);
        $this->itPeer = $this->user($this->it, ['name' => 'เพื่อนไอที']);
        $this->salesMember = $this->user($this->sales, ['name' => 'พนักงานเซลส์']);
        $this->admin = $this->user(null, ['role' => 'admin', 'name' => 'ผู้ดูแลระบบ']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /* ---------------- รายงานโปรเจกต์: งานข้ามแผนก ---------------- */

    /**
     * สาเหตุที่ KPI งานข้ามแผนกเป็น 0 บนข้อมูลจริง
     *
     * งานของแผนกเราที่ชวนคนแผนกอื่นมาร่วม (ผู้ร่วมงานที่ตอบรับแล้ว) เป็นงานข้ามแผนกตามนิยามของระบบ
     * (ProjectRoles::joinsAcrossDepartments — การแจ้งเตือนและหน้าคำขออนุมัติ) แต่รายงานเคยดูแค่แผนก
     * ของผู้รับผิดชอบและผู้สร้าง จึงนับเป็น "ในแผนก" ทุกใบ
     */
    public function test_an_owned_task_with_an_accepted_collaborator_from_another_department_is_cross_department_work(): void
    {
        $shared = $this->task($this->itMember, 'ระบบ CRM ที่เซลส์มาร่วม');
        $this->collaborate($shared, $this->salesMember);
        // ผู้ร่วมงานแผนกเดียวกัน และคำเชิญข้ามแผนกที่ยังไม่ตอบรับ ไม่ทำให้เป็นงานข้ามแผนก
        $internal = $this->task($this->itMember, 'งานที่เพื่อนในแผนกช่วย');
        $this->collaborate($internal, $this->itPeer);
        $pending = $this->task($this->itMember, 'เชิญเซลส์แต่ยังไม่ตอบรับ');
        $this->collaborate($pending, $this->salesMember, 'pending');

        foreach ([
            [$this->admin, ['owner' => $this->itMember->id]],
            [$this->head, []],
            [$this->itMember, []],
        ] as [$viewer, $query]) {
            $page = $this->actingAs($viewer)->get(route('reports.projects', $query))->assertOk();
            $rows = $page->viewData('taskRows')->keyBy('topic');

            $this->assertSame(1, $this->kpi($page, 'cross'), 'ผู้ดู '.$viewer->name);
            $this->assertSame('cross', $rows['ระบบ CRM ที่เซลส์มาร่วม']['scope']['key']);
            $this->assertSame('มีผู้ร่วมงานจากแผนก Sales', $rows['ระบบ CRM ที่เซลส์มาร่วม']['scope']['detail']);
            $this->assertSame(['id' => $this->sales->id, 'name' => 'Sales'], $rows['ระบบ CRM ที่เซลส์มาร่วม']['scope']['department']);
            $this->assertSame('internal', $rows['งานที่เพื่อนในแผนกช่วย']['scope']['key']);
            $this->assertSame('internal', $rows['เชิญเซลส์แต่ยังไม่ตอบรับ']['scope']['key']);

            $detail = $this->detail($viewer, 'reports.projects.kpi', 'cross', $query);
            $this->assertSame(['ระบบ CRM ที่เซลส์มาร่วม'], array_column($detail['items'], 'topic'));
        }

        // ตัวกรอง "ข้ามแผนก · Sales" ของตารางเจองานใบเดียวกัน
        $filtered = $this->actingAs($this->head)->get(route('reports.projects', ['scope' => 'cross:'.$this->sales->id]))->assertOk();
        $this->assertSame(['ระบบ CRM ที่เซลส์มาร่วม'], $filtered->viewData('taskRows')->pluck('topic')->all());
    }

    /**
     * ภาพรวมแผนก: งานที่รับผิดชอบและงานที่ไปร่วมทำของแผนกอื่นนับรวมกัน งานหนึ่งใบนับครั้งเดียว
     * แม้ลูกทีมสองคนไปร่วมงานใบเดียวกัน และกล่องแสดงรายการที่ตรงกับตัวเลขบนการ์ด
     */
    public function test_the_cross_department_kpi_counts_owned_and_joined_work_once_and_matches_its_modal(): void
    {
        $this->task($this->itMember, 'เซลส์มอบงานให้ไอที', ['created_by' => $this->salesMember->id, 'department_id' => $this->sales->id]);
        $salesProject = $this->task($this->salesMember, 'งานเซลส์ที่ไอทีสองคนไปช่วย');
        $this->collaborate($salesProject, $this->itMember);
        $this->collaborate($salesProject, $this->itPeer);
        $this->task($this->itMember, 'งานในแผนก');
        // งานเดือนก่อนไม่อยู่ในเดือนที่เลือก
        $old = $this->task($this->salesMember, 'งานเซลส์เดือนก่อน', ['created_at' => '2026-07-02 09:00:00']);
        $this->collaborate($old, $this->itMember);

        $page = $this->actingAs($this->head)->get(route('reports.projects'))->assertOk();
        $detail = $this->detail($this->head, 'reports.projects.kpi', 'cross');

        $this->assertSame(2, $this->kpi($page, 'cross'));
        $this->assertSame(2, $detail['total']);
        $this->assertEqualsCanonicalizing(['เซลส์มอบงานให้ไอที', 'งานเซลส์ที่ไอทีสองคนไปช่วย'], array_column($detail['items'], 'topic'));
        $this->assertSame(count($detail['items']), count(array_unique(array_column($detail['items'], 'id'))));

        // เดือนก่อนเห็นเฉพาะงานของเดือนนั้น
        $july = $this->detail($this->head, 'reports.projects.kpi', 'cross', ['month' => '2026-07']);
        $this->assertSame(['งานเซลส์เดือนก่อน'], array_column($july['items'], 'topic'));
    }

    /* ---------------- รายงานโปรเจกต์: ทุกการ์ด ---------------- */

    public function test_every_project_kpi_modal_lists_exactly_what_its_card_counts(): void
    {
        $project = WorkOrderList::create(['user_id' => $this->itMember->id, 'name' => 'ระบบ CRM']);
        $parent = $this->task($this->itMember, 'ติดตั้ง CRM', ['work_order_list_id' => $project->id]);
        $this->task($this->itMember, 'สำรวจความต้องการ', ['parent_job_id' => $parent->job_id]);
        $this->task($this->itMember, 'ปิดงานแล้ว', ['job_status' => 4, 'job_completed_at' => '2026-08-15 09:00:00', 'work_order_list_id' => $project->id]);
        $this->task($this->itMember, 'เลยกำหนด', ['job_due_at' => '2026-08-20 17:00:00']);
        $this->task($this->itMember, 'เซลส์มอบงานให้ไอที', ['created_by' => $this->salesMember->id, 'department_id' => $this->sales->id]);
        $joined = $this->task($this->salesMember, 'งานเซลส์ที่ไอทีไปช่วย');
        $this->collaborate($joined, $this->itMember);

        $query = ['owner' => $this->itMember->id];
        $page = $this->actingAs($this->admin)->get(route('reports.projects', $query))->assertOk();
        $expected = [
            'projects' => ['ระบบ CRM'],
            'owned' => ['ติดตั้ง CRM', 'ปิดงานแล้ว', 'เลยกำหนด', 'เซลส์มอบงานให้ไอที'],
            'joined' => ['งานเซลส์ที่ไอทีไปช่วย'],
            'subtasks' => ['สำรวจความต้องการ'],
            'cross' => ['เซลส์มอบงานให้ไอที', 'งานเซลส์ที่ไอทีไปช่วย'],
            'active' => ['ติดตั้ง CRM', 'เลยกำหนด', 'เซลส์มอบงานให้ไอที'],
            'completed' => ['ปิดงานแล้ว'],
        ];

        $this->assertSame(array_keys(ProjectReportService::KPIS), array_keys($expected));

        foreach ($expected as $key => $names) {
            $detail = $this->detail($this->admin, 'reports.projects.kpi', $key, $query);
            $names = $key === 'projects' ? array_column($detail['items'], 'name') : array_column($detail['items'], 'topic');

            $this->assertSame($this->kpi($page, $key), $detail['total'], 'การ์ด '.$key);
            $this->assertEqualsCanonicalizing($expected[$key], $names, 'การ์ด '.$key);
        }

        $projects = $this->detail($this->admin, 'reports.projects.kpi', 'projects', $query)['items'][0];
        $this->assertSame(['total' => 2, 'done' => 1, 'late' => 0], array_intersect_key($projects, array_flip(['total', 'done', 'late'])));

        // การ์ดบนหน้าเป็นปุ่มที่พก URL ของกล่องพร้อม query ชุดเดียวกับหน้า
        $page->assertSee('data-kpi-url="'.e(route('reports.projects.kpi', ['kpi' => 'cross', 'owner' => $this->itMember->id, 'month' => '2026-08'])).'"', false)
            ->assertSee('data-kpi-modal', false);
    }

    public function test_project_kpi_modal_renders_states_links_and_pages(): void
    {
        foreach (range(1, 12) as $index) {
            $this->task($this->itMember, sprintf('งานที่ %02d', $index), ['created_at' => sprintf('2026-08-%02d 09:00:00', $index)]);
        }

        $first = $this->actingAs($this->itMember)->get(route('reports.projects.kpi', ['kpi' => 'owned']))->assertOk();
        $first->assertSee('data-kpi-detail', false)
            ->assertSee('data-kpi-total="12"', false)
            ->assertSee('แสดง 1–10 จาก 12 รายการ')
            ->assertSee('หน้า 1 / 2')
            ->assertSee('data-kpi-page-url="'.e(route('reports.projects.kpi', ['kpi' => 'owned', 'owner' => $this->itMember->id, 'month' => '2026-08', 'page' => 2])).'"', false)
            // HTML ชิ้นเดียว ไม่มี layout ของหน้า
            ->assertDontSee('<html', false)
            ->assertDontSee('sidebar-nav', false);
        $this->assertCount(10, $first->viewData('detail')['items']);

        $second = $this->actingAs($this->itMember)->get(route('reports.projects.kpi', ['kpi' => 'owned', 'page' => 2]))->assertOk();
        $this->assertCount(2, $second->viewData('detail')['items']);
        $second->assertSee('แสดง 11–12 จาก 12 รายการ');
        // หน้าที่เกินถูกดึงกลับมาหน้าสุดท้าย
        $this->assertSame(2, $this->actingAs($this->itMember)->get(route('reports.projects.kpi', ['kpi' => 'owned', 'page' => 99]))->viewData('detail')['page']);

        // ว่าง: ไม่มีงานที่ร่วมทำ
        $this->actingAs($this->itMember)->get(route('reports.projects.kpi', ['kpi' => 'joined']))->assertOk()
            ->assertSee('ไม่มีรายการในเดือนสิงหาคม 2569')
            ->assertDontSee('report-kpi-detail__list', false);

        $this->actingAs($this->itMember)->get('/reports/projects/kpis/unknown')->assertNotFound();
    }

    /**
     * ลิงก์ไปหน้างานออกตาม WorkOrderPolicy::view ของผู้ดูเท่านั้น
     *
     * บังคับปฏิเสธงานใบเดียวผ่าน Gate::before เพื่อพิสูจน์ว่าลิงก์ถามนโยบายจริง ไม่ได้ออกให้ทุกแถว
     */
    public function test_task_links_in_the_modal_follow_the_task_policy(): void
    {
        $open = $this->task($this->itMember, 'งานที่เปิดได้');
        $locked = $this->task($this->itMember, 'งานที่ถูกปฏิเสธ');
        Gate::before(fn (User $user, string $ability, array $arguments) => $ability === 'view'
            && ($arguments[0] ?? null) instanceof WorkOrder
            && (int) $arguments[0]->job_id === (int) $locked->job_id ? false : null);

        $response = $this->actingAs($this->head)->get(route('reports.projects.kpi', ['kpi' => 'owned']))->assertOk();
        $items = collect($response->viewData('detail')['items'])->keyBy('topic');

        $this->assertSame(route('tasks.show', $open->job_id), $items['งานที่เปิดได้']['url']);
        $this->assertNull($items['งานที่ถูกปฏิเสธ']['url']);
        $response->assertSee('href="'.route('tasks.show', $open->job_id).'"', false)
            ->assertDontSee('href="'.route('tasks.show', $locked->job_id).'"', false);
    }

    public function test_the_project_kpi_modal_keeps_the_report_scope_for_every_role(): void
    {
        $this->task($this->itMember, 'งานของพนักงานไอที');
        $this->task($this->itPeer, 'งานของเพื่อนไอที');
        $this->task($this->salesMember, 'งานของเซลส์');

        $topics = fn (User $viewer, array $query = []): array => array_column(
            $this->detail($viewer, 'reports.projects.kpi', 'owned', $query)['items'],
            'topic'
        );

        // หัวหน้าแผนกขอคนนอกแผนก หรือแผนกอื่น ได้ภาพรวมแผนกตัวเองแทน
        $this->assertEqualsCanonicalizing(['งานของพนักงานไอที', 'งานของเพื่อนไอที'], $topics($this->head, ['owner' => $this->salesMember->id]));
        $this->assertEqualsCanonicalizing(['งานของพนักงานไอที', 'งานของเพื่อนไอที'], $topics($this->head, ['department' => $this->sales->id]));
        // พนักงานทั่วไปเห็นเฉพาะของตัวเองแม้แก้ owner
        $this->assertSame(['งานของพนักงานไอที'], $topics($this->itMember, ['owner' => $this->salesMember->id]));
        // admin เลือกภาพรวมแผนกได้
        $this->assertSame(['งานของเซลส์'], $topics($this->admin, ['department' => $this->sales->id]));
        // viewer ดูรายงานโปรเจกต์ได้ จึงเปิดกล่องได้ในขอบเขตเดียวกัน
        $viewer = $this->user(null, ['role' => 'viewer']);
        $this->assertCount(3, $topics($viewer));

        auth()->logout();
        $this->get(route('reports.projects.kpi', ['kpi' => 'owned']))->assertRedirect(route('login'));
    }

    /* ---------------- รายงานปฏิบัติงาน ---------------- */

    public function test_every_operational_kpi_modal_lists_the_logs_behind_its_card(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', TodayWorkspace::BUSINESS_TIMEZONE));
        $this->log($this->itMember, ['title' => 'งานประจำกันยายน', 'work_date' => '2026-09-10', 'duration_minutes' => 60]);
        $this->log($this->itMember, ['title' => 'งานนอกสถานที่กันยายน', 'kind' => 'field', 'work_date' => '2026-09-11', 'duration_minutes' => 120]);
        $this->routineLog($this->itMember, ['title' => 'ตรวจเครื่องค้างไว้', 'work_date' => '2026-09-12', 'status' => 'open']);
        // คนอื่นและเดือนอื่นไม่เข้ามาในกล่อง
        $this->log($this->itPeer, ['title' => 'งานของเพื่อน', 'work_date' => '2026-09-10', 'duration_minutes' => 30]);
        $this->log($this->itMember, ['title' => 'งานสิงหาคม', 'work_date' => '2026-08-10', 'duration_minutes' => 30]);

        $query = ['month' => '2026-09'];
        $kpis = $this->actingAs($this->itMember)->get(route('reports.operational', $query))->assertOk()->viewData('kpis');
        $detail = fn (string $key): array => $this->detail($this->itMember, 'reports.operational.kpi', $key, $query);

        $this->assertSame(array_keys(OperationalWorkloadReportService::KPI_DESCRIPTIONS), array_keys($kpis));

        $hours = $detail('hours');
        $this->assertSame($kpis['hours']['value'], $hours['kpi']['value']);
        $this->assertEqualsCanonicalizing(['งานประจำกันยายน', 'งานนอกสถานที่กันยายน'], array_column($hours['items'], 'title'));

        $this->assertSame('2 ครั้ง', $kpis['routine']['value']);
        $this->assertSame(2, $detail('routine')['total']);
        $this->assertSame(['งานนอกสถานที่กันยายน'], array_column($detail('field')['items'], 'title'));

        // อัตราปิดรายการ 67% = ปิดแล้ว 2 จาก 3 รายการ ที่ยังไม่ปิดขึ้นก่อน
        $close = $detail('close_rate');
        $this->assertSame('67%', $close['kpi']['value']);
        $this->assertSame([2, 3], [$close['counted'], $close['total']]);
        $this->assertSame('ตรวจเครื่องค้างไว้', $close['items'][0]['title']);
        $this->assertSame([false, true, true], array_column($close['items'], 'counted'));

        $onTime = $detail('on_time');
        $this->assertSame([0, 1], [$onTime['counted'], $onTime['total']]);

        $pending = $detail('pending');
        $this->assertSame('1 รายการ', $kpis['pending']['value']);
        $this->assertSame(['ตรวจเครื่องค้างไว้'], array_column($pending['items'], 'title'));

        // รายการเปิดไทม์ไลน์บันทึกงานของวันนั้นผ่าน route เดิม
        $this->assertSame(
            route('daily-logs.index', ['user' => $this->itMember->id, 'date' => '2026-09-12']),
            $pending['items'][0]['url']
        );
        $this->actingAs($this->itMember)->get(route('reports.operational.kpi', ['kpi' => 'close_rate', ...$query]))
            ->assertOk()
            ->assertSee('ปิดแล้ว 2 จาก 3 รายการ')
            ->assertSee('ยังไม่ปิด');
    }

    public function test_the_operational_team_modal_counts_the_team_and_names_each_person(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', TodayWorkspace::BUSINESS_TIMEZONE));
        $this->log($this->itMember, ['title' => 'ซ่อมเครื่องสาขา', 'kind' => 'field', 'work_date' => '2026-09-10']);
        $this->log($this->itPeer, ['title' => 'เดินสายแลน', 'kind' => 'field', 'work_date' => '2026-09-11']);
        $this->log($this->salesMember, ['title' => 'งานของเซลส์', 'kind' => 'field', 'work_date' => '2026-09-11', 'department_id' => $this->sales->id]);

        $page = $this->actingAs($this->head)->get(route('reports.operational'))->assertOk()->assertViewIs('reports.operational.team');
        $detail = $this->detail($this->head, 'reports.operational.kpi', 'field');

        $this->assertSame('2 ครั้ง', $page->viewData('kpis')['field']['value']);
        $this->assertSame(2, $detail['total']);
        $this->assertTrue($detail['showPerson']);
        $this->assertEqualsCanonicalizing(['พนักงานไอที', 'เพื่อนไอที'], array_column($detail['items'], 'person'));
        $page->assertSee('data-kpi-url="'.e(route('reports.operational.kpi', ['kpi' => 'field', 'month' => '2026-09'])).'"', false);
    }

    public function test_the_operational_kpi_modal_keeps_the_report_scope_and_denies_viewers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', TodayWorkspace::BUSINESS_TIMEZONE));
        $this->log($this->itMember, ['title' => 'งานของพนักงานไอที', 'work_date' => '2026-09-10']);
        $this->log($this->itPeer, ['title' => 'งานของเพื่อนไอที', 'work_date' => '2026-09-10']);
        $this->log($this->salesMember, ['title' => 'งานของเซลส์', 'work_date' => '2026-09-10', 'department_id' => $this->sales->id]);

        $titles = fn (User $viewer, array $query = []): array => array_column(
            $this->detail($viewer, 'reports.operational.kpi', 'routine', $query)['items'],
            'title'
        );

        // พนักงานแก้ owner ไปหาคนอื่นไม่ได้
        $this->assertSame(['งานของพนักงานไอที'], $titles($this->itMember, ['owner' => $this->itPeer->id]));
        // หัวหน้าขอคนนอกแผนก ได้ภาพรวมแผนกตัวเอง
        $this->assertEqualsCanonicalizing(['งานของพนักงานไอที', 'งานของเพื่อนไอที'], $titles($this->head, ['owner' => $this->salesMember->id]));
        $this->assertSame(['งานของเพื่อนไอที'], $titles($this->head, ['owner' => $this->itPeer->id]));

        $viewer = $this->user(null, ['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('reports.operational.kpi', ['kpi' => 'routine']))->assertForbidden();
        $this->actingAs($this->itMember)->get('/reports/operational/kpis/unknown')->assertNotFound();
    }

    /* ---------------- helpers ---------------- */

    private function kpi(TestResponse $page, string $key): int
    {
        return collect($page->viewData('kpis'))->firstWhere('key', $key)['value'];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $viewer, string $route, string $kpi, array $query = []): array
    {
        return $this->actingAs($viewer)
            ->get(route($route, ['kpi' => $kpi, ...$query]))
            ->assertOk()
            ->viewData('detail');
    }

    private function user(?Department $department, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function task(User $assignee, string $topic, array $attributes = []): WorkOrder
    {
        $values = array_merge([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => '2026-08-01 09:00:00',
            'job_due_at' => '2026-08-31 17:00:00',
            'created_at' => '2026-08-10 09:00:00',
        ], $attributes);
        $createdAt = $values['created_at'];
        unset($values['created_at']);

        $job = WorkOrder::create($values);
        $job->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $job->refresh();
    }

    private function collaborate(WorkOrder $job, User $member, string $status = 'accepted'): void
    {
        DB::table('work_order_collaborators')->insert([
            'work_order_id' => $job->job_id,
            'user_id' => $member->id,
            'added_by' => $job->user_id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function log(User $owner, array $overrides = []): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $owner->department_id,
            'kind' => 'routine',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'งานปฏิบัติการ',
            'work_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
        ], $overrides));
    }

    private function routineLog(User $owner, array $overrides = []): WorkLog
    {
        $template = WorkLogTemplate::create([
            'user_id' => $owner->id,
            'title' => $overrides['title'] ?? 'งานประจำประจำวัน',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::EVERYDAY,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
            'is_active' => true,
        ]);

        return $this->log($owner, array_merge(['source' => 'template', 'work_log_template_id' => $template->id], $overrides));
    }
}
