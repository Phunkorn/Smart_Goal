<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemNotification;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้ารายละเอียด Ticket ของเจ้าหน้าที่:
 *   หัว Ticket (เลข สถานะ เวลาแจ้ง) แล้วการ์ด "รายละเอียดปัญหา" ที่มีข้อเท็จจริงพร้อมไอคอน
 *   การ์ดการดำเนินการมีสามปุ่ม: ปิดงาน (ขั้นตอนที่รวมผลการแก้ไขและรูป) · ติดตามงาน · ยกเลิกงาน (ปิดแบบยกเลิก)
 */
class TicketDetailLayoutTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    public function test_the_header_is_number_status_and_time_and_the_problem_card_shows_facts_with_icons(): void
    {
        $agent = $this->agent();
        $requester = $this->staff($this->department('Account'));
        $ticket = $this->startedBy($agent, app(TicketSubmissionService::class)->submitInternal($requester, [
            'description' => "เครื่องพิมพ์ชั้น 2 พิมพ์ไม่ออก\nไฟกระพริบสีส้ม",
            'location_name' => 'สาขาบางนา',
        ]));

        $html = $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $header = substr($html, (int) strpos($html, '<header class="ticket-detail__header'));
        $header = substr($header, 0, (int) strpos($header, '</header>'));

        // หัว: เลข สถานะ และเวลาแจ้งเท่านั้น — ไม่มีรายละเอียดหรือบรรทัดสรุปซ้ำกับการ์ดถัดไป
        $this->assertStringContainsString($ticket->ticket_number, $header);
        $this->assertStringContainsString('ticket-status', $header);
        $this->assertStringContainsString('แจ้งเมื่อ', $header);
        $this->assertStringNotContainsString('ticket-detail__description', $header);
        $this->assertStringNotContainsString('ticket-facts', $header);
        $this->assertStringNotContainsString('ticket-detail__summary', $html);

        // การ์ด "รายละเอียดปัญหา": ไอคอนหัวการ์ด · ปัญหาเต็ม · ข้อเท็จจริงพร้อมไอคอนของแต่ละช่อง
        $card = substr($html, (int) strpos($html, '<section class="ticket-detail__problem'));
        $card = substr($card, 0, (int) strpos($card, '</section>'));
        $this->assertStringContainsString('<span class="ticket-panel__icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>', $card);
        $this->assertStringContainsString("<p class=\"ticket-detail__description\">เครื่องพิมพ์ชั้น 2 พิมพ์ไม่ออก\nไฟกระพริบสีส้ม</p>", $card);
        foreach ([
            'bi-person' => 'ผู้แจ้ง', 'bi-building' => 'แผนก', 'bi-geo-alt-fill' => 'สาขา/สถานที่',
            'bi-chat-square-text' => 'ช่องทางที่แจ้ง', 'bi-calendar3' => 'รับงานครั้งแรก',
        ] as $icon => $fact) {
            $this->assertStringContainsString('<div class="ticket-fact bi '.$icon.'"><dt>'.$fact.'</dt>', $card, $fact);
        }
        $this->assertStringContainsString('<dd>Account</dd>', $card);
        $this->assertStringContainsString('<dd>สาขาบางนา</dd>', $card);
        $this->assertSame(1, substr_count($html, 'class="ticket-facts"'));

        // modal ดูรายละเอียดของคิวใช้การ์ดเดียวกัน
        $this->actingAs($agent)->get(route('tickets.preview', $ticket))->assertOk()
            ->assertSee('ticket-detail__problem', false)
            ->assertSee('<div class="ticket-fact bi bi-geo-alt-fill"><dt>สาขา/สถานที่</dt>', false);
    }

    public function test_the_action_card_has_close_follow_up_and_cancel_only(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $html = $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $card = substr($html, (int) strpos($html, '<section class="ticket-detail__actions'));
        $card = substr($card, 0, (int) strpos($card, '</section>'));

        // ปิดงาน (เต็มแถว) → ติดตามงาน · ยกเลิกงาน (ครึ่งแถว)
        $order = array_map(fn (string $needle) => strpos($card, $needle), [
            'ticket-actions__button--close',
            'data-bs-target="#ticket-modal-follow-up" data-ticket-confirm-open',
            'data-bs-target="#ticket-modal-cancel" data-ticket-confirm-open',
        ]);
        $this->assertNotContains(false, $order);
        $this->assertSame($order, collect($order)->sort()->values()->all());
        $this->assertStringContainsString('<div class="ticket-actions__row">', $card);
        $this->assertMatchesRegularExpression('~ticket-button--warning ticket-actions__button"[^>]*>\s*<i class="bi bi-hourglass-split" aria-hidden="true"></i> ติดตามงาน~s', $card);
        $this->assertMatchesRegularExpression('~ticket-button--danger ticket-actions__button"[^>]*>\s*<i class="bi bi-x-circle" aria-hidden="true"></i> ยกเลิกงาน~s', $card);
        $this->assertMatchesRegularExpression('~data-ticket-confirm="ต้องการยกเลิกงานนี้ใช่ไหม\?~', $card);

        // ไม่มีปุ่มบันทึก / ถ่าย-แนบรูป / ออกจาก Ticket (ปิดงานเป็นขั้นตอนที่รวมผลการแก้ไขและรูปแล้ว)
        foreach (['data-ticket-note-open', 'data-ticket-note-photo', 'data-ticket-leave', 'ticket-note-modal', 'ticket-detail__note'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
        $modal = substr($html, (int) strpos($html, 'id="ticket-modal-cancel"'));
        $modal = substr($modal, 0, (int) strpos($modal, '</form>'));
        $this->assertStringContainsString('name="action" value="cancel"', $modal);
        $this->assertMatchesRegularExpression('~<textarea id="ticket-modal-cancel-note" name="note"[^>]*required~s', $modal);
        $this->assertStringContainsString('ticket-button--danger', $modal);
    }

    public function test_cancelling_closes_the_ticket_with_a_reason_the_requester_can_read(): void
    {
        $requester = $this->staff($this->department('Sales'));
        $agent = $this->agent();
        $ticket = $this->startedBy($agent, $this->ticketFrom($requester));

        // ต้องมีเหตุผล
        $this->actingAs($agent)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => 'cancel', 'expected_status' => 'active', 'note' => ''])
            ->assertSessionHasErrors(['note' => 'กรุณาระบุเหตุผลที่ยกเลิกงาน']);
        $this->assertMatchesRegularExpression('/id="ticket-modal-cancel"[^>]*data-open-on-load="true"/s',
            $this->actingAs($agent)->get(route('tickets.show', $ticket))->getContent());

        $this->actingAs($agent)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => 'cancel', 'expected_status' => 'active', 'note' => 'ผู้แจ้งแก้ได้เองแล้ว'])
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame($agent->id, (int) $ticket->closed_by);
        $activity = TicketActivity::query()->where('ticket_id', $ticket->id)->where('to_status', Ticket::STATUS_CLOSED)->sole();
        $this->assertSame('cancel', $activity->data['action']);

        // timeline ของเจ้าหน้าที่: "ยกเลิกงาน" + เหตุผล · ผู้แจ้งเห็นเหตุผลในความคืบหน้า และได้แจ้งเตือน
        $this->actingAs($agent)->get(route('tickets.show', $ticket))
            ->assertSee('ยกเลิกงาน')->assertSee('เหตุผลที่ยกเลิก')->assertSee('ผู้แจ้งแก้ได้เองแล้ว')
            ->assertSee('data-bs-target="#ticket-modal-reopen"', false);
        $this->actingAs($requester)->get(route('tickets.index', ['ticket' => $ticket->id]))
            ->assertSee('ยกเลิกงาน')->assertSee('ผู้แจ้งแก้ได้เองแล้ว');
        $this->assertSame(1, SystemNotification::query()->where('user_id', $requester->id)
            ->where('type', 'ticket_status_changed')->where('title', 'like', '%ยกเลิกงาน%')->count());

        // ผู้แจ้งแจ้งใหม่ได้ทันที (ใบที่ยกเลิกนับเป็นใบที่ปิดแล้ว)
        $this->ticketFrom($requester, 'ปัญหาใหม่หลังยกเลิก');
        $this->assertSame(2, Ticket::query()->where('requester_id', $requester->id)->count());
    }

    public function test_new_tickets_can_be_cancelled_by_an_agent_without_claiming_them(): void
    {
        $agent = $this->agent();
        $waiting = $this->publicTicket();

        $page = $this->actingAs($agent)->get(route('tickets.index'))->assertOk();
        $page->assertSee('data-bs-target="#ticket-modal-cancel-'.$waiting->id.'"', false)
            ->assertSee('aria-label="ยกเลิกงาน '.$waiting->ticket_number.'"', false);

        $this->actingAs($agent)->patch(route('tickets.status.update', $waiting), [
            'action' => 'cancel', 'expected_status' => 'new', 'note' => '',
        ])->assertSessionHasErrors('note');

        $this->actingAs($agent)->patch(route('tickets.status.update', $waiting), [
            'action' => 'cancel', 'expected_status' => 'new', 'note' => 'แจ้งซ้ำกับใบเดิม',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'ยกเลิกงานแล้ว — สถานะปัจจุบัน: ยกเลิก');

        $this->assertSame(Ticket::STATUS_CLOSED, $waiting->fresh()->status);
        $this->assertSame(0, $waiting->participants()->count());
        $queue = $this->actingAs($agent)->get(route('tickets.index'))->assertOk()->getContent();
        $row = substr($queue, (int) strpos($queue, 'id="ticket-row-'.$waiting->id.'"'));
        $row = substr($row, 0, (int) strpos($row, '</tr>'));
        $this->assertStringContainsString('>ยกเลิก</span>', $row);
        $this->assertStringContainsString('เหตุผล: แจ้งซ้ำกับใบเดิม', $row);
        $this->assertStringNotContainsString('>ปิดงาน</span>', $row);
        $this->assertStringNotContainsString('data-bs-target="#ticket-modal-cancel-'.$waiting->id.'"', $row);
    }

    public function test_queue_uses_the_latest_closing_action_after_reopening(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);
        $this->transition($ticket, Ticket::ACTION_CANCEL, $agent, ['note' => 'แจ้งซ้ำ']);
        $this->transition($ticket, Ticket::ACTION_REOPEN, $agent, ['note' => 'ตรวจพบว่าเป็นคนละปัญหา']);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'แก้ไขเสร็จ']);

        $html = $this->actingAs($agent)->get(route('tickets.index'))->assertOk()->getContent();
        $row = substr($html, (int) strpos($html, 'id="ticket-row-'.$ticket->id.'"'));
        $row = substr($row, 0, (int) strpos($row, '</tr>'));
        $this->assertStringContainsString('>ปิดงาน</span>', $row);
        $this->assertStringNotContainsString('เหตุผล: แจ้งซ้ำ', $row);
    }

    public function test_only_agents_can_cancel_new_tickets_and_only_participants_can_cancel_started_tickets(): void
    {
        $lead = $this->agent();
        $ticket = $this->startedBy($lead);

        // agent ที่ยังไม่เข้าร่วม: ไม่มีปุ่มยกเลิก และส่งตรงก็ถูกปฏิเสธ
        $outsider = $this->agent();
        $this->actingAs($outsider)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('data-ticket-join', false)
            ->assertDontSee('data-bs-target="#ticket-modal-cancel"', false);
        $this->actingAs($outsider)->patch(route('tickets.status.update', $ticket), ['action' => 'cancel', 'expected_status' => 'active', 'note' => 'x'])
            ->assertForbidden();
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);

        // รอรับงาน: agent ยกเลิกตรงได้ แต่ admin ที่ไม่ใช่ agent ทำไม่ได้
        $waiting = $this->publicTicket();
        $admin = $this->staff($this->department('Management'), role: 'admin');
        $this->actingAs($admin)->get(route('tickets.index'))->assertOk()
            ->assertDontSee('data-bs-target="#ticket-modal-cancel-'.$waiting->id.'"', false);
        $this->actingAs($admin)->from(route('tickets.index'))
            ->patch(route('tickets.status.update', $waiting), ['action' => 'cancel', 'expected_status' => 'new', 'note' => 'x'])
            ->assertForbidden();
        $this->assertSame(Ticket::STATUS_NEW, $waiting->fresh()->status);

        // ปิดแล้ว: เหลือเปิดงานใหม่ ไม่มีปิด/ยกเลิก และมีข้อความว่า Ticket ปิดแล้ว
        $this->transition($ticket, Ticket::ACTION_CLOSE, $lead, ['note' => 'เรียบร้อย']);
        $this->actingAs($lead)->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('data-bs-target="#ticket-modal-cancel"', false)
            ->assertSee('data-bs-target="#ticket-modal-reopen"', false)
            ->assertSee('Ticket นี้ปิดแล้ว');
    }
}
