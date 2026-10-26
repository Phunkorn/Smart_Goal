<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\TicketParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้ารับแจ้งปัญหา: ปุ่มรับงาน/เข้าร่วมในแถวของคิว และ modal ดูรายละเอียด (tickets.preview)
 * ปุ่มแสดงตาม TicketPolicy เท่านั้น และ endpoint ของ modal ตรวจ TicketPolicy::view เหมือนหน้ารายละเอียด
 */
class TicketDeskQueueActionsTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    private function rowOf(string $html, Ticket $ticket): string
    {
        $start = strpos($html, 'data-ticket-row="'.$ticket->id.'"');
        $this->assertNotFalse($start, $ticket->ticket_number);

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    public function test_agents_get_claim_and_join_buttons_per_row_by_policy(): void
    {
        $agent = $this->agent();
        $waiting = $this->publicTicket('คุณรอรับ');
        $joined = $this->startedBy($agent, $this->publicTicket('คุณเข้าร่วมแล้ว'));
        $others = $this->startedBy($this->agent(), $this->publicTicket('คุณของคนอื่น'));

        $html = $this->actingAs($agent)->get(route('tickets.index'))->assertOk()
            ->assertSee('<th scope="col">ดำเนินการ</th>', false)
            ->getContent();

        // รอรับงาน: มีแค่ปุ่มไอคอนรับงาน (success ไม่ใช่ primary) — ต้องรับงานก่อนจึงเข้าร่วมได้
        $row = $this->rowOf($html, $waiting);
        $this->assertStringContainsString('class="ticket-button ticket-button--success ticket-button--icon ticket-actions__button"', $row);
        $this->assertStringContainsString('aria-label="รับงาน '.$waiting->ticket_number.'"', $row);
        $this->assertStringContainsString('bi-clipboard-check', $row);
        $this->assertStringNotContainsString('ticket-button--primary', $row);
        $this->assertStringContainsString('value="start"', $row);
        $this->assertStringNotContainsString('data-ticket-join', $row);

        // เข้าร่วมอยู่แล้ว: ไม่มีปุ่มใด (รับงานทำได้เฉพาะสถานะรอรับงาน)
        $row = $this->rowOf($html, $joined);
        $this->assertStringNotContainsString('value="start"', $row);
        $this->assertStringNotContainsString('data-ticket-join', $row);

        // รับงานแล้วโดยคนอื่น: มีแค่ปุ่มไอคอนเข้าร่วม
        $row = $this->rowOf($html, $others);
        $this->assertStringNotContainsString('value="start"', $row);
        $this->assertStringContainsString('class="ticket-button ticket-button--outline ticket-button--icon ticket-actions__button" data-ticket-join', $row);
        $this->assertStringContainsString('aria-label="เข้าร่วม Ticket '.$others->ticket_number.'"', $row);
        $this->assertStringContainsString('action="'.route('tickets.participants.store', $others).'"', $row);
    }

    public function test_admins_who_are_not_agents_see_no_claim_buttons(): void
    {
        $admin = $this->staff($this->department('Management'), role: 'admin');
        $this->publicTicket();

        $this->actingAs($admin)->get(route('tickets.index'))->assertOk()
            ->assertSee('data-ticket-row=', false)
            ->assertDontSee('value="start"', false)
            ->assertDontSee('data-ticket-join', false);
    }

    public function test_claiming_from_the_queue_over_ajax_returns_json(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();

        $this->actingAs($agent)
            ->patchJson(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => Ticket::STATUS_NEW])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => Ticket::STATUS_ACTIVE]);

        $this->assertTrue($ticket->fresh()->hasActiveParticipant($agent));

        // อีกคนกดรับงานใบเดิมหลังจากนั้น → 409 พร้อมสถานะล่าสุด (desk.js โหลดคิวใหม่ทั้งสองกรณี)
        $this->actingAs($this->agent())
            ->patchJson(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => Ticket::STATUS_NEW])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'status' => Ticket::STATUS_ACTIVE]);
    }

    public function test_the_preview_reuses_the_detail_components_and_offers_only_join(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket('คุณผู้แจ้ง');

        // รอรับงาน: modal ไม่มีปุ่มรับงาน และยังเข้าร่วมไม่ได้
        $this->actingAs($agent)->get(route('tickets.preview', $ticket))->assertOk()
            ->assertDontSee('value="start"', false)
            ->assertDontSee('data-ticket-join', false);

        $this->startedBy($this->agent(), $ticket);
        $ticket = $ticket->fresh();

        $preview = $this->actingAs($agent)->get(route('tickets.preview', $ticket))->assertOk()
            ->assertViewIs('tickets.components.preview')
            ->assertSee($ticket->ticket_number)
            ->assertSee('คุณผู้แจ้ง')
            ->assertSee('รายละเอียดปัญหา')
            ->assertSee('เจ้าหน้าที่ที่เข้าร่วม')
            ->assertSee('ประวัติการดำเนินงาน')
            ->assertDontSee('value="start"', false)
            ->assertSee('data-ticket-join', false)
            ->assertSee('href="'.route('tickets.show', $ticket).'"', false);

        // เนื้อหา modal ล้วน ไม่มีเลย์เอาต์ของหน้า
        $this->assertStringNotContainsString('<html', $preview->getContent());

        app(TicketParticipantService::class)->join($ticket, $agent);
        $this->actingAs($agent)->get(route('tickets.preview', $ticket->fresh()))->assertOk()
            ->assertDontSee('data-ticket-join', false);

        $admin = $this->staff($this->department('Management'), role: 'admin');
        $this->actingAs($admin)->get(route('tickets.preview', $ticket))->assertOk()
            ->assertDontSee('value="start"', false)
            ->assertDontSee('data-ticket-join', false);
    }

    public function test_the_preview_is_authorized_like_the_detail_page(): void
    {
        $ticket = $this->publicTicket();

        $this->get(route('tickets.preview', $ticket))->assertRedirect(route('login'));
        $this->actingAs($this->staff($this->department('Sales')))->get(route('tickets.preview', $ticket))->assertForbidden();
        $this->actingAs($this->staff($this->department('Sales'), role: 'viewer'))->get(route('tickets.preview', $ticket))->assertForbidden();
    }
}
