<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\TicketSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * การสร้าง Ticket: เลข Ticket และ snapshot ของผู้แจ้ง แผนก สาขา
 */
class TicketCreationTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_each_ticket_gets_a_unique_number_from_its_id(): void
    {
        $first = $this->publicTicket();
        $second = $this->publicTicket();

        $this->assertSame(Ticket::formatNumber($first->id), $first->ticket_number);
        $this->assertMatchesRegularExpression('/^TK-\d{6}$/', $first->ticket_number);
        $this->assertNotSame($first->ticket_number, $second->ticket_number);
    }

    public function test_internal_requester_and_department_come_from_the_account_not_the_input(): void
    {
        $sales = $this->department('Sales');
        $hr = $this->department('HR');
        $requester = $this->staff($sales);

        $ticket = app(TicketSubmissionService::class)->submitInternal($requester, [
            'description' => 'เมาส์เสีย',
            'location_name' => 'ชั้น 3',
            // ค่าปลอมที่ต้องถูกเพิกเฉย
            'requester_department_id' => $hr->id,
            'requester_id' => 999,
            'status' => Ticket::STATUS_CLOSED,
        ]);

        $this->assertSame(Ticket::SOURCE_INTERNAL, $ticket->source);
        $this->assertSame($requester->id, $ticket->requester_id);
        $this->assertSame($requester->name, $ticket->requester_name);
        $this->assertSame($sales->id, $ticket->requester_department_id);
        $this->assertSame('Sales', $ticket->requester_department_name);
        $this->assertSame(Ticket::STATUS_NEW, $ticket->status);
    }

    public function test_a_public_ticket_has_no_account_or_department(): void
    {
        $ticket = $this->publicTicket('คุณสมศรี');

        $this->assertSame(Ticket::SOURCE_PUBLIC, $ticket->source);
        $this->assertNull($ticket->requester_id);
        $this->assertNull($ticket->requester_department_id);
        $this->assertSame('คุณสมศรี', $ticket->requester_name);
        $this->assertSame('203.0.113.5', $ticket->submitter_ip);

        $created = $ticket->activities()->sole();
        $this->assertSame(TicketActivity::TYPE_CREATED, $created->type);
        $this->assertNull($created->user_id);
        $this->assertSame('คุณสมศรี', $created->user_name);
    }

    public function test_public_submission_needs_only_name_location_and_description(): void
    {
        $ticket = app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'ผู้แจ้ง',
            'description' => 'จอไม่ติด',
            'location_name' => 'สาขา 1',
        ], null);

        $this->assertNull($ticket->contact);

        foreach (['requester_name', 'description', 'location_name'] as $missing) {
            $input = ['requester_name' => 'ผู้แจ้ง', 'description' => 'จอไม่ติด', 'location_name' => 'สาขา 1'];
            $input[$missing] = '  ';

            try {
                app(TicketSubmissionService::class)->submitPublic($input, null);
                $this->fail("{$missing} should be required");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($missing, $e->errors());
            }
        }
    }

    public function test_a_listed_location_is_snapshotted_by_name(): void
    {
        $location = $this->location('สาขาบางนา');

        $ticket = app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'ผู้แจ้ง',
            'description' => 'เน็ตช้า',
            'location_id' => $location->id,
        ], null);

        $this->assertSame($location->id, $ticket->location_id);
        $this->assertSame('สาขาบางนา', $ticket->location_name);
    }

    public function test_an_inactive_location_cannot_be_chosen(): void
    {
        $closed = $this->location('สาขาที่ปิดแล้ว', active: false);

        $this->expectException(ValidationException::class);
        app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'ผู้แจ้ง',
            'description' => 'เน็ตช้า',
            'location_id' => $closed->id,
        ], null);
    }

    public function test_viewers_cannot_submit_internal_tickets(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->ticketFrom($this->staff($this->department('Sales'), role: 'viewer'));
    }

    public function test_the_card_summary_is_the_first_non_empty_line(): void
    {
        $ticket = $this->ticketFrom($this->staff(), "\n  ปริ้นเตอร์ชั้น 2 กระดาษติด  \nลองดึงออกแล้วไม่ได้");
        $this->assertSame('ปริ้นเตอร์ชั้น 2 กระดาษติด', $ticket->summary);

        $long = $this->ticketFrom($this->staff(), str_repeat('ก', 200));
        $this->assertSame(Ticket::SUMMARY_LENGTH + 1, mb_strlen($long->summary));
        $this->assertStringEndsWith('…', $long->summary);
    }
}
