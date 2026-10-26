<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketParticipantService;
use App\Services\TicketReportService;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * รายงาน Smart Ticket (Phase 5) — /reports/tickets
 *
 * ทุกเทสต์ยิงผ่าน HTTP จริง แล้วอ่านตัวเลขจาก view data ที่หน้าใช้ render
 * เวลาเริ่มต้น 15 ต.ค. 2569 10:00 น. เวลากรุงเทพ (03:00 UTC)
 */
class TicketReportTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        config(['tickets.sla.response_minutes' => null, 'tickets.sla.resolution_hours' => null]);
    }

    private function report(User $viewer, array $query = []): TestResponse
    {
        return $this->actingAs($viewer)->get(route('reports.tickets', $query))->assertOk();
    }

    /** @return Collection<string, array<string, mixed>> */
    private function kpis(TestResponse $response): Collection
    {
        return collect($response->viewData('kpis'))->keyBy('key');
    }

    /** @return Collection<string, array<string, mixed>> */
    private function people(TestResponse $response): Collection
    {
        return collect($response->viewData('people')->items())->keyBy('key');
    }

    private function admin(): User
    {
        return $this->staff(null, 'admin');
    }

    private function join(Ticket $ticket, User $agent): void
    {
        app(TicketParticipantService::class)->join($ticket, $agent);
    }

    private function leave(Ticket $ticket, User $agent): void
    {
        app(TicketParticipantService::class)->leave($ticket, $agent);
    }

    /* ---------------------------------------------------------------
     * 1–5 ทีมนับ Ticket ละ 1 / รายบุคคลนับ Ticket ไม่ใช่กิจกรรม
     * ------------------------------------------------------------- */

    public function test_one_ticket_worked_by_three_agents_counts_once_for_the_team_and_once_for_each_person(): void
    {
        [$a, $b, $c] = [$this->agent(), $this->agent(), $this->agent()];
        $ticket = $this->startedBy($a);
        $this->join($ticket, $b);
        $this->join($ticket, $c);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $b, ['note' => 'แก้ไขแล้ว']);

        $response = $this->report($this->admin());

        $this->assertSame(1, $response->viewData('total'));
        $this->assertSame('1', $this->kpis($response)['total']['value']);
        $this->assertSame('1', $this->kpis($response)['closed']['value']);
        $this->assertSame(1, array_sum(array_column($response->viewData('byDepartment'), 'count')));
        $this->assertSame(1, array_sum(array_column($response->viewData('byLocation'), 'count')));
        $this->assertSame(1, array_sum($response->viewData('trend')['values']));

        $people = $this->people($response);
        foreach ([$a, $b, $c] as $agent) {
            $this->assertSame(1, $people['user:'.$agent->id]['participated'], $agent->name);
            $this->assertSame(0, $people['user:'.$agent->id]['still_involved']);
        }
        $this->assertSame(1, $people['user:'.$b->id]['closed'], 'ผู้ปิดงาน');
        $this->assertSame(0, $people['user:'.$a->id]['closed']);
        $this->assertSame(0, $people['user:'.$c->id]['closed']);
    }

    public function test_joining_twice_or_leaving_and_rejoining_never_adds_a_ticket(): void
    {
        $lead = $this->agent();
        $helper = $this->agent();
        $ticket = $this->startedBy($lead);

        $this->join($ticket, $helper);
        $this->join($ticket, $helper);
        $this->leave($ticket, $helper);
        $this->join($ticket, $helper);
        $this->leave($ticket, $helper);
        $this->join($ticket, $helper);

        $people = $this->people($this->report($this->admin()));

        $this->assertSame(1, $people['user:'.$helper->id]['participated']);
        $this->assertSame(1, $people['user:'.$helper->id]['still_involved']);
    }

    public function test_activities_are_not_counted_as_tickets(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);
        foreach (range(1, 3) as $round) {
            $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่ '.$round]);
            $this->transition($ticket, Ticket::ACTION_RESUME, $agent);
        }
        $this->assertGreaterThan(5, $ticket->activities()->count());

        $response = $this->report($this->admin());

        $this->assertSame(1, $response->viewData('total'));
        $this->assertSame(1, $this->people($response)['user:'.$agent->id]['participated']);
    }

    public function test_reopening_does_not_change_the_team_total(): void
    {
        $agent = $this->agent();
        $ticket = $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->transition($ticket, Ticket::ACTION_REOPEN, $agent, ['note' => 'ยังเสียอยู่']);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบจริง']);
        $this->transition($ticket, Ticket::ACTION_REOPEN, $agent, ['note' => 'อีกรอบ']);

        $response = $this->report($this->admin());
        $kpis = $this->kpis($response);

        $this->assertSame(1, $response->viewData('total'));
        $this->assertSame('1', $kpis['active']['value'], 'เปิดใหม่แล้วยังไม่ปิด = กำลังดำเนินการ');
        $this->assertSame('0', $kpis['closed']['value']);
        $this->assertSame(1, collect($response->viewData('watch'))->firstWhere('key', 'reopened')['count']);
        $this->assertSame(1, $this->people($response)['user:'.$agent->id]['participated']);
    }

    /* ---------------------------------------------------------------
     * 6 เวลาตอบรับ / เวลาแก้ไขหลังเปิดงานใหม่
     * ------------------------------------------------------------- */

    public function test_resolution_time_after_a_reopen_runs_from_creation_to_the_latest_close(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();

        $this->travel(60)->minutes();
        $this->startedBy($agent, $ticket);
        $this->travel(60)->minutes();
        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->travel(60)->minutes();
        $this->transition($ticket, Ticket::ACTION_REOPEN, $agent, ['note' => 'ยังเสีย']);

        // เปิดใหม่แล้วยังไม่ปิด → ไม่อยู่ในค่าเฉลี่ยเวลาแก้ไข (ไม่ใช่ 0 และไม่ใช่เวลาปิดครั้งแรก)
        $reopened = $this->report($this->admin());
        $this->assertNull($reopened->viewData('durations')['resolution']['average']);
        $this->assertSame('—', $this->kpis($reopened)['resolution']['value']);
        $this->assertSame('1 ชม.', $this->kpis($reopened)['response']['value'], 'เวลาตอบรับไม่เปลี่ยนเมื่อเปิดใหม่');

        $this->travel(120)->minutes();
        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบจริง']);

        $closed = $this->report($this->admin());
        $this->assertEqualsWithDelta(300, $closed->viewData('durations')['resolution']['average'], 0.01);
        $this->assertSame('5 ชม.', $this->kpis($closed)['resolution']['value']);
        $this->assertSame('1 ชม.', $this->kpis($closed)['response']['value']);
    }

    public function test_tickets_without_a_response_are_excluded_from_the_average_not_counted_as_zero(): void
    {
        $agent = $this->agent();
        $this->publicTicket('ยังไม่มีใครรับ');
        $answered = $this->publicTicket();
        $this->travel(30)->minutes();
        $this->startedBy($agent, $answered);

        $response = $this->report($this->admin());

        $this->assertSame(['average' => 30.0, 'count' => 1], $response->viewData('durations')['response']);
        $this->assertSame('30 นาที', $this->kpis($response)['response']['value']);
    }

    /* ---------------------------------------------------------------
     * 7–10 snapshot และตัวตนทางประวัติ
     * ------------------------------------------------------------- */

    public function test_department_and_location_renames_do_not_rewrite_the_report(): void
    {
        $sales = $this->department('Sales');
        $branch = $this->location('สาขาบางนา');
        app(TicketSubmissionService::class)->submitInternal($this->staff($sales), ['description' => 'จอดับ', 'location_id' => $branch->id]);

        $sales->update(['department_name' => 'Marketing']);
        $branch->update(['name' => 'สาขาเทพารักษ์']);

        $response = $this->report($this->admin());

        $this->assertSame(['Sales'], array_column($response->viewData('byDepartment'), 'label'));
        $this->assertSame(['สาขาบางนา'], array_column($response->viewData('byLocation'), 'label'));
        $this->assertSame(['Sales'], $response->viewData('chartData')['departments']['labels']);
        $response->assertDontSee('สาขาเทพารักษ์ (', false);

        // ตัวกรองยังใช้ id เดิม — ชื่อใหม่ในตัวเลือก แต่ Ticket เดิมยังนับ
        $filtered = $this->report($this->admin(), ['department' => $sales->id, 'location' => $branch->id]);
        $this->assertSame(1, $filtered->viewData('total'));
    }

    public function test_a_force_deleted_agent_stays_one_person_with_their_closes_across_tickets(): void
    {
        $gone = $this->agent();
        $name = $gone->name;
        $other = $this->agent();
        $first = $this->transition($this->startedBy($gone), Ticket::ACTION_CLOSE, $gone, ['note' => 'จบ']);
        $second = $this->startedBy($gone);
        $this->join($second, $other);
        $this->transition($second, Ticket::ACTION_CLOSE, $other, ['note' => 'จบ']);
        $third = $this->startedBy($gone);

        $gone->forceDelete();
        $this->assertNull($first->fresh()->closed_by, 'FK ถูกล้างตามเดิม');

        $response = $this->report($this->admin());
        $people = $this->people($response);
        $row = $people['user:'.$gone->id];

        $this->assertSame(3, $response->viewData('total'));
        $this->assertSame($name, $row['name']);
        $this->assertSame('บัญชีถูกลบถาวร', $row['state']);
        $this->assertSame(3, $row['participated'], 'สามแถวการเข้าร่วม = คนเดียว ไม่ใช่สามคน');
        $this->assertSame(1, $row['closed'], 'ผู้ปิดยังระบุได้จาก closed_by_ref_id');
        $this->assertSame(1, $row['still_involved']);
        $this->assertSame(1, $people['user:'.$other->id]['closed']);
        $this->assertCount(0, $people->filter(fn (array $row, string $key) => str_starts_with($key, 'participant:')));
        $response->assertSee($name);

        // เลือกดูคนที่ลบถาวรแล้วจากตัวกรองได้
        $filtered = $this->report($this->admin(), ['agent' => $gone->id]);
        $this->assertSame(3, $filtered->viewData('total'));
        $this->assertSame(['user:'.$gone->id], $this->people($filtered)->keys()->all());
        $this->assertSame([$third->id, $second->id, $first->id], collect($filtered->viewData('tickets')->items())->pluck('id')->all());
    }

    public function test_history_deleted_before_identity_refs_existed_is_shown_per_row_and_never_guessed(): void
    {
        $legacy = $this->agent();
        $legacy->update(['name' => 'สมศรี มีชัย']);
        $ticketA = $this->transition($this->startedBy($legacy), Ticket::ACTION_CLOSE, $legacy, ['note' => 'จบ']);
        $ticketB = $this->startedBy($legacy);

        // ข้อมูลก่อน migration 2026_10_23_000008: ยังไม่มี ref แล้วบัญชีถูกลบถาวร
        DB::table('ticket_participants')->update(['user_ref_id' => null]);
        DB::table('ticket_activities')->update(['user_ref_id' => null]);
        DB::table('tickets')->update(['closed_by_ref_id' => null]);
        $legacy->forceDelete();

        $rows = $this->people($this->report($this->admin()))->where('name', 'สมศรี มีชัย');

        $this->assertCount(2, $rows, 'ไม่รวมด้วยชื่อ');
        $this->assertTrue($rows->every(fn (array $row) => $row['participated'] === 1 && $row['closed'] === null));
        $this->assertSame(
            ['participant:'.$ticketA->participants()->value('id'), 'participant:'.$ticketB->participants()->value('id')],
            $rows->keys()->sort()->values()->all(),
        );
    }

    public function test_soft_deleted_and_deactivated_agents_stay_in_the_report_with_their_state(): void
    {
        $retired = $this->agent();
        $inactive = $this->agent();
        $ticket = $this->startedBy($retired);
        $this->join($ticket, $inactive);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $retired, ['note' => 'จบ']);

        $retired->delete();
        $inactive->update(['is_active' => false]);

        $people = $this->people($this->report($this->admin()));

        $this->assertSame('ลบบัญชีแล้ว', $people['user:'.$retired->id]['state']);
        $this->assertSame(1, $people['user:'.$retired->id]['closed']);
        $this->assertSame('ปิดใช้งาน', $people['user:'.$inactive->id]['state']);
        $this->assertSame(1, $people['user:'.$inactive->id]['participated']);
    }

    public function test_agents_with_the_same_name_are_never_merged(): void
    {
        $first = $this->agent();
        $second = $this->agent();
        $first->update(['name' => 'สมชาย ใจดี']);
        $second->update(['name' => 'สมชาย ใจดี']);
        $this->startedBy($first);
        $this->startedBy($second);
        $this->startedBy($second);

        $people = $this->people($this->report($this->admin()));

        $this->assertSame(1, $people['user:'.$first->id]['participated']);
        $this->assertSame(2, $people['user:'.$second->id]['participated']);

        // ลบถาวรทั้งคู่ — ชื่อซ้ำ แต่ยังเป็นสองคน และคนที่สองยังรวม 2 Ticket ของตัวเองไว้
        $first->forceDelete();
        $second->forceDelete();

        $rows = $this->people($this->report($this->admin()))->where('name', 'สมชาย ใจดี');
        $this->assertEquals(['user:'.$first->id => 1, 'user:'.$second->id => 2], $rows->map(fn (array $row) => $row['participated'])->all());
    }

    /* ---------------------------------------------------------------
     * 11 ช่วงวันที่ตามเวลากรุงเทพ
     * ------------------------------------------------------------- */

    public function test_the_date_range_uses_bangkok_business_days(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 16:59:00', 'UTC')); // 30 ก.ย. 23:59 กรุงเทพ
        $september = $this->publicTicket('ปลายเดือนกันยา');
        $this->travelTo(Carbon::parse('2026-09-30 17:00:00', 'UTC')); // 1 ต.ค. 00:00 กรุงเทพ
        $october = $this->publicTicket('ต้นเดือนตุลา');
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        $admin = $this->admin();

        $oct = $this->report($admin, ['month' => '2026-10']);
        $this->assertSame([$october->id], collect($oct->viewData('tickets')->items())->pluck('id')->all());
        $this->assertSame(1, $oct->viewData('trend')['values'][0], 'วันแรกของกราฟ = 1 ต.ค.');

        $sep = $this->report($admin, ['month' => '2026-09']);
        $this->assertSame([$september->id], collect($sep->viewData('tickets')->items())->pluck('id')->all());

        $day = $this->report($admin, ['from' => '2026-10-01', 'to' => '2026-10-01']);
        $this->assertSame(1, $day->viewData('total'));
        $this->assertSame(['1 ต.ค.'], $day->viewData('trend')['labels']);
    }

    public function test_closed_within_the_period_is_reported_separately_from_created_and_closed(): void
    {
        $agent = $this->agent();
        $this->travelTo(Carbon::parse('2026-09-20 03:00:00', 'UTC'));
        $ticket = $this->startedBy($agent);
        $this->travelTo(Carbon::parse('2026-10-02 03:00:00', 'UTC'));
        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        $admin = $this->admin();

        $oct = $this->report($admin, ['month' => '2026-10']);
        $this->assertSame(0, $oct->viewData('total'));
        $this->assertSame(1, $oct->viewData('closedInPeriod'));

        $sep = $this->report($admin, ['month' => '2026-09']);
        $this->assertSame(1, $sep->viewData('total'));
        $this->assertSame('1', $this->kpis($sep)['closed']['value']);
        $this->assertSame(0, $sep->viewData('closedInPeriod'));
        $this->assertEqualsWithDelta(12 * 1440, $sep->viewData('durations')['resolution']['average'], 0.01);
    }

    /* ---------------------------------------------------------------
     * 12 SLA
     * ------------------------------------------------------------- */

    public function test_without_sla_targets_the_report_shows_no_percentage(): void
    {
        $this->startedBy($this->agent());

        $response = $this->report($this->admin());

        $this->assertFalse($this->kpis($response)->keys()->contains(fn (string $key) => str_starts_with($key, 'sla_')));
        $this->assertSame(['configured' => false], $response->viewData('sla')['response']);
        $this->assertSame(['configured' => false], $response->viewData('sla')['resolution']);
        $this->assertNull(collect($response->viewData('watch'))->firstWhere('key', 'overdue')['count']);
    }

    public function test_configured_sla_counts_met_missed_and_pending_without_treating_missing_data_as_met(): void
    {
        config(['tickets.sla.response_minutes' => 30, 'tickets.sla.resolution_hours' => 1]);
        $agent = $this->agent();

        $fast = $this->publicTicket('รับเร็ว');
        $this->publicTicket('ไม่มีใครรับ');
        $this->travel(10)->minutes();
        $this->startedBy($agent, $fast);
        $this->travel(105)->minutes();
        $this->publicTicket('เพิ่งแจ้ง');
        $this->travel(5)->minutes();

        $response = $this->report($this->admin());
        $sla = $response->viewData('sla');

        $this->assertSame([1, 1, 1, 50.0], [$sla['response']['met'], $sla['response']['missed'], $sla['response']['pending'], $sla['response']['percent']]);
        $this->assertSame([0, 2, 1, 0.0], [$sla['resolution']['met'], $sla['resolution']['missed'], $sla['resolution']['pending'], $sla['resolution']['percent']]);
        $this->assertSame('50%', $this->kpis($response)['sla_response']['value']);
        $this->assertSame('SLA ตอบรับ (30 นาที)', $this->kpis($response)['sla_response']['label']);
        $this->assertSame('0%', $this->kpis($response)['sla_resolution']['value']);
        $this->assertSame(2, collect($response->viewData('watch'))->firstWhere('key', 'overdue')['count']);
    }

    /* ---------------------------------------------------------------
     * 13 สิทธิ์
     * ------------------------------------------------------------- */

    public function test_only_admins_and_ticket_agents_can_open_the_report(): void
    {
        $this->publicTicket();
        $sales = $this->department('Sales');

        $this->actingAs($this->agent())->get(route('reports.tickets'))->assertOk();
        $this->actingAs($this->admin())->get(route('reports.tickets'))->assertOk();

        $this->actingAs($this->staff($sales))->get(route('reports.tickets'))->assertForbidden();
        $this->actingAs($this->staff($sales, head: true))->get(route('reports.tickets'))->assertForbidden();
        $this->actingAs($this->staff(null, 'viewer'))->get(route('reports.tickets'))->assertForbidden();

        $disabled = $this->agent();
        $this->override($disabled, false);
        $this->actingAs($disabled)->get(route('reports.tickets'))->assertForbidden();

        // บัญชีที่ถูกปิด: middleware active ออกจากระบบให้ก่อนถึง policy (JSON = 403, หน้าเว็บ = ไปหน้า login)
        $inactive = $this->staff($this->itDepartment(), 'admin', active: false);
        $this->assertFalse($inactive->can('viewReport', Ticket::class));
        $this->actingAs($inactive)->getJson(route('reports.tickets'))->assertForbidden();
        $this->actingAs($this->staff($this->itDepartment(), active: false))->get(route('reports.tickets'))->assertRedirect(route('login'));
    }

    public function test_the_report_menu_follows_the_same_ability(): void
    {
        $sales = $this->department('Sales');
        $link = 'href="'.route('reports.tickets').'"';

        $this->actingAs($this->agent())->get(route('tickets.index'))->assertSee($link, false);
        $this->actingAs($this->admin())->get(route('tickets.index'))->assertSee($link, false);
        $this->actingAs($this->staff($sales))->get(route('tickets.index'))->assertDontSee($link, false);
        $this->actingAs($this->staff(null, 'viewer'))->get(route('reports.projects'))->assertOk()->assertDontSee($link, false);

        // ลิงก์รายงานอยู่ในกลุ่ม "รายงาน" (layouts/partials/report-nav) ร่วมกับรายงานอื่น และมีที่เดียว
        $page = $this->report($this->agent())->assertSee('<details class="report-nav report-nav--active" open>', false)->getContent();
        $this->assertStringNotContainsString('data-ticket-nav', $page);
        $this->assertMatchesRegularExpression('~report-nav__link active"\s+aria-current="page"\s*>\s*<span class="nav-item__label">รายงานแจ้งปัญหา~u', $page);
        $this->assertSame(1, substr_count($page, $link));
    }

    /* ---------------------------------------------------------------
     * 14 ตัวกรองและการแบ่งหน้า
     * ------------------------------------------------------------- */

    public function test_filters_narrow_every_number_and_unknown_values_are_ignored(): void
    {
        $sales = $this->department('Sales');
        $branch = $this->location('สาขาบางนา');
        $a = $this->agent();
        $b = $this->agent();

        $salesTicket = app(TicketSubmissionService::class)->submitInternal($this->staff($sales), ['description' => 'เมาส์เสีย', 'location_id' => $branch->id]);
        $this->startedBy($a, $salesTicket);
        $public = $this->startedBy($b);
        $this->transition($public, Ticket::ACTION_CLOSE, $b, ['note' => 'จบ']);
        $this->ticketFrom($this->staff()); // ไม่มีแผนก, สถานที่พิมพ์เอง
        $admin = $this->admin();

        $this->assertSame(3, $this->report($admin)->viewData('total'));
        $this->assertSame(1, $this->report($admin, ['status' => Ticket::STATUS_CLOSED])->viewData('total'));
        $this->assertSame(1, $this->report($admin, ['department' => $sales->id])->viewData('total'));
        $this->assertSame(1, $this->report($admin, ['department' => 'public'])->viewData('total'));
        $this->assertSame(1, $this->report($admin, ['department' => 'none'])->viewData('total'));
        $this->assertSame(1, $this->report($admin, ['location' => $branch->id])->viewData('total'));
        $this->assertSame(2, $this->report($admin, ['location' => 'other'])->viewData('total'));

        $byAgent = $this->report($admin, ['agent' => $a->id]);
        $this->assertSame(1, $byAgent->viewData('total'));
        $this->assertSame(['user:'.$a->id], $this->people($byAgent)->keys()->all(), 'กรองเจ้าหน้าที่ = แสดงคนนั้นคนเดียว');

        $ignored = $this->report($admin, ['status' => 'deleted', 'source' => 'email', 'department' => '99999', 'location' => 'x', 'agent' => '1 or 1=1']);
        $this->assertSame(3, $ignored->viewData('total'));
        $this->assertSame(['status' => '', 'source' => '', 'department' => '', 'location' => '', 'agent' => ''], $ignored->viewData('filters'));
    }

    public function test_both_tables_paginate_and_keep_filters_and_each_others_page(): void
    {
        $agent = $this->agent();
        foreach (range(1, 23) as $index) {
            $this->startedBy($agent, $this->publicTicket('ผู้แจ้ง '.$index));
        }
        foreach (range(1, 16) as $index) {
            $this->agent();
        }
        $admin = $this->admin();

        $first = $this->report($admin, ['status' => Ticket::STATUS_ACTIVE]);
        $this->assertSame(23, $first->viewData('tickets')->total());
        $this->assertCount(20, $first->viewData('tickets')->items());
        $this->assertSame(17, $first->viewData('people')->total(), 'agent ทุกคนอยู่ในตาราง แม้ยังไม่มีส่วนร่วม');

        $next = $first->viewData('tickets')->nextPageUrl();
        $this->assertStringContainsString('status=active', $next);
        $this->assertStringContainsString('#ticket-report-tickets', $next);

        $second = $this->report($admin, ['status' => Ticket::STATUS_ACTIVE, 'page' => 2, 'people_page' => 2]);
        $this->assertCount(3, $second->viewData('tickets')->items());
        $this->assertCount(2, $second->viewData('people')->items());
        $this->assertStringContainsString('people_page=2', $second->viewData('tickets')->previousPageUrl());
        $this->assertStringContainsString('page=2', $second->viewData('people')->previousPageUrl());

        // คนที่มีส่วนร่วมมากที่สุดขึ้นก่อน
        $this->assertSame('user:'.$agent->id, $first->viewData('people')->items()[0]['key']);
    }

    /* ---------------------------------------------------------------
     * 15 N+1
     * ------------------------------------------------------------- */

    public function test_the_source_filter_narrows_every_number_and_is_kept_across_pages(): void
    {
        $agent = $this->agent();
        $internal = $this->ticketFrom($this->staff($this->department('Sales')));
        $this->startedBy($agent, $internal);
        $this->publicTicket();
        $this->publicTicket();
        $admin = $this->admin();

        $all = $this->report($admin);
        $this->assertSame(3, $all->viewData('total'));
        $this->assertSame(['internal' => 1, 'public' => 2], array_map(fn (array $item) => $item['count'], $all->viewData('bySource')));
        $all->assertSee('value="internal"', false)->assertSee('value="public"', false);

        $public = $this->report($admin, ['source' => 'public']);
        $this->assertSame(2, $public->viewData('total'));
        $this->assertSame('2', collect($public->viewData('kpis'))->firstWhere('key', 'total')['value']);
        $this->assertSame(2, array_sum($public->viewData('trend')['values']));
        $this->assertSame(2, $public->viewData('tickets')->total());
        $this->assertSame(['public'], collect($public->viewData('tickets')->items())->pluck('source')->unique()->values()->all());
        $this->assertSame(0, $this->people($public)['user:'.$agent->id]['participated'], 'รายบุคคลใช้ชุด Ticket เดียวกัน');
        $public->assertSee('value="public" selected', false)->assertDontSee('value="internal" selected', false);

        $internalOnly = $this->report($admin, ['source' => 'internal']);
        $this->assertSame([$internal->id], collect($internalOnly->viewData('tickets')->items())->pluck('id')->all());

        $this->assertSame('', $this->report($admin, ['source' => 'fax'])->viewData('filters')['source'], 'ค่าที่ไม่รู้จักถูกทิ้ง');

        foreach (range(1, TicketReportService::TICKETS_PER_PAGE) as $index) {
            $this->publicTicket();
        }
        $this->assertStringContainsString('source=public', $this->report($admin, ['source' => 'public'])->viewData('tickets')->nextPageUrl());
    }

    public function test_the_ticket_table_lists_every_participant_with_coordinator_and_closer(): void
    {
        [$lead, $helper, $leaver] = [$this->agent(), $this->agent(), $this->agent()];
        $lead->update(['name' => 'หัวหน้าทีม']);
        $helper->update(['name' => 'ผู้ช่วยหนึ่ง']);
        $leaver->update(['name' => 'คนที่ออกไป']);
        $ticket = $this->startedBy($lead);
        app(TicketParticipantService::class)->join($ticket, $helper);
        app(TicketParticipantService::class)->join($ticket, $leaver);
        app(TicketParticipantService::class)->leave($ticket, $leaver);
        app(TicketParticipantService::class)->setCoordinator($ticket, $lead, $helper);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $lead, ['note' => 'จบ']);
        $lead->forceDelete();

        $html = $this->report($this->admin())->getContent();
        preg_match('~<td data-label="ผู้ดำเนินงาน" data-cell="participants">(.*?)</td>~s', $html, $cell);

        $this->assertNotEmpty($cell);
        $this->assertStringContainsString('หัวหน้าทีม', $cell[1]);
        $this->assertMatchesRegularExpression('~ผู้ช่วยหนึ่ง\s*<span class="ticket-report__role">ผู้ประสานงาน</span>~u', $cell[1]);
        $this->assertMatchesRegularExpression('~คนที่ออกไป\s*<span class="ticket-report__role ticket-report__role--muted">ออกแล้ว</span>~u', $cell[1]);
        $this->assertStringContainsString('ปิดโดย หัวหน้าทีม', $cell[1], 'ผู้ปิดยังระบุได้หลังบัญชีถูกลบถาวร');
    }

    public function test_the_query_count_does_not_grow_with_tickets_or_people(): void
    {
        $admin = $this->admin();
        $count = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report($admin);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->seedWork(2);
        $this->report($admin); // อุ่นการตรวจ schema ครั้งแรกของ request
        $small = $count();

        $this->seedWork(10);
        $large = $count();

        $this->assertSame($small, $large);
    }

    private function seedWork(int $agents): void
    {
        $department = $this->department('Dept '.uniqid());
        $location = $this->location('Branch '.uniqid());

        foreach (range(1, $agents) as $index) {
            $agent = $this->agent();
            $ticket = app(TicketSubmissionService::class)->submitInternal($this->staff($department), ['description' => 'ปัญหา '.$index, 'location_id' => $location->id]);
            $this->startedBy($agent, $ticket);
            $this->join($ticket, $this->agent());
            if ($index % 2 === 0) {
                $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
            }
        }
    }

    /* ---------------------------------------------------------------
     * หน้าเว็บ และ 18 รายงานเดิม
     * ------------------------------------------------------------- */

    public function test_the_page_renders_numbers_tables_and_chart_data_from_the_server(): void
    {
        $agent = $this->agent();
        $this->startedBy($agent, $this->publicTicket('คุณสมหญิง'));

        $this->report($agent)
            ->assertSee('รายงาน Smart Ticket')
            ->assertSee('id="ticketTrendChart"', false)
            ->assertSee('id="ticketDepartmentChart"', false)
            ->assertSee('id="ticket-report-chart-data"', false)
            ->assertSee('data-chart-state="loading"', false)
            ->assertSee('คุณสมหญิง')
            ->assertDontSee('{{--', false)
            ->assertDontSee('--}}', false)
            ->assertDontSee('<style', false);
    }

    /** ชื่อผู้แจ้ง สถานที่ที่บุคคลภายนอกพิมพ์เอง และชื่อแผนกไปถึงทั้งตาราง และ JSON ของกราฟในแท็ก <script> */
    public function test_text_typed_by_public_submitters_cannot_inject_markup_into_the_report(): void
    {
        $payload = '</script><script>alert(1)</script>';
        app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'คุณ <img src=x onerror=alert(2)>',
            'description' => 'ปริ้นเตอร์เสีย '.$payload,
            'location_name' => 'สาขา '.$payload,
        ], '203.0.113.7');
        $this->ticketFrom($this->staff($this->department('ฝ่าย '.$payload)));

        $html = $this->report($this->admin())->getContent();

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('203.0.113.7', $html, 'IP ผู้แจ้งไม่แสดงในรายงาน');

        preg_match('~<script type="application/json" id="ticket-report-chart-data">(.*?)</script>~s', $html, $island);
        $this->assertContains('ฝ่าย '.$payload, json_decode($island[1], true)['departments']['labels'], 'JSON ยังอ่านกลับได้ครบ');
    }

    public function test_an_empty_period_renders_one_empty_state_instead_of_empty_charts_and_tables(): void
    {
        // ช่วงที่ไม่มี Ticket: ข้อความเดียว ไม่มีกราฟ/ตารางว่างหลายใบ
        $this->report($this->admin())
            ->assertSee('data-ticket-report-empty', false)
            ->assertSee('ไม่มี Ticket ที่แจ้งใน')
            ->assertDontSee('data-chart-state="loading"', false)
            ->assertDontSee('id="ticketTrendChart"', false)
            ->assertDontSee('id="ticketDepartmentChart"', false)
            ->assertDontSee('data-ticket-report-list', false)
            ->assertDontSee('ล้างตัวกรอง');

        // มีตัวกรองแต่ไม่พบ: บอกว่าไม่ตรงกับตัวกรอง และมีทางล้างตัวกรอง
        $this->report($this->admin(), ['source' => Ticket::SOURCE_PUBLIC])
            ->assertSee('ที่ตรงกับตัวกรอง')
            ->assertSee('ล้างตัวกรอง');
    }

    public function test_a_period_with_tickets_shows_charts_then_the_list_and_nothing_else(): void
    {
        $this->startedBy($this->agent());

        $this->report($this->admin())
            ->assertDontSee('data-ticket-report-empty', false)
            ->assertSeeInOrder(['id="ticketTrendChart"', 'id="ticketDepartmentChart"', 'data-ticket-report-list'], false)
            ->assertDontSee('ticket-report__disclosure', false)
            ->assertDontSee('รายละเอียดวิเคราะห์เพิ่มเติม');
    }

    public function test_the_report_has_no_kpi_cards_but_the_backend_still_computes_them(): void
    {
        $agent = $this->agent();
        $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);

        $response = $this->report($this->admin())
            ->assertDontSee('data-ticket-kpi', false)
            ->assertDontSee('report-kpi', false)
            ->assertDontSee('ticket-report__kpi', false);

        $this->assertSame(['total', 'new', 'active', 'follow_up', 'closed', 'response', 'resolution'], $this->kpis($response)->keys()->all());
        // ตัวเลขชุดเดิมคำนวณไว้เป็น view data แต่ไม่แสดงบนหน้า
        $response->assertDontSee('data-ticket-fact', false);
    }

    public function test_the_department_chart_ranks_the_requester_departments_from_snapshots(): void
    {
        $sales = $this->department('Sales');
        $support = $this->department('Support');
        foreach ([$sales, $sales, $sales, $support] as $department) {
            $this->ticketFrom($this->staff($department));
        }
        $this->publicTicket();
        $this->publicTicket();
        $this->ticketFrom($this->staff());

        $chart = $this->report($this->admin())->viewData('chartData')['departments'];

        $this->assertSame(['Sales', 'บุคคลภายนอก', 'Support', 'ไม่ระบุแผนก'], $chart['labels']);
        $this->assertSame([3, 2, 1, 1], $chart['values']);
        $this->assertSame(7, array_sum($chart['values']), 'ผลรวมแผนก = Ticket ทั้งหมด');
    }

    public function test_the_department_donut_keeps_the_top_slices_and_folds_the_rest_into_one_real_total(): void
    {
        $counts = [];
        foreach (range(1, TicketReportService::CHART_DEPARTMENTS + 3) as $index) {
            $department = $this->department('แผนก '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            // แผนกที่ 1 มี Ticket มากสุด ลดหลั่นลงมา
            foreach (range(1, TicketReportService::CHART_DEPARTMENTS + 4 - $index) as $unused) {
                $this->ticketFrom($this->staff($department));
            }
            $counts[$index] = TicketReportService::CHART_DEPARTMENTS + 4 - $index;
        }

        $response = $this->report($this->admin());
        $chart = $response->viewData('chartData')['departments'];
        $top = TicketReportService::CHART_DEPARTMENTS;

        $this->assertTrue($chart['other']);
        $this->assertCount($top + 1, $chart['labels']);
        $this->assertSame(array_slice(array_values($counts), 0, $top), array_slice($chart['values'], 0, $top));
        $this->assertSame('อื่น ๆ (3 แผนก)', $chart['labels'][$top]);
        $this->assertSame(array_sum(array_slice($counts, $top)), $chart['values'][$top], 'ชิ้นอื่น ๆ = ผลรวมจริงของแผนกที่เหลือ');
        $this->assertSame($response->viewData('total'), array_sum($chart['values']), 'ผลรวมทุกชิ้น = Ticket ทั้งหมด');
        $this->assertCount($top + 3, $response->viewData('byDepartment'), 'service ยังจัดอันดับครบทุกแผนก');
        $response->assertSee('ticket-report__legend-item--other', false)->assertSee('อื่น ๆ (3 แผนก)');
    }

    public function test_the_department_donut_has_no_other_slice_when_everything_fits(): void
    {
        $this->ticketFrom($this->staff($this->department('Sales')));
        $this->publicTicket();

        $response = $this->report($this->admin());

        $this->assertFalse($response->viewData('chartData')['departments']['other']);
        $response->assertDontSee('ticket-report__legend-item--other', false);
    }

    public function test_the_donut_legend_lists_every_slice_with_its_count_and_share_and_a_total_in_the_middle(): void
    {
        $sales = $this->department('Sales');
        foreach (range(1, 3) as $unused) {
            $this->ticketFrom($this->staff($sales));
        }
        $this->publicTicket();

        $response = $this->report($this->admin())
            ->assertSee('id="ticketDepartmentChart"', false)
            ->assertSee('data-chart-kind="doughnut"', false)
            ->assertDontSee('data-chart-kind="horizontal-bar"', false)
            ->assertSee('ticket-report__donut-total', false);

        $html = $response->getContent();
        preg_match_all('~<li class="ticket-report__legend-item ticket-report__legend-item--(\w+)" data-department-slice>(.*?)</li>~s', $html, $items, PREG_SET_ORDER);

        $this->assertCount(2, $items);
        $this->assertSame('0', $items[0][1]);
        $this->assertStringContainsString('Sales', $items[0][2]);
        $this->assertMatchesRegularExpression('~3\s*<small>\(75%\)</small>~', $items[0][2]);
        $this->assertStringContainsString('บุคคลภายนอก', $items[1][2]);
        $this->assertMatchesRegularExpression('~1\s*<small>\(25%\)</small>~', $items[1][2]);
    }

    /* ---------------------------------------------------------------
     * ช่องค้นหาในรายการ และปุ่มดูรายละเอียด
     * ------------------------------------------------------------- */

    public function test_search_narrows_only_the_ticket_list_not_the_charts_or_totals(): void
    {
        $first = $this->publicTicket('คุณสมหญิง');
        $this->publicTicket('คุณสมชาย');
        $admin = $this->admin();

        $all = $this->report($admin);
        $found = $this->report($admin, ['q' => 'สมหญิง']);

        $this->assertSame([$first->id], collect($found->viewData('tickets')->items())->pluck('id')->all());
        $this->assertSame(2, $found->viewData('total'), 'ยอดรวมของรายงานไม่เปลี่ยน');
        $this->assertSame($all->viewData('chartData'), $found->viewData('chartData'));
        $this->assertSame($all->viewData('byDepartment'), $found->viewData('byDepartment'));
        $found->assertSee('พบ 1 จาก 2 รายการ');
        $found->assertSee('name="q" value="สมหญิง"', false);

        // ค้นด้วยเลข Ticket และรายละเอียดได้เหมือนคิวงาน
        $this->assertSame([$first->id], collect($this->report($admin, ['q' => $first->ticket_number])->viewData('tickets')->items())->pluck('id')->all());
        $this->assertCount(2, $this->report($admin, ['q' => 'ปริ้นเตอร์'])->viewData('tickets')->items());
    }

    public function test_search_with_no_match_says_so_and_offers_to_clear_it(): void
    {
        $this->publicTicket();

        $response = $this->report($this->admin(), ['status' => Ticket::STATUS_NEW, 'q' => 'ไม่มีคำนี้'])
            ->assertSee('ไม่พบ Ticket ที่ตรงกับ “ไม่มีคำนี้”', false)
            ->assertSee('ล้างคำค้นหา')
            ->assertSee('id="ticketTrendChart"', false);

        $this->assertSame(0, $response->viewData('tickets')->total());
        $this->assertSame(1, $response->viewData('total'));
        // ล้างคำค้นหาคงตัวกรองไว้
        $response->assertSee('status=new#ticket-report-tickets', false);
        $response->assertDontSee('status=new&amp;q=', false);
    }

    public function test_search_text_is_trimmed_capped_and_kept_across_pages_and_filters(): void
    {
        foreach (range(1, TicketReportService::TICKETS_PER_PAGE + 1) as $index) {
            $this->publicTicket('ผู้ใช้ '.$index);
        }
        $admin = $this->admin();

        $response = $this->report($admin, ['q' => '  ผู้ใช้  ', 'source' => 'public']);
        $this->assertSame('ผู้ใช้', $response->viewData('search'));
        $this->assertStringContainsString('q=', $response->viewData('tickets')->nextPageUrl());
        $this->assertStringContainsString('source=public', $response->viewData('tickets')->nextPageUrl());
        // ตัวกรองหลักส่ง q ต่อไปเมื่อเปลี่ยนค่า และช่องค้นหาส่งตัวกรองเดิมต่อ
        $response->assertSee('<input type="hidden" name="q" value="ผู้ใช้">', false);
        $response->assertSee('<input type="hidden" name="source" value="public">', false);

        $this->assertSame(100, mb_strlen($this->report($admin, ['q' => str_repeat('ก', 150)])->viewData('search')));
    }

    public function test_every_row_links_to_the_ticket_detail_and_has_no_bulk_checkbox(): void
    {
        $ticket = $this->publicTicket();

        $this->report($this->admin())
            ->assertSee('href="'.route('tickets.show', $ticket).'"', false)
            ->assertSee('ดูรายละเอียด '.$ticket->ticket_number, false)
            ->assertDontSee('type="checkbox"', false);
    }

    public function test_the_table_shows_department_requester_and_the_real_source_of_each_ticket(): void
    {
        $sales = $this->department('Sales');
        $internal = $this->ticketFrom($this->staff($sales));
        $public = $this->publicTicket('คุณภายนอก');

        $html = $this->report($this->admin())->getContent();
        preg_match('~<tr data-ticket-row="'.$internal->id.'">(.*?)</tr>~s', $html, $internalRow);
        preg_match('~<tr data-ticket-row="'.$public->id.'">(.*?)</tr>~s', $html, $publicRow);

        $this->assertStringContainsString('Sales', $internalRow[1]);
        $this->assertStringContainsString('Smart Goals', $internalRow[1]);
        $this->assertStringContainsString('บุคคลภายนอก', $publicRow[1]);
        $this->assertStringContainsString('คุณภายนอก', $publicRow[1]);
        $this->assertStringContainsString('หน้าเว็บสาธารณะ', $publicRow[1]);
    }

    public function test_chart_height_follows_the_amount_of_data_without_changing_it(): void
    {
        $admin = $this->admin();
        $this->publicTicket();

        // 1 Ticket / 1 แผนก → กรอบเตี้ย และตัวเลขกราฟยังเป็นของจริง
        $one = $this->report($admin)->assertSee('ticket-report__charts--sm', false);
        $this->assertSame(1, array_sum($one->viewData('chartData')['trend']['values']));

        // หลายแผนก → กรอบสูงขึ้น ยอดรวมแผนกยังเท่ากับ Ticket ทั้งหมด
        foreach (range(1, 6) as $index) {
            $this->ticketFrom($this->staff($this->department('แผนก '.$index)));
        }
        $many = $this->report($admin)->assertSee('ticket-report__charts--lg', false)->assertDontSee('ticket-report__charts--sm', false);
        $this->assertSame(7, array_sum($many->viewData('chartData')['departments']['values']));
    }

    public function test_chart_height_grows_with_a_busy_day_even_with_one_department(): void
    {
        foreach (range(1, 8) as $index) {
            $this->publicTicket('ผู้ใช้ '.$index);
        }

        $this->report($this->admin())->assertSee('ticket-report__charts--lg', false);
    }

    public function test_the_search_box_placeholder_names_the_list_it_searches(): void
    {
        $this->publicTicket();

        $this->report($this->admin())->assertSee('placeholder="ค้นหาในรายการ Ticket"', false);
    }

    public function test_the_four_detail_sections_are_gone_from_the_page_but_still_computed(): void
    {
        $agent = $this->agent();
        $this->startedBy($agent, $this->publicTicket());

        $response = $this->report($this->admin());
        $html = $response->getContent();

        foreach (['data-ticket-sla', 'data-ticket-breakdowns', 'data-ticket-people', 'data-ticket-definitions', 'data-person=', 'data-sla', 'data-watch', 'ticket-report__more'] as $hook) {
            $this->assertStringNotContainsString($hook, $html, $hook);
        }
        foreach (['สรุปสถานะ เวลา และ SLA', 'สรุปตามแผนกและสาขา', 'ผลงานรายบุคคล', 'วิธีคำนวณ'] as $title) {
            $response->assertDontSee($title);
        }

        // การคำนวณยังอยู่ใน view data (ไม่ลบ service / query)
        foreach (['sla', 'watch', 'durations', 'byDepartment', 'byLocation', 'people', 'closedInPeriod'] as $key) {
            $this->assertNotNull($response->viewData($key), $key);
        }
    }

    public function test_existing_reports_still_work_and_keep_their_menu(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('reports.projects'))->assertOk()
            ->assertSee('รายงานโปรเจกต์')->assertSee('href="'.route('reports.tickets').'"', false);
        $this->actingAs($admin)->get(route('reports.operational'))->assertOk()
            ->assertSee('รายงานปฏิบัติงาน');
    }

    public function test_status_is_the_last_data_column_and_the_detail_link_stays_at_the_end(): void
    {
        $agent = $this->agent();
        $this->startedBy($agent);

        $html = $this->report($this->admin())->getContent();
        $table = substr($html, (int) strpos($html, '<table class="ticket-report__table ticket-report__table--cards ticket-report__table--tickets">'));
        $table = substr($table, 0, (int) strpos($table, '</table>'));

        preg_match_all('~<th scope="col"[^>]*>(.*?)</th>~s', $table, $heads);
        $this->assertSame(['Ticket', 'หัวข้อปัญหา', 'แผนก/หน่วยงาน', 'ผู้แจ้ง', 'วันที่แจ้ง', 'ตอบรับ / แก้ไข', 'ผู้ดำเนินงาน', 'สถานะ'],
            array_map(fn (string $head) => trim(strip_tags($head)), array_slice($heads[1], 0, 8)));
        preg_match_all('~<col class="ticket-report__col--([a-z]+)">~', $table, $cols);
        $this->assertSame(['ticket', 'summary', 'department', 'requester', 'when', 'durations', 'people', 'status', 'action'], $cols[1]);

        // แต่ละแถว (และการ์ดบนจอแคบ) เรียงเซลล์ตามหัวตาราง: ผู้ดำเนินงาน → สถานะ → ดูรายละเอียด
        $row = substr($table, (int) strpos($table, '<tr data-ticket-row='));
        $this->assertTrue(strpos($row, 'data-label="ผู้ดำเนินงาน"') < strpos($row, 'data-label="สถานะ"'));
        $this->assertTrue(strpos($row, 'data-label="สถานะ"') < strpos($row, 'class="ticket-report__action"'));
    }

    public function test_response_and_fix_times_have_their_own_column_and_missing_times_are_a_dash_not_zero(): void
    {
        $agent = $this->agent();
        $this->travelTo(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $closed = $this->publicTicket('ปิดแล้ว');
        $this->travel(15)->minutes();
        $this->startedBy($agent, $closed);
        $this->travel(2)->hours();
        $this->transition($closed, Ticket::ACTION_CLOSE, $agent, ['note' => 'แก้ไขแล้ว']);
        $this->travel(1)->minutes();
        $waiting = $this->publicTicket('ยังรออยู่');

        $html = $this->report($this->admin(), ['month' => '2026-10'])->getContent();
        $rowOf = function (Ticket $ticket) use ($html): string {
            $start = (int) strpos($html, '<tr data-ticket-row="'.$ticket->id.'">');

            return substr($html, $start, (int) strpos($html, '</tr>', $start) - $start);
        };
        $cell = fn (string $row) => preg_replace('/\s+/', ' ', strip_tags(substr($row, (int) strpos($row, 'data-label="ตอบรับ / แก้ไข"'), (int) strpos($row, 'data-label="ผู้ดำเนินงาน"') - (int) strpos($row, 'data-label="ตอบรับ / แก้ไข"'))));

        // ปิดแล้ว: ตอบรับ 15 นาที · แก้ไข 2 ชม. 15 นาที — และไม่ซ้ำอยู่ใต้วันที่แจ้งอีก
        $this->assertStringContainsString('ตอบรับ 15 นาที', $cell($rowOf($closed)));
        $this->assertStringContainsString('แก้ไข 2 ชม. 15 นาที', $cell($rowOf($closed)));
        $when = substr($rowOf($closed), (int) strpos($rowOf($closed), 'data-label="วันที่แจ้ง"'), (int) strpos($rowOf($closed), 'data-label="ตอบรับ / แก้ไข"') - (int) strpos($rowOf($closed), 'data-label="วันที่แจ้ง"'));
        $this->assertStringNotContainsString('ตอบรับ', $when);

        // ยังไม่รับ/ยังไม่ปิด: "—" พร้อมคำอธิบายสำหรับ screen reader ไม่ใช่ 0
        $this->assertStringContainsString('—<span class="visually-hidden">ยังไม่ตอบรับ</span>', $rowOf($waiting));
        $this->assertStringContainsString('—<span class="visually-hidden">ยังไม่ปิดงาน</span>', $rowOf($waiting));
        $this->assertStringNotContainsString('0 นาที', $cell($rowOf($waiting)));
    }

    public function test_the_period_is_the_first_field_of_the_filter_bar(): void
    {
        $html = $this->report($this->admin())->getContent();
        $header = substr($html, (int) strpos($html, '<header class="ticket-report__header">'), (int) strpos($html, '</header>', (int) strpos($html, '<header class="ticket-report__header">')) - (int) strpos($html, '<header class="ticket-report__header">'));
        $filters = substr($html, (int) strpos($html, '<div class="ticket-report__filters">'));

        $this->assertStringNotContainsString('name="month"', $header);
        $this->assertTrue(strpos($filters, 'name="month"') < strpos($filters, 'name="status"'), 'ช่วงเวลามาก่อนสถานะ');
        $this->assertTrue(strpos($filters, 'name="department"') < strpos($filters, 'data-period-range'), 'ช่วงวันที่กำหนดเองอยู่แถวถัดไป');
    }
}
