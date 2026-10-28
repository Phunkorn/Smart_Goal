<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าของผู้แจ้ง (tickets/landing — การ์ดสถานะเดียว) และ modal แจ้งปัญหา 3 ขั้น
 * modal เป็นฟอร์มจริงที่ส่งไป tickets.store ตัวเดิม — ไม่มีเส้นทางส่งใหม่
 */
class TicketLandingPageTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    public function test_requesters_get_one_action_and_no_ticket_management(): void
    {
        $sales = $this->department('ฝ่ายขาย');
        $member = $this->staff($sales);
        $member->forceFill(['phone' => '081-234-5678'])->save();
        // ใบเก่าที่ปิดแล้วไม่ถูกแสดงเป็นรายการในหน้า — ดูได้จากปุ่ม "ประวัติ Ticket" ในการ์ด (ไม่มีแถว "Ticket ล่าสุด" ใต้การ์ด)
        $agent = $this->agent();
        $old = $this->ticketFrom($member, 'ปัญหาที่เคยแจ้ง');
        $this->transition($this->startedBy($agent, $old), Ticket::ACTION_CLOSE, $agent, ['note' => 'แก้ไขแล้ว']);

        $page = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->assertViewIs('tickets.landing');

        $page->assertSee('<h1 class="ticket-reporter__title" id="ticket-reporter-title">แจ้งปัญหา IT</h1>', false)
            ->assertSee('พบปัญหาคอมพิวเตอร์ ระบบ หรืออุปกรณ์ แจ้งทีม IT ได้ที่นี่')
            ->assertSee('src="'.asset('images/tickets/reporter-1.png').'"', false)
            ->assertSee('href="'.route('tickets.create').'"', false)
            ->assertSee('data-bs-target="#ticket-submit-modal"', false)
            ->assertSee('data-bs-target="#ticket-history-modal"', false)
            ->assertDontSee('ticket-reporter__last', false)
            ->assertDontSee('Ticket ล่าสุด')
            // ไม่มีคิว ตัวกรอง แท็บสถานะ ภาพรวม หรือภาระงาน
            ->assertDontSee('class="ticket-tabs"', false)
            ->assertDontSee('data-auto-submit-form', false)
            ->assertDontSee('class="ticket-overview"', false)
            ->assertDontSee('ภาระงานของทีม')
            ->assertDontSee('data-ticket-row=', false);

        // modal: ฟอร์มจริงไป tickets.store · ขั้นตอน 3 ขั้น · ผู้แจ้งจากบัญชีเป็นข้อความ ไม่ใช่ช่องกรอก
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('~<form class="modal-content ticket-form[^"]*" method="POST" action="'.preg_quote(route('tickets.store'), '~').'"\s+enctype="multipart/form-data" data-ticket-form data-ticket-stepper>~', $html);
        $this->assertSame(3, substr_count($html, 'data-ticket-step-indicator='));
        $this->assertSame(3, substr_count($html, 'data-ticket-step="'));
        $this->assertStringContainsString('<dd>'.e($member->name).'</dd>', $html); // ชื่อสุ่มอาจมี ' ซึ่งถูก escape
        $this->assertStringContainsString('<dd>ฝ่ายขาย</dd>', $html);
        // เบอร์โทรไม่อยู่ในสรุปอีกแล้ว — เป็นช่องกรอกจริงที่ตั้งต้นจากโปรไฟล์แบบตัวเลขล้วน (prefillPhone)
        $this->assertStringNotContainsString('<dd>081-234-5678</dd>', $html);
        $this->assertStringContainsString('name="contact"', $html);
        $this->assertStringContainsString('value="0812345678"', $html);
        $this->assertStringContainsString('name="remote_access"', $html);
        $this->assertStringNotContainsString('name="requester_name"', $html);
        $this->assertStringNotContainsString('name="requester_department_id"', $html);
        $this->assertStringNotContainsString('data-open-on-load', $html);
        $this->assertSame('no-store, private', $page->headers->get('Cache-Control'), 'การ์ดต้องมาจาก server ทุกครั้ง (ปุ่ม Back)');
        // ป้ายของ partial ที่ include ใน modal ต้องเป็นของตัวเอง (ตัวแปร $label ของ loop ขั้นตอนเคยรั่วลงไป)
        $this->assertMatchesRegularExpression('~<span data-ticket-submit-label>ส่งแจ้งปัญหา</span>~', $html);
        $this->assertMatchesRegularExpression('~id="ticket-photos-label">\s*<span>รูปภาพประกอบ~u', $html);
        $this->assertSame(1, substr_count($html, '<span class="ticket-stepper__label">รายละเอียดปัญหา</span>'));
        // ขั้นที่ 2–3 ซ่อนไว้ตอนเริ่ม ช่องบังคับยังเป็น required (ไม่ปิดการตรวจของเบราว์เซอร์)
        $this->assertMatchesRegularExpression('~data-ticket-step="2"[^>]*hidden~', $html);
        $this->assertMatchesRegularExpression('~<textarea id="ticket-description" name="description"[^>]*\srequired~s', $html);
    }

    public function test_department_heads_who_are_not_agents_get_the_department_list_and_no_report_button(): void
    {
        $head = $this->staff($this->department('Sales'), head: true);

        $this->actingAs($head)->get(route('tickets.index'))->assertOk()->assertViewIs('tickets.department')
            ->assertDontSee('data-ticket-open-modal', false)
            ->assertDontSee('id="ticket-submit-modal"', false)
            ->assertDontSee('ticket-reporter__card', false);
        $this->actingAs($head)->get(route('tickets.create'))->assertForbidden();
    }

    public function test_the_modal_submits_through_the_existing_store_and_shows_the_new_ticket(): void
    {
        $member = $this->staff($this->department('ฝ่ายขาย'));
        $branch = $this->location('สาขาบางนา');

        $response = $this->actingAs($member)->from(route('tickets.index'))->post(route('tickets.store'), $this->internalForm([
            'location_id' => (string) $branch->id,
            'requester_department_id' => 999,
        ]));

        $ticket = Ticket::query()->sole();
        $response->assertRedirect(route('tickets.index'));
        $this->assertSame([$member->id, 'ฝ่ายขาย', Ticket::STATUS_NEW], [$ticket->requester_id, $ticket->requester_department_name, $ticket->status]);

        // กลับมาที่การ์ดเดิม: สถานะ "รอเจ้าหน้าที่รับงาน" จากฐานข้อมูล (ไม่ใช่ loading ค้าง) และไม่มีปุ่มแจ้งปัญหาใหม่
        $this->followRedirects($response)->assertOk()->assertViewIs('tickets.landing')
            ->assertSee('ส่งแจ้งปัญหาเรียบร้อยแล้ว หมายเลข '.$ticket->ticket_number)
            ->assertSee('<h1 class="ticket-reporter__title" id="ticket-reporter-title">รอเจ้าหน้าที่รับงาน</h1>', false)
            ->assertSee('<dd data-ticket-number>'.$ticket->ticket_number.'</dd>', false)
            ->assertSee('data-bs-target="#ticket-reporter-modal"', false)
            ->assertDontSee('data-ticket-open-modal', false)
            ->assertDontSee('id="ticket-submit-modal"', false)
            ->assertDontSee('กำลังส่งคำขอ');
    }

    public function test_a_rejected_submission_reopens_the_modal_with_the_values_kept(): void
    {
        $member = $this->staff($this->department('Sales'));
        $this->location('สาขาบางนา');

        $this->actingAs($member)->from(route('tickets.index'))
            ->post(route('tickets.store'), $this->internalForm(['description' => 'สั้น', 'location_name' => 'ห้องประชุมใหญ่']))
            ->assertRedirect(route('tickets.index'));

        $html = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~data-ticket-submit-modal\s+data-open-on-load~', $html);
        $this->assertMatchesRegularExpression('~<textarea id="ticket-description"[^>]*is-invalid~s', $html);
        $this->assertStringContainsString('id="ticket-description-error"', $html);
        $this->assertStringContainsString('value="ห้องประชุมใหญ่"', $html, 'ค่าที่กรอกในขั้นที่ 2 กลับมา');
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_the_full_page_form_stays_as_the_fallback(): void
    {
        $member = $this->staff($this->department('Sales'));

        $this->actingAs($member)->get(route('tickets.create'))->assertOk()
            ->assertSee('data-ticket-form', false)
            ->assertDontSee('data-ticket-stepper', false)
            ->assertSee('name="description"', false)
            ->assertSee('<dd>'.e($member->name).'</dd>', false);

        $this->actingAs($member)->from(route('tickets.create'))->post(route('tickets.store'), $this->internalForm())
            ->assertRedirect(route('tickets.index'));

        // มีใบเปิดอยู่แล้ว → หน้าฟอร์มแบบหน้าเดียวพากลับการ์ดสถานะ
        $this->actingAs($member)->get(route('tickets.create'))->assertRedirect(route('tickets.index'));
    }

    public function test_the_public_form_has_the_same_fields_as_a_two_step_form_and_still_creates_public_tickets(): void
    {
        $this->get(route('support.create'))->assertOk()
            ->assertSee('name="requester_name"', false)
            ->assertSee('name="description"', false)
            ->assertSee('data-ticket-stepper', false)
            ->assertSee('ticket-form__icon', false)
            ->assertDontSee('data-ticket-submit-modal', false);

        // ไอคอนหน้าป้ายช่องเป็นของหน้าสาธารณะเท่านั้น — modal ภายในไม่มี
        $member = $this->staff($this->department('Sales'));
        $this->actingAs($member)->get(route('tickets.index'))->assertDontSee('ticket-form__icon', false);

        $this->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));
        $this->assertSame(Ticket::SOURCE_PUBLIC, Ticket::query()->sole()->source);
    }

    /* ---------------------------------------------------------------
     * ประวัติ Ticket (ปุ่มในการ์ด + modal รายการ)
     * ------------------------------------------------------------- */

    public function test_a_requester_who_never_reported_has_no_history_button_or_modal(): void
    {
        $this->actingAs($this->staff($this->department('Sales')))->get(route('tickets.index'))->assertOk()
            ->assertDontSee('data-ticket-history-open', false)
            ->assertDontSee('id="ticket-history-modal"', false);
    }

    public function test_the_history_button_sits_inside_the_card_beside_the_main_action_and_opens_the_modal(): void
    {
        $member = $this->staff($this->department('Sales'));
        $agent = $this->agent();
        $old = $this->ticketFrom($member, 'ปัญหาที่เคยแจ้ง');
        $this->transition($this->startedBy($agent, $old), Ticket::ACTION_CLOSE, $agent, ['note' => 'แก้ไขแล้ว']);

        $html = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->getContent();
        $card = substr($html, (int) strpos($html, '<section class="ticket-reporter__card"'));
        $card = substr($card, 0, (int) strpos($card, '</section>'));
        $actions = substr($card, (int) strpos($card, 'class="ticket-reporter__actions"'));

        // ปุ่มอยู่ในการ์ด ถัดจากปุ่มแจ้งปัญหา (ในกลุ่มปุ่มเดียวกัน) และไม่มีแถวใต้การ์ดอีก
        $this->assertStringContainsString('ticket-button ticket-button--ghost ticket-reporter__history', $card);
        $this->assertStringContainsString('data-bs-target="#ticket-history-modal"', $actions);
        $this->assertLessThan(strpos($actions, 'ticket-reporter__history'), strpos($actions, 'data-ticket-open-modal'));
        $this->assertStringNotContainsString('ticket-reporter__last', $html);
        $this->assertStringNotContainsString('Ticket ล่าสุด', $html);
        $this->assertSame(1, substr_count($html, 'id="ticket-history-modal"'));
        // ใบที่ปิดแล้วเปิดดูจากรายการประวัติ ไม่มี modal รายละเอียดที่ไม่มีปุ่มเปิด
        $this->assertStringNotContainsString('id="ticket-reporter-modal"', $html);
    }

    public function test_the_history_button_sits_beside_the_view_button_while_a_ticket_is_open(): void
    {
        $member = $this->staff($this->department('Sales'));
        $open = $this->ticketFrom($member);

        $html = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->getContent();
        $card = substr($html, (int) strpos($html, '<section class="ticket-reporter__card"'));
        $card = substr($card, 0, (int) strpos($card, '</section>'));

        $this->assertStringContainsString('data-bs-target="#ticket-reporter-modal"', $card);
        $this->assertLessThan(strpos($card, 'ticket-reporter__history'), strpos($card, 'data-bs-target="#ticket-reporter-modal"'));
        $modal = substr($html, (int) strpos($html, 'id="ticket-history-modal"'));
        $this->assertStringContainsString($open->ticket_number, $modal);
        $this->assertStringContainsString('ทั้งหมด 1 รายการ', $modal);
    }

    public function test_the_history_lists_only_my_internal_tickets_newest_first_with_status_and_a_deep_link(): void
    {
        $member = $this->staff($this->department('Sales'));
        $other = $this->staff($this->department('HR'));
        $agent = $this->agent();

        $first = $this->ticketFrom($member, 'เมาส์เสีย');
        $this->transition($this->startedBy($agent, $first), Ticket::ACTION_CLOSE, $agent, ['note' => 'เปลี่ยนแล้ว']);
        $second = $this->ticketFrom($member, 'เครื่องพิมพ์ไม่ออก');
        $this->ticketFrom($other, 'ของคนอื่นห้ามเห็น');
        $this->publicTicket();

        $html = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->getContent();
        $modal = substr($html, (int) strpos($html, 'id="ticket-history-modal"'));

        $this->assertStringContainsString('ทั้งหมด 2 รายการ', $modal);
        $this->assertStringNotContainsString('ของคนอื่นห้ามเห็น', $modal);
        $this->assertStringNotContainsString('ปริ้นเตอร์ใช้งานไม่ได้', $modal);
        // ใหม่สุดก่อน พร้อมสถานะของแต่ละใบ และปิดงานมีเวลาปิด
        $this->assertSame(2, substr_count($modal, 'class="ticket-history__item"'));
        $this->assertSame(1, substr_count($modal, 'ปิดงาน <time'));
        $this->assertTrue(strpos($modal, $second->ticket_number) < strpos($modal, $first->ticket_number));
        $this->assertStringContainsString('href="'.route('tickets.index', ['ticket' => $first->id]).'"', $modal);
        $this->assertStringContainsString('href="'.route('tickets.index', ['ticket' => $second->id]).'"', $modal);
        $this->assertStringNotContainsString('<form', $modal);

        // ลิงก์ของแต่ละแถวเปิด modal รายละเอียดอ่านอย่างเดียวของใบนั้นเอง
        $detail = $this->actingAs($member)->get(route('tickets.index', ['ticket' => $first->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~id="ticket-reporter-modal"[^>]*data-ticket-reporter-modal\s+data-open-on-load~s', $detail);
        $this->assertStringContainsString('ticket-readonly-title-'.$first->id, $detail);
    }

    public function test_the_history_modal_shows_the_latest_twenty_and_says_how_many_exist(): void
    {
        $member = $this->staff($this->department('Sales'));
        $agent = $this->agent();

        foreach (range(1, 21) as $number) {
            $ticket = $this->ticketFrom($member, 'ปัญหาครั้งที่ '.$number);
            $this->transition($this->startedBy($agent, $ticket), Ticket::ACTION_CLOSE, $agent, ['note' => 'เรียบร้อย']);
        }

        $html = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->getContent();
        $modal = substr($html, (int) strpos($html, 'id="ticket-history-modal"'));

        $this->assertSame(20, substr_count($modal, 'class="ticket-history__item"'));
        $this->assertStringContainsString('แสดง 20 รายการล่าสุดจากทั้งหมด 21 รายการ', $modal);
        $this->assertStringContainsString('ปัญหาครั้งที่ 21', $modal);
        $this->assertStringNotContainsString('ปัญหาครั้งที่ 1<', $modal, 'ใบเก่าสุดไม่อยู่ในรายการ');
    }
}
