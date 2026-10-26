<?php

namespace Tests\Feature\Tickets;

use App\Exceptions\TicketStatusConflict;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\TicketWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * State machine ของ Ticket: new → active → follow_up ⇄ active → closed → (reopen) active
 */
class TicketWorkflowTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_a_new_ticket_starts_as_new(): void
    {
        $this->assertSame(Ticket::STATUS_NEW, $this->publicTicket()->status);
    }

    public function test_taking_a_ticket_makes_it_active_joins_the_agent_and_records_first_response(): void
    {
        Carbon::setTestNow('2026-10-23 03:00:00');
        $agent = $this->agent();

        $ticket = $this->startedBy($agent);

        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertTrue($ticket->first_responded_at->equalTo(now()));
        $this->assertTrue($ticket->hasActiveParticipant($agent));
        $this->assertSame(
            [TicketActivity::TYPE_CREATED, TicketActivity::TYPE_JOINED, TicketActivity::TYPE_STATUS_CHANGED],
            $ticket->activities()->pluck('type')->all()
        );
    }

    public function test_a_ticket_cannot_be_closed_before_it_is_taken(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();

        $this->expectException(ValidationException::class);

        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'ซ้ำ']);
    }

    public function test_the_full_cycle_keeps_first_response_and_history_through_a_reopen(): void
    {
        Carbon::setTestNow('2026-10-23 03:00:00');
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);
        $firstResponse = $ticket->first_responded_at;

        Carbon::setTestNow('2026-10-23 05:00:00');
        $ticket = $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, [
            'note' => 'รออะไหล่',
            'follow_up_date' => '2026-10-25',
        ]);
        $this->assertSame(Ticket::STATUS_FOLLOW_UP, $ticket->status);
        $this->assertSame('2026-10-25', $ticket->follow_up_date->toDateString());

        $ticket = $this->transition($ticket, Ticket::ACTION_RESUME, $agent);
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertNull($ticket->follow_up_date);

        Carbon::setTestNow('2026-10-23 07:00:00');
        $ticket = $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'เปลี่ยนอะไหล่แล้ว']);
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->status);
        $this->assertTrue($ticket->closed_at->equalTo(now()));
        $this->assertSame($agent->id, $ticket->closed_by);

        Carbon::setTestNow('2026-10-24 01:00:00');
        $ticket = $this->transition($ticket, Ticket::ACTION_REOPEN, $agent, ['note' => 'อาการกลับมาอีก']);
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertNull($ticket->closed_at);
        $this->assertNull($ticket->closed_by);
        $this->assertSame(1, $ticket->reopen_count);
        $this->assertTrue($ticket->first_responded_at->equalTo($firstResponse), 'first response must not move on reopen');

        $changes = $ticket->activities()->where('type', TicketActivity::TYPE_STATUS_CHANGED)->get();
        $this->assertSame(
            [['new', 'active'], ['active', 'follow_up'], ['follow_up', 'active'], ['active', 'closed'], ['closed', 'active']],
            $changes->map(fn (TicketActivity $a) => [$a->from_status, $a->to_status])->all()
        );
        $this->assertSame('เปลี่ยนอะไหล่แล้ว', $changes[3]->body);
        $this->assertSame('2026-10-25', $changes[1]->data['follow_up_date']);
    }

    public function test_every_illegal_transition_is_rejected(): void
    {
        $agent = $this->agent();
        $byStatus = [
            Ticket::STATUS_NEW => $this->publicTicket(),
            Ticket::STATUS_ACTIVE => $this->startedBy($agent),
            Ticket::STATUS_FOLLOW_UP => $this->transition($this->startedBy($agent), Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รอ']),
            Ticket::STATUS_CLOSED => $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']),
        ];

        foreach ($byStatus as $status => $ticket) {
            foreach (array_keys(Ticket::TRANSITIONS) as $action) {
                if (Ticket::allowsTransition($status, $action)) {
                    continue;
                }

                try {
                    $this->transition($ticket, $action, $agent, ['note' => 'x']);
                    $this->fail("{$action} from {$status} should be rejected");
                } catch (ValidationException) {
                    $this->assertSame($status, $ticket->fresh()->status, "{$action} from {$status}");
                }
            }
        }
    }

    public function test_a_stale_screen_gets_a_conflict_instead_of_overwriting(): void
    {
        $first = $this->agent();
        $second = $this->agent();
        $ticket = $this->publicTicket();

        app(TicketWorkflowService::class)->transition($ticket, Ticket::ACTION_START, $first, Ticket::STATUS_NEW);

        try {
            app(TicketWorkflowService::class)->transition($ticket, Ticket::ACTION_START, $second, Ticket::STATUS_NEW);
            $this->fail('second start should conflict');
        } catch (TicketStatusConflict $conflict) {
            $this->assertSame(Ticket::STATUS_ACTIVE, $conflict->currentStatus);
        }

        $this->assertSame(1, $ticket->activities()->where('type', TicketActivity::TYPE_STATUS_CHANGED)->count());
        $this->assertFalse($ticket->fresh()->hasActiveParticipant($second));
    }

    public function test_follow_up_close_and_reopen_require_a_note(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        foreach ([Ticket::ACTION_FOLLOW_UP, Ticket::ACTION_CLOSE] as $action) {
            try {
                $this->transition($ticket, $action, $agent, ['note' => '   ']);
                $this->fail("{$action} without note should fail");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('note', $e->errors());
            }
        }

        $closed = $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->expectException(ValidationException::class);
        $this->transition($closed, Ticket::ACTION_REOPEN, $agent);
    }

    public function test_an_invalid_follow_up_date_is_rejected(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $this->expectException(ValidationException::class);
        $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รอ', 'follow_up_date' => '2026-02-30']);
    }

    public function test_a_non_participant_agent_cannot_close_and_nothing_changes(): void
    {
        $worker = $this->agent();
        $outsider = $this->agent();
        $ticket = $this->startedBy($worker);

        try {
            $this->transition($ticket, Ticket::ACTION_CLOSE, $outsider, ['note' => 'จบ']);
            $this->fail('non-participant close should be denied');
        } catch (AuthorizationException) {
            $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);
        }
    }

    public function test_a_department_head_cannot_take_their_departments_ticket(): void
    {
        $sales = $this->department('Sales');
        $ticket = $this->ticketFrom($this->staff($sales));

        $this->expectException(AuthorizationException::class);
        $this->transition($ticket, Ticket::ACTION_START, $this->staff($sales, head: true));
    }
}
