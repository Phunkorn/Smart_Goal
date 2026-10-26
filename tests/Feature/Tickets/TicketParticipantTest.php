<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketParticipant;
use App\Policies\TicketPolicy;
use App\Services\TicketParticipantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * เจ้าหน้าที่หลายคนต่อหนึ่ง Ticket — ไม่มีผู้ถือคนเดียว ไม่มีการล็อก และการเข้าร่วมไม่เปลี่ยนสถานะ
 */
class TicketParticipantTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    private TicketParticipantService $participants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->participants = app(TicketParticipantService::class);
    }

    public function test_a_ticket_waiting_for_someone_to_take_it_cannot_be_joined(): void
    {
        $agent = $this->agent();
        $new = $this->publicTicket();

        try {
            $this->participants->join($new, $agent);
            $this->fail('joining a new ticket must be refused');
        } catch (AuthorizationException $denied) {
            $this->assertSame(TicketPolicy::JOIN_NEEDS_START, $denied->getMessage());
        }
        $this->assertSame(0, $new->participants()->count());
        $this->assertSame(Ticket::STATUS_NEW, $new->fresh()->status);

        // ผ่าน HTTP: AJAX ได้ 403 พร้อมข้อความ · form ปกติกลับหน้าเดิมพร้อมข้อความเดียวกัน
        $this->actingAs($agent)->postJson(route('tickets.participants.store', $new))
            ->assertForbidden()->assertJson(['ok' => false, 'message' => TicketPolicy::JOIN_NEEDS_START]);
        $this->actingAs($agent)->from(route('tickets.show', $new))->post(route('tickets.participants.store', $new))
            ->assertRedirect(route('tickets.show', $new))->assertSessionHasErrors(['status' => TicketPolicy::JOIN_NEEDS_START]);

        // คนที่กดรับงานเป็นผู้เข้าร่วมคนแรกอัตโนมัติ
        $taker = $this->agent();
        $this->startedBy($taker, $new);
        $this->assertTrue($new->fresh()->hasActiveParticipant($taker));
        $this->assertSame(1, $new->participants()->count());
    }

    public function test_joining_never_changes_the_status(): void
    {
        $agent = $this->agent();

        $active = $this->startedBy($this->agent());
        $this->participants->join($active, $agent);
        $this->assertSame(Ticket::STATUS_ACTIVE, $active->fresh()->status);
    }

    public function test_joining_twice_keeps_one_row_and_one_timeline_entry(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($this->agent());

        $this->assertTrue($this->participants->join($ticket, $agent));
        $this->assertFalse($this->participants->join($ticket, $agent));

        $this->assertSame(1, TicketParticipant::query()->where('ticket_id', $ticket->id)->where('user_id', $agent->id)->count());
        $this->assertSame(1, $ticket->activities()->where('type', TicketActivity::TYPE_JOINED)->where('user_id', $agent->id)->count());
    }

    public function test_a_row_inserted_by_a_concurrent_request_does_not_break_or_duplicate(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();

        // จำลองคำขออีกตัวที่เพิ่มแถวไปก่อน — unique (ticket_id, user_id) ต้องกันแถวที่สองไว้
        TicketParticipant::query()->forceCreate([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'user_name' => $agent->name,
            'joined_at' => now(),
        ]);

        $this->assertFalse($this->participants->joinLocked($ticket, $agent));
        $this->assertSame(1, TicketParticipant::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_several_agents_work_the_same_ticket_with_equal_rights(): void
    {
        $first = $this->agent();
        $second = $this->agent();
        $ticket = $this->startedBy($first);

        $this->participants->join($ticket, $second);

        // คนที่สองปิดงานได้ แม้คนแรกเป็นผู้รับงาน — ไม่มีการล็อก
        $closed = $this->transition($ticket, Ticket::ACTION_CLOSE, $second, ['note' => 'แก้ไขแล้ว']);
        $this->assertSame(Ticket::STATUS_CLOSED, $closed->status);
        $this->assertSame(2, $closed->participants()->count());
    }

    public function test_leaving_keeps_the_row_and_rejoining_reactivates_it(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $this->participants->leave($ticket, $agent);
        $row = TicketParticipant::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertNotNull($row->left_at);
        $this->assertFalse($ticket->fresh()->hasActiveParticipant($agent));

        $this->assertTrue($this->participants->join($ticket, $agent));
        $this->assertNull($row->fresh()->left_at);
        $this->assertSame(1, TicketParticipant::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(
            [TicketActivity::TYPE_JOINED, TicketActivity::TYPE_LEFT, TicketActivity::TYPE_JOINED],
            $ticket->activities()->whereIn('type', [TicketActivity::TYPE_JOINED, TicketActivity::TYPE_LEFT])->pluck('type')->all()
        );
    }

    public function test_the_coordinator_is_optional_must_be_a_participant_and_is_cleared_on_leave(): void
    {
        $lead = $this->agent();
        $helper = $this->agent();
        $outsider = $this->agent();
        $ticket = $this->startedBy($lead);
        $this->participants->join($ticket, $helper);

        $this->assertNull($ticket->fresh()->coordinator_id);

        $this->assertTrue($this->participants->setCoordinator($ticket, $lead, $helper));
        $this->assertSame($helper->id, $ticket->fresh()->coordinator_id);

        try {
            $this->participants->setCoordinator($ticket, $lead, $outsider);
            $this->fail('non-participant coordinator should be rejected');
        } catch (ValidationException) {
            $this->assertSame($helper->id, $ticket->fresh()->coordinator_id);
        }

        $this->participants->leave($ticket, $helper);
        $this->assertNull($ticket->fresh()->coordinator_id);
    }

    public function test_nobody_joins_or_leaves_a_closed_ticket(): void
    {
        $agent = $this->agent();
        $ticket = $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);

        $this->expectException(AuthorizationException::class);
        $this->participants->join($ticket, $this->agent());
    }

    public function test_non_agents_cannot_join(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->participants->join($this->publicTicket(), $this->staff($this->department('Sales')));
    }
}
