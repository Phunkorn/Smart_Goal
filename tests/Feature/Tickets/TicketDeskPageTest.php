<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\TicketParticipantService;
use App\Services\TicketQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * หน้ารับแจ้งปัญหาของทีม IT (/tickets สำหรับ admin และเจ้าหน้าที่ IT)
 * ทุกตัวเลขต้องมาจากฐานข้อมูลจริง และ Ticket หนึ่งใบมีผู้เข้าร่วมได้หลายคน
 */
class TicketDeskPageTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tickets.sla.response_minutes' => null, 'tickets.sla.resolution_hours' => null]);
    }

    public function test_agents_and_admins_get_the_desk(): void
    {
        $this->actingAs($this->agent())->get(route('tickets.index'))->assertOk()->assertViewIs('tickets.index')
            ->assertSee('<h1>รับแจ้งปัญหา</h1>', false)
            ->assertSee('id="ticket-queue"', false)
            ->assertSee('class="ticket-overview"', false)
            // "งานที่ต้องติดตาม" เหลือเป็นแท็บสถานะของคิว — การ์ดงานที่ต้องติดตาม/เกิน SLA และการ์ดภาระงานทีมถูกถอดจากหน้านี้แล้ว
            ->assertSee('งานที่ต้องติดตาม')
            ->assertDontSee('ภาระงานของทีม')
            ->assertDontSee('ticket-attention', false)
            ->assertDontSee('ticket-workload', false)
            ->assertDontSee('ยังไม่ปิดและเกิน SLA');

        $this->actingAs($this->staff($this->department('Management'), role: 'admin'))->get(route('tickets.index'))
            ->assertOk()->assertViewIs('tickets.index')
            ->assertSee('ดูอย่างเดียว');
    }

    public function test_the_overview_counts_every_ticket_once_per_current_status(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC')); // 10:00 กรุงเทพ
        $agent = $this->agent();
        $this->publicTicket();
        $this->startedBy($agent);
        $this->transition($this->startedBy($agent), Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่']);
        $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบวันนี้']);
        $this->travelTo(Carbon::parse('2026-10-14 16:59:00', 'UTC')); // 14 ต.ค. 23:59 กรุงเทพ = เมื่อวาน
        $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบเมื่อวาน']);
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));

        $page = $this->actingAs($agent)->get(route('tickets.index'))->assertOk();

        $this->assertSame([
            'counts' => [Ticket::STATUS_NEW => 1, Ticket::STATUS_ACTIVE => 1, Ticket::STATUS_FOLLOW_UP => 1, Ticket::STATUS_CLOSED => 2],
            'closed_today' => 1,
        ], $page->viewData('overview'));
        $page->assertSee('ปิดวันนี้ 1 รายการ')
            ->assertSee('href="'.route('tickets.index', ['status' => Ticket::STATUS_FOLLOW_UP]).'#ticket-queue"', false);

        // ตัวกรองของคิวไม่เปลี่ยนภาพรวม
        $this->assertSame(2, $this->actingAs($agent)->get(route('tickets.index', ['status' => 'closed']))->viewData('overview')['counts']['closed']);
    }

    public function test_the_kpi_row_has_five_real_figures_and_counts_today_by_bangkok_business_day(): void
    {
        $agent = $this->agent();
        $this->travelTo(Carbon::parse('2026-10-14 16:59:00', 'UTC')); // 14 ต.ค. 23:59 กรุงเทพ = เมื่อวาน
        $this->publicTicket();
        $this->travelTo(Carbon::parse('2026-10-14 17:00:00', 'UTC')); // 15 ต.ค. 00:00 กรุงเทพ = วันนี้
        $this->publicTicket();
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        $this->startedBy($agent);

        $page = $this->actingAs($agent)->get(route('tickets.index'))->assertOk();

        $this->assertSame(2, $page->viewData('createdToday'));
        // งานที่ต้องลงมือก่อน (รอรับงาน) อยู่ก่อนกำลังดำเนินการ · ไอคอนสื่อความหมายจาก TicketDesign::STATUSES
        $page->assertSeeInOrder(['Ticket ทั้งหมด', 'งานในวันนี้', 'รอรับงาน', 'กำลังดำเนินการ', 'งานที่ต้องติดตาม', 'ปิดงานแล้ว'])
            ->assertSeeInOrder(['bi-ticket-perforated', 'bi-calendar-plus', 'bi-clock', 'bi-tools', 'bi-hourglass-split', 'bi-check2-circle'], false)
            ->assertSee('data-ticket-overview="all"', false)
            ->assertSee('data-ticket-overview="today"', false)
            ->assertSee('data-ticket-overview="follow_up"', false)
            // การ์ดเป็นตัวกรองสถานะแล้ว — คิวไม่มีแท็บสถานะซ้ำ
            ->assertDontSee('class="ticket-tabs"', false);
    }

    public function test_overview_cards_filter_the_queue_by_status_and_keep_the_other_filters(): void
    {
        $agent = $this->agent();
        $this->publicTicket();

        $html = $this->actingAs($agent)->get(route('tickets.index', ['status' => Ticket::STATUS_FOLLOW_UP, 'q' => 'ปริ้น']))
            ->assertOk()
            ->assertDontSee('class="ticket-tabs"', false)
            ->getContent();

        // ทุกการ์ดคงคำค้นหาไว้ เปลี่ยนเฉพาะสถานะ · "Ticket ทั้งหมด" ล้างสถานะ · "งานในวันนี้" กรองด้วย day=today
        foreach ([Ticket::STATUS_NEW, Ticket::STATUS_ACTIVE, Ticket::STATUS_FOLLOW_UP, Ticket::STATUS_CLOSED] as $status) {
            $this->assertStringContainsString('href="'.e(route('tickets.index', ['q' => 'ปริ้น', 'status' => $status])).'#ticket-queue"', $html);
        }
        $this->assertStringContainsString('href="'.e(route('tickets.index', ['q' => 'ปริ้น'])).'#ticket-queue" data-ticket-overview="all"', $html);
        $this->assertStringContainsString(
            'href="'.e(route('tickets.index', ['q' => 'ปริ้น', 'day' => TicketQueryService::DAY_TODAY])).'#ticket-queue"',
            $html
        );

        // การ์ดของตัวกรองที่ใช้อยู่ใบเดียวมี aria-current (นับเฉพาะส่วนภาพรวม — เมนู sidebar มีของตัวเอง)
        $start = (int) strpos($html, '<section class="ticket-overview"');
        $overview = substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
        $this->assertSame(1, substr_count($overview, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#data-ticket-overview="follow_up"\s+aria-current="page"#', $html);
    }

    public function test_the_today_card_filters_the_queue_to_tickets_reported_today(): void
    {
        $agent = $this->agent();
        $this->travelTo(Carbon::parse('2026-10-14 16:59:00', 'UTC')); // 14 ต.ค. 23:59 กรุงเทพ = เมื่อวาน
        $yesterday = $this->publicTicket();
        $this->travelTo(Carbon::parse('2026-10-14 17:00:00', 'UTC')); // 15 ต.ค. 00:00 กรุงเทพ = วันนี้
        $today = $this->publicTicket();
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));

        $page = $this->actingAs($agent)->get(route('tickets.index', ['day' => TicketQueryService::DAY_TODAY]))->assertOk();
        $html = $page->getContent();

        // ตรวจเฉพาะคิว — เลข Ticket โผล่ในกล่องแจ้งเตือนบนหัวหน้าจอด้วย ซึ่งไม่เกี่ยวกับตัวกรองนี้
        $queue = $this->queueResults($html);
        $this->assertStringContainsString($today->ticket_number, $queue);
        $this->assertStringNotContainsString($yesterday->ticket_number, $queue);

        // ตัวเลขบนการ์ดยังเป็นของทั้งระบบ ไม่ได้ลดลงตามตัวกรอง
        $this->assertSame(1, $page->viewData('createdToday'));
        $this->assertSame(2, array_sum($page->viewData('overview')['counts']));

        // "งานในวันนี้" เป็นการ์ดที่ใช้อยู่ใบเดียว — "Ticket ทั้งหมด" ต้องไม่ถูกทำเครื่องหมายไปพร้อมกัน
        $start = (int) strpos($html, '<section class="ticket-overview"');
        $overview = substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
        $this->assertSame(1, substr_count($overview, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#data-ticket-overview="today"\s+aria-current="page"#', $html);

        // ค้นหาต่อจากการ์ดไม่ทำให้ตัวกรองวันหาย (ฟอร์มส่ง day กลับไปด้วย)
        $this->assertStringContainsString('<input type="hidden" name="day" value="today">', $html);

        // ค่าที่ไม่รู้จักถูกทิ้ง ไม่ใช่กรองมั่ว
        $all = $this->queueResults($this->actingAs($agent)->get(route('tickets.index', ['day' => 'เมื่อวาน']))->assertOk()->getContent());
        $this->assertStringContainsString($yesterday->ticket_number, $all);
        $this->assertStringContainsString($today->ticket_number, $all);
    }

    /** เฉพาะส่วนผลลัพธ์ของคิวงาน (ไม่รวมหัวข้อ การ์ดภาพรวม และกล่องแจ้งเตือนของ layout) */
    private function queueResults(string $html): string
    {
        $start = (int) strpos($html, 'data-ticket-queue-results');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    public function test_the_department_list_keeps_its_status_tabs_because_it_has_no_overview(): void
    {
        $sales = $this->department('Sales');
        $head = $this->staff($sales, head: true);
        $this->ticketFrom($this->staff($sales));

        $this->actingAs($head)->get(route('tickets.index'))
            ->assertOk()
            ->assertViewIs('tickets.department')
            ->assertDontSee('class="ticket-overview"', false)
            ->assertSee('class="ticket-tabs"', false)
            ->assertSee('ทั้งหมด <span class="ticket-tabs__count">1</span>', false);
    }

    /** การ์ดภาระงานถูกถอดจากหน้าแล้ว แต่ query ของ service ยังเป็นเจ้าของกติกาการนับ จึงตรวจที่ service โดยตรง */
    public function test_team_workload_counts_shared_tickets_for_every_participant(): void
    {
        [$alice, $bob, $idle] = [$this->agent(), $this->agent(), $this->agent()];
        $alice->update(['name' => 'อลิส']);
        $bob->update(['name' => 'บ็อบ']);
        $idle->update(['name' => 'ว่าง']);

        $shared = $this->startedBy($alice);
        app(TicketParticipantService::class)->join($shared, $bob);
        $this->transition($this->startedBy($alice), Ticket::ACTION_FOLLOW_UP, $alice, ['note' => 'รอผู้ใช้']);
        $this->transition($this->startedBy($bob), Ticket::ACTION_CLOSE, $bob, ['note' => 'จบ']);
        $left = $this->startedBy($bob);
        app(TicketParticipantService::class)->join($left, $idle);
        app(TicketParticipantService::class)->leave($left, $idle);

        $rows = app(TicketQueryService::class)->teamWorkload()
            ->mapWithKeys(fn (array $row) => [$row['user']->name => [$row['working'], $row['follow_up'], $row['closed_today'], $row['closed_week']]]);

        // เรียงตามจำนวนที่กำลังเข้าร่วม (มากก่อน) แล้วตามชื่อ — บ็อบกับอลิสเข้าร่วม 2 ใบเท่ากัน
        $this->assertSame([
            'บ็อบ' => [2, 0, 1, 1],
            'อลิส' => [2, 1, 0, 0],
            'ว่าง' => [0, 0, 0, 0],
        ], $rows->all());
    }

    /** การ์ดถูกถอดจากหน้าแล้ว แต่ query ของ service (งานที่ต้องติดตาม / เกิน SLA) ยังคงกติกาเดิม จึงตรวจที่ service โดยตรง */
    public function test_follow_ups_and_overdue_tickets_are_separate_lists(): void
    {
        $agent = $this->agent();
        $waiting = $this->transition($this->startedBy($agent, $this->publicTicket('คนรออะไหล่')), Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่']);
        $this->travel(3)->hours();
        $fresh = $this->publicTicket('เพิ่งแจ้ง');

        $tickets = app(TicketQueryService::class);
        $this->assertSame([$waiting->id], $tickets->followUps(5)->pluck('id')->all());
        $this->assertFalse($tickets->overdue(5)['configured']);

        config(['tickets.sla.resolution_hours' => 2]);
        $overdue = $tickets->overdue(5);

        $this->assertSame([true, 120, 1], [$overdue['configured'], $overdue['target_minutes'], $overdue['total']]);
        $this->assertSame([$waiting->id], $overdue['tickets']->pluck('id')->all());
        $this->assertNotContains($fresh->id, $overdue['tickets']->pluck('id')->all());
    }

    public function test_admins_who_are_not_agents_get_no_extra_actions(): void
    {
        $admin = $this->staff($this->department('Management'), role: 'admin');
        $ticket = $this->publicTicket();

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('data-ticket-join', false)
            ->assertDontSee('value="start"', false);
        $this->actingAs($admin)->patch(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => 'new'])->assertForbidden();
    }

    public function test_the_page_states_when_its_data_was_read_and_updates_itself_without_a_refresh_button(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 07:30:00', 'UTC'));

        // คิวงานและรายการของหัวหน้าแผนกใช้ป้ายเวลาชุดเดียวกัน (components/desk-stamp) ไม่มีปุ่มรีเฟรช — desk.js โหลดใหม่เอง
        $head = $this->staff($this->department('Sales'), head: true);
        $this->actingAs($head)->get(route('tickets.index'))->assertOk()
            ->assertSee('อัปเดตอัตโนมัติ · ข้อมูล ณ <time datetime="2026-10-15T07:30:00+00:00">', false)
            ->assertDontSee('data-ticket-refresh', false)
            ->assertDontSee('ticket-desk__actions', false);

        $this->actingAs($this->agent())->get(route('tickets.index', ['status' => 'new']))
            ->assertSee('อัปเดตอัตโนมัติ · ข้อมูล ณ <time datetime="2026-10-15T07:30:00+00:00">', false)
            ->assertSee('data-ticket-stamp aria-live="polite"', false)
            ->assertDontSee('data-ticket-refresh', false)
            ->assertDontSee('ticket-desk__actions', false)
            // ส่วนที่ desk.js โหลดใหม่แบบ async — แถบ "ให้รีเฟรชเอง" แบบเดิมถูกแทนที่แล้ว
            ->assertSee('data-ticket-overview', false)
            ->assertSee('data-ticket-queue-results', false)
            ->assertSee('data-ticket-stamp', false)
            ->assertDontSee('data-ticket-refresh-hint', false)
            ->assertDontSee('อัปเดตล่าสุด');
    }

    public function test_the_desk_does_not_query_per_ticket_or_per_agent(): void
    {
        $viewer = $this->agent();
        $count = function () use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get(route('tickets.index'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->seedDesk(2);
        $this->actingAs($viewer)->get(route('tickets.index')); // อุ่นการตรวจ schema ครั้งแรก
        $few = $count();

        $this->seedDesk(8);
        $many = $count();

        $this->assertSame($few, $many);
    }

    private function seedDesk(int $agents): void
    {
        foreach (range(1, $agents) as $index) {
            $agent = $this->agent();
            $ticket = $this->startedBy($agent);
            app(TicketParticipantService::class)->join($ticket, $this->agent());
            if ($index % 2 === 0) {
                $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รอ']);
            }
            $this->publicTicket();
        }
    }
}
