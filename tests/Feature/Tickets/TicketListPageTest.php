<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\TicketParticipantService;
use App\Services\TicketQueryService;
use App\Services\TicketSubmissionService;
use App\Support\TicketDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * หน้า /tickets — สองประสบการณ์ตามความสามารถของบัญชี (TicketController::index)
 *   เห็น Ticket ทั้งหมด (admin / เจ้าหน้าที่ IT) → หน้ารับแจ้งปัญหา: คิวงาน ตัวกรอง การแบ่งหน้า
 *   ผู้ใช้อื่น (รวมหัวหน้าแผนก) → หน้าแจ้งปัญหา IT ที่ไม่มีรายการ
 * ขอบเขตที่ผู้ใช้แต่ละคนเปิดได้ยังเป็นของ TicketQueryService::visibleTo() / TicketPolicy — ตรวจที่ service โดยตรง
 */
class TicketListPageTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_guests_and_viewers_cannot_open_the_list(): void
    {
        $this->get(route('tickets.index'))->assertRedirect(route('login'));
        $this->actingAs($this->staff($this->department('Sales'), role: 'viewer'))
            ->get(route('tickets.index'))->assertForbidden();
    }

    public function test_each_role_lists_exactly_what_it_may_open(): void
    {
        $sales = $this->department('Sales');
        $hr = $this->department('HR');
        $member = $this->staff($sales);
        $colleague = $this->staff($sales);
        $salesHead = $this->staff($sales, head: true);
        $hrMember = $this->staff($hr);

        $mine = $this->ticketFrom($member, 'ปัญหาของสมาชิกฝ่ายขาย');
        $colleagues = $this->ticketFrom($colleague, 'ปัญหาของเพื่อนร่วมแผนก');
        $hrs = $this->ticketFrom($hrMember, 'ปัญหาของฝ่ายบุคคล');
        $public = $this->publicTicket();

        // ผู้แจ้งได้การ์ดสถานะของตัวเอง ไม่มีรายการ Ticket ใดเลย (มีใบเปิดอยู่ จึงไม่มีปุ่มแจ้งปัญหาใหม่)
        $this->actingAs($member)->get(route('tickets.index'))->assertOk()
            ->assertViewIs('tickets.landing')
            ->assertSee('รอเจ้าหน้าที่รับงาน')
            ->assertSee($mine->ticket_number)
            ->assertDontSee('data-ticket-open-modal', false)
            ->assertDontSee('data-ticket-row=', false)
            ->assertDontSee('id="ticket-queue"', false);

        // หัวหน้าแผนก (ไม่ใช่ Handler) ได้รายการของแผนกแบบอ่านอย่างเดียว — ไม่มีปุ่มแจ้ง/รับงาน/เข้าร่วม
        $html = $this->actingAs($salesHead)->get(route('tickets.index'))->assertOk()
            ->assertViewIs('tickets.department')
            ->assertSee('Ticket ของแผนก')
            ->assertSee($mine->ticket_number)
            ->assertSee($colleagues->ticket_number)
            ->assertDontSee($hrs->ticket_number)
            ->assertDontSee($public->ticket_number)
            ->assertDontSee('data-ticket-open-modal', false)
            ->assertDontSee('data-ticket-join', false)
            ->assertDontSee('value="start"', false)
            ->getContent();
        // คอลัมน์ดำเนินการของรายการแผนกมีแค่ไอคอนรูปตา (ดูรายละเอียด) — ไม่มีฟอร์มรับงาน/เข้าร่วมใด ๆ
        $table = substr($html, (int) strpos($html, '<table class="ticket-queue__table">'));
        $this->assertStringNotContainsString('data-ticket-action-form', $table);
        $this->assertSame(2, substr_count($table, 'class="ticket-queue__open"'));

        // ขอบเขตเดิมของแต่ละคนยังอยู่ครบที่ service และหน้ารายละเอียด
        $this->assertVisible($member, [$mine], [$colleagues, $hrs, $public]);
        $this->assertVisible($salesHead, [$mine, $colleagues], [$hrs, $public]);
        $this->actingAs($member)->get(route('tickets.show', $mine))->assertRedirect(route('tickets.index', ['ticket' => $mine->id]));
        $this->actingAs($salesHead)->get(route('tickets.show', $colleagues))->assertRedirect(route('tickets.index', ['ticket' => $colleagues->id]));
        $this->actingAs($salesHead)->get(route('tickets.show', $hrs))->assertForbidden();

        $this->assertListed($this->agent(), [$mine, $colleagues, $hrs, $public], []);
        $this->assertListed($this->staff($this->department('Management'), role: 'admin'), [$mine, $colleagues, $hrs, $public], []);
    }

    public function test_rows_are_one_line_with_the_core_fields_and_the_rest_lives_on_the_detail_page(): void
    {
        $agent = $this->agent();
        $longLocation = mb_substr(str_repeat('สาขาที่มีชื่อยาวมาก', 6), 0, 150);
        $requester = $this->staff($this->department(str_repeat('ฝ่ายบริหาร', 8)));
        $requester->update(['name' => 'ผู้แจ้งที่มีชื่อยาวมาก '.str_repeat('ต่อท้าย', 6)]);
        $ticket = app(TicketSubmissionService::class)->submitInternal($requester, [
            'description' => 'เครื่องพิมพ์ชั้น 2 พิมพ์ไม่ออก '.str_repeat('และมีเสียงดังผิดปกติ ', 4),
            'location_name' => $longLocation,
        ]);
        $this->startedBy($agent, $ticket);
        app(TicketParticipantService::class)->join($ticket, $second = $this->agent());
        $ticket = $ticket->fresh();

        // คิว: เลข Ticket หัวข้อ (เต็ม ไม่ตัด) ผู้แจ้ง (ชื่อเต็ม) สาขา/สถานที่ สถานะ เวลา และลิงก์ — บรรทัดเดียวต่อแถว
        $list = $this->actingAs($agent)->get(route('tickets.index'))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee($ticket->summary)
            ->assertSee($ticket->requester_name)
            ->assertSee('กำลังดำเนินการ')
            ->assertSee(route('tickets.show', $ticket), false);

        // สาขา/สถานที่อยู่ในแถว (ค่าเต็ม ไม่ตัด) · ผู้แจ้งเป็นชื่ออย่างเดียว ไม่มีวงกลมตัวอักษร
        // แผนกและเจ้าหน้าที่ไม่อยู่ในแถว (ไม่ลบข้อมูล — ดูได้ที่หน้ารายละเอียดครบ) ตรวจเฉพาะตารางของคิว
        $html = $list->getContent();
        $table = substr($html, (int) strpos($html, '<table class="ticket-queue__table">'), (int) strpos($html, '</table>') - (int) strpos($html, '<table class="ticket-queue__table">'));
        $this->assertStringContainsString($ticket->ticket_number, $table);
        $this->assertStringContainsString('<th scope="col">สาขา/สถานที่</th>', $table);
        $this->assertStringContainsString('<td data-label="สาขา/สถานที่">', $table);
        $this->assertStringContainsString($longLocation, $table);
        $this->assertTrue(strpos($table, e($ticket->requester_name)) < strpos($table, $longLocation), 'สาขาอยู่ถัดจากผู้แจ้ง');
        $this->assertStringNotContainsString('ticket-queue__avatar', $table);
        $this->assertStringNotContainsString($requester->department->department_name, $table);
        $this->assertStringNotContainsString($second->name, $table);

        $this->actingAs($agent)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee($requester->department->department_name)
            ->assertSee($longLocation)
            ->assertSee($agent->name)
            ->assertSee($second->name);
    }

    public function test_each_row_opens_its_ticket_in_the_preview_modal_and_has_no_bulk_controls(): void
    {
        $agent = $this->agent();
        $first = $this->publicTicket('คุณหนึ่ง');
        $second = $this->publicTicket('คุณสอง');

        $page = $this->actingAs($agent)->get(route('tickets.index'))->assertOk();
        $html = $page->getContent();

        // "ดูรายละเอียด" เป็นไอคอนรูปตาในคอลัมน์ดำเนินการ เปิด modal (data-ticket-preview) — href ยังเป็นหน้ารายละเอียดเต็มสำหรับแท็บใหม่/ไม่มี JS
        foreach ([$first, $second] as $ticket) {
            $this->assertSame(1, preg_match_all(
                '#<a class="ticket-queue__open" href="'.preg_quote(route('tickets.show', $ticket), '#').'"\s+data-ticket-preview="'
                    .preg_quote(route('tickets.preview', $ticket), '#').'"\s+aria-label="ดูรายละเอียด '.$ticket->ticket_number.'" title="ดูรายละเอียด">\s*<i class="bi bi-eye" aria-hidden="true"></i>#u',
                $html
            ), $ticket->ticket_number);
        }
        $this->assertSame(1, substr_count($html, 'data-ticket-preview-modal'));

        // ไม่มีคอลัมน์ความสำคัญ (Ticket ไม่มีฟิลด์นี้) ไม่มี checkbox/bulk action และป้ายสถานะไม่มีไอคอนนำหน้า
        $page->assertDontSee('ความสำคัญ')
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('ticket-button--ghost ticket-queue__open', false)
            ->assertDontSee('badge-soft accent ticket-status"><i', false);
    }

    public function test_avatar_initials_skip_leading_vowels_and_time_parts_use_bangkok_time(): void
    {
        $this->assertSame('ล', TicketDesign::initial('เลขา สุขดี'));
        $this->assertSame('ส', TicketDesign::initial('  สมชาย'));
        $this->assertSame('A', TicketDesign::initial('alice'));
        $this->assertSame('?', TicketDesign::initial(''));

        $parts = TicketDesign::whenParts(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        $this->assertSame('10:00 น.', $parts['time']);
        $this->assertStringContainsString('2569', $parts['date']);
        $this->assertNull(TicketDesign::whenParts(null));
    }

    public function test_the_queue_header_has_search_and_a_filter_panel_inside_one_form(): void
    {
        $agent = $this->agent();
        $this->publicTicket();

        $page = $this->actingAs($agent)->get(route('tickets.index'))->assertOk();
        $html = $page->getContent();
        $form = substr($html, (int) strpos($html, 'data-auto-submit-form'), 4000);

        // ค้นหา ตัวกรองสาขา และแผงอยู่ในฟอร์มเดียวกัน (initAutoSubmitFilters หา input ภายในฟอร์ม) ปุ่มซ่อนไว้จนกว่า JS จะทำงาน
        $this->assertStringContainsString('name="q"', $form);
        $this->assertStringContainsString('data-ticket-filter-panel', $form);
        $this->assertStringContainsString('name="location"', $form);
        $page->assertSee('data-ticket-filter-toggle', false)->assertDontSee('data-open', false);

        // มีตัวกรองสาขา/ขอบเขตใช้อยู่ → แผงเปิดเองและบอกจำนวน
        $this->actingAs($agent)->get(route('tickets.index', ['location' => TicketQueryService::LOCATION_OTHER]))
            ->assertSee('data-ticket-filter-panel', false)->assertSee('data-open', false)
            ->assertSee('aria-expanded="true"', false);
    }

    public function test_numbered_pagination_keeps_links_and_the_compact_status(): void
    {
        $agent = $this->agent();
        // ผู้แจ้งหนึ่งคนมี Ticket เปิดอยู่ได้ใบเดียว — คิวยาวจึงมาจากผู้แจ้งหลายคน
        $sales = $this->department('Sales');
        for ($i = 0; $i < TicketQueryService::PER_PAGE + 1; $i++) {
            $this->ticketFrom($this->staff($sales), 'ปัญหาที่ '.$i);
        }

        $page = $this->actingAs($agent)->get(route('tickets.index'))->assertOk();

        // ตารางแสดง 10 รายการต่อหน้า ที่เหลือไปด้วยปุ่มถัดไป/ก่อนหน้า
        $this->assertSame(10, TicketQueryService::PER_PAGE);
        $this->assertSame(10, substr_count($page->getContent(), 'data-ticket-row='));
        $page->assertSee('แสดง 1–10 จาก 11 รายการ')
            ->assertSee('aria-current="page">1</span>', false)
            ->assertSee('aria-label="ไปหน้า 2"', false)
            ->assertSee('หน้า 1 จาก 2')
            ->assertSee('rel="next"', false);
    }

    public function test_public_tickets_show_the_chosen_department_and_older_ones_stay_outside(): void
    {
        $this->publicTicket('คุณภายนอก');

        $agent = $this->agent();
        $ticket = Ticket::query()->sole();

        $this->actingAs($agent)->get(route('tickets.index'))->assertSee('คุณภายนอก');

        // แผนกที่ผู้แจ้งเลือกเองบนฟอร์ม /support ขึ้นที่หน้ารายละเอียด แทนคำว่าบุคคลภายนอก
        $this->actingAs($agent)->get(route('tickets.show', $ticket))
            ->assertSee('Callcenter')
            ->assertDontSee('บุคคลภายนอก');

        // Ticket สาธารณะที่แจ้งไว้ก่อนมีช่องแผนก (ค่าว่าง) ยังขึ้นว่าเป็นบุคคลภายนอกตามเดิม
        $ticket->forceFill(['requester_department_name' => null])->save();
        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertSee('บุคคลภายนอก');
    }

    public function test_search_status_location_and_scope_filters(): void
    {
        $agent = $this->agent();
        $location = $this->location('สาขาบางนา');
        $printer = $this->ticketFrom($this->staff($this->department('Sales')), 'เครื่องพิมพ์เสีย');
        $network = app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'ผู้แจ้ง', 'requester_department' => 'Callcenter', 'description' => 'อินเทอร์เน็ตหลุดบ่อย', 'location_id' => $location->id,
        ], null);
        $this->startedBy($agent, $network);

        $this->assertFiltered($agent, ['q' => 'พิมพ์'], [$printer], [$network]);
        $this->assertFiltered($agent, ['q' => $network->ticket_number], [$network], [$printer]);
        $this->assertFiltered($agent, ['status' => Ticket::STATUS_ACTIVE], [$network], [$printer]);
        $this->assertFiltered($agent, ['location' => (string) $location->id], [$network], [$printer]);
        $this->assertFiltered($agent, ['location' => 'other'], [$printer], [$network]);
        $this->assertFiltered($agent, ['scope' => TicketQueryService::SCOPE_PARTICIPATING], [$network], [$printer]);
    }

    /**
     * หัวหน้าแผนกไม่มีรายการบนหน้าจอแล้ว แต่กติกาขอบเขตใน TicketQueryService ยังเป็นแหล่งเดียวของการมองเห็น
     * (และยังใช้กับ admin ที่เลือก "ที่ฉันแจ้ง") จึงตรวจที่ service โดยตรง
     */
    public function test_department_heads_can_narrow_to_their_department_and_unknown_values_are_ignored(): void
    {
        $sales = $this->department('Sales');
        $head = $this->staff($sales, head: true);
        $own = $this->ticketFromBeforeRoleChange($head, 'ปัญหาของหัวหน้าเอง');
        $team = $this->ticketFrom($this->staff($sales), 'ปัญหาของลูกทีม');

        $this->assertVisible($head, [$own, $team], [], ['scope' => TicketQueryService::SCOPE_DEPARTMENT]);
        $this->assertVisible($head, [$own], [$team], ['scope' => TicketQueryService::SCOPE_REQUESTED]);

        // พนักงานทั่วไปส่ง scope ที่ตัวเองไม่มีสิทธิ์เลือก — ถูกทิ้ง ไม่ขยายขอบเขต
        $member = $this->staff($sales);
        $mine = $this->ticketFrom($member, 'ของพนักงานเอง');
        $this->assertSame(
            ['q' => '', 'status' => '', 'location' => '', 'scope' => '', 'day' => ''],
            app(TicketQueryService::class)->filters($member, [
                'scope' => TicketQueryService::SCOPE_DEPARTMENT, 'status' => 'hacked', 'location' => '999', 'day' => 'hacked',
            ]),
        );
        $this->assertVisible($member, [$mine], [$own, $team], ['scope' => TicketQueryService::SCOPE_DEPARTMENT, 'status' => 'hacked', 'location' => '999']);

        // หัวหน้าแผนกได้รายการแผนก (ไม่ใช่คิวงานของทีม IT) — ขอบเขตเดียวกับ service
        $this->actingAs($head)->get(route('tickets.index', ['scope' => TicketQueryService::SCOPE_DEPARTMENT]))
            ->assertOk()->assertViewIs('tickets.department')
            ->assertSee($own->ticket_number)->assertSee($team->ticket_number)
            ->assertDontSee('class="ticket-overview"', false);
    }

    public function test_pagination_keeps_the_filters(): void
    {
        $agent = $this->agent();
        $sales = $this->department('Sales');
        for ($i = 0; $i < TicketQueryService::PER_PAGE + 3; $i++) {
            $this->ticketFrom($this->staff($sales), 'จอคอมพิวเตอร์ดับ '.$i);
        }

        $page = $this->actingAs($agent)->get(route('tickets.index', ['q' => 'จอ', 'status' => 'new']))->assertOk();
        $page->assertSee('หน้า 1 จาก 2');

        $content = $page->getContent();
        preg_match('/<a class="ticket-pager__link" href="([^"]+)" rel="next"/', $content, $next);
        $this->assertNotEmpty($next, 'next page link');
        $nextUrl = html_entity_decode($next[1]);
        $this->assertStringContainsString('q=', $nextUrl);
        $this->assertStringContainsString('status=new', $nextUrl);
        $this->assertStringContainsString('page=2', $nextUrl);

        $this->actingAs($agent)->get($nextUrl)->assertOk()->assertSee('หน้า 2 จาก 2');
    }

    public function test_empty_states(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->get(route('tickets.index'))->assertSee('ยังไม่มี Ticket');

        $this->publicTicket();
        $this->actingAs($agent)->get(route('tickets.index', ['q' => 'ไม่มีคำนี้แน่นอน']))
            ->assertSee('ไม่พบ Ticket ตามตัวกรอง')
            ->assertSee('ล้างตัวกรอง');
    }

    public function test_the_list_does_not_query_per_card(): void
    {
        $agent = $this->agent();
        $sales = $this->department('Sales');

        $count = function (int $tickets) use ($agent, $sales): int {
            for ($i = 0; $i < $tickets; $i++) {
                $this->startedBy($agent, $this->ticketFrom($this->staff($sales), 'ปัญหา '.$i));
            }

            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($agent)->get(route('tickets.index'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // อุ่นเครื่องหนึ่งครั้ง: การตรวจว่าติดตั้งตาราง Ticket แล้ว (TicketSchemaStatus) เกิดครั้งเดียวแล้วถูกจำไว้
        // ต้นทุนครั้งเดียวนั้นมีเทสต์ของตัวเองใน TicketNotInstalledTest — ที่นี่วัดเฉพาะต้นทุนต่อการ์ด
        $this->actingAs($agent)->get(route('tickets.index'))->assertOk();

        $few = $count(2);
        $many = $count(8);

        $this->assertSame($few, $many, 'จำนวนคิวรีต้องไม่เพิ่มตามจำนวนการ์ด');
    }

    /** @param  array<int, Ticket>  $visible  @param  array<int, Ticket>  $hidden */
    private function assertListed($user, array $visible, array $hidden): void
    {
        $this->assertFiltered($user, [], $visible, $hidden);
    }

    /** @param  array<int, Ticket>  $visible  @param  array<int, Ticket>  $hidden */
    private function assertFiltered($user, array $query, array $visible, array $hidden): void
    {
        $response = $this->actingAs($user)->get(route('tickets.index', $query))->assertOk();

        // ตรวจที่แถวของคิว ไม่ใช่ทั้งหน้า — เลข Ticket ปรากฏในกระดิ่งแจ้งเตือนของ agent ได้ตามปกติ
        foreach ($visible as $ticket) {
            $response->assertSee('id="ticket-row-'.$ticket->id.'"', false);
        }
        foreach ($hidden as $ticket) {
            $response->assertDontSee('id="ticket-row-'.$ticket->id.'"', false);
        }
    }

    /**
     * ขอบเขตจาก TicketQueryService โดยตรง (ชุดเดียวกับที่ policy ใช้) — สำหรับผู้ใช้ที่ไม่มีคิวงานบนหน้าจอ
     *
     * @param  array<int, Ticket>  $visible  @param  array<int, Ticket>  $hidden  @param  array<string, string>  $query
     */
    private function assertVisible($user, array $visible, array $hidden, array $query = []): void
    {
        $service = app(TicketQueryService::class);
        $ids = collect($service->paginate($user, $service->filters($user, $query))->items())->pluck('id');

        foreach ($visible as $ticket) {
            $this->assertTrue($ids->contains($ticket->id), "ควรเห็น {$ticket->ticket_number}");
        }
        foreach ($hidden as $ticket) {
            $this->assertFalse($ids->contains($ticket->id), "ไม่ควรเห็น {$ticket->ticket_number}");
        }
    }
}
