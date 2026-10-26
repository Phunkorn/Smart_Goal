<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketParticipant;
use App\Services\TicketNoteService;
use App\Services\TicketParticipantService;
use App\Services\TicketSubmissionService;
use App\Support\TicketAgentAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ประวัติของ Ticket ต้องไม่หายเมื่อข้อมูลต้นทางเปลี่ยน
 *
 * คำสั่ง trash:purge-expired ลบผู้ใช้ถาวรเมื่อพ้นระยะเก็บ (TrashRetention::forceDeleteEntity)
 * จึงต้องพิสูจน์ว่าการลบถาวรไม่ลบประวัติ และไม่ทำให้การลบนั้นล้ม
 */
class TicketHistoryRetentionTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_force_deleting_an_agent_keeps_participation_and_timeline_with_their_name(): void
    {
        $agent = $this->agent();
        $name = $agent->name;
        $ticket = $this->transition($this->startedBy($agent), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);

        $agent->forceDelete();

        $participant = TicketParticipant::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertNull($participant->user_id);
        $this->assertSame($name, $participant->user_name);

        $byAgent = $ticket->activities()->where('user_name', $name)->get();
        $this->assertCount(3, $byAgent); // เข้าร่วม รับงาน ปิดงาน
        $this->assertTrue($byAgent->every(fn (TicketActivity $a) => $a->user_id === null));

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->closed_by);
        $this->assertNotNull($fresh->closed_at);
    }

    public function test_force_deleting_the_requester_keeps_the_ticket_and_their_name(): void
    {
        $requester = $this->staff($this->department('Sales'));
        $ticket = $this->ticketFrom($requester);

        $requester->forceDelete();

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->requester_id);
        $this->assertSame($requester->name, $fresh->requester_name);
        $this->assertSame('Sales', $fresh->requester_department_name);
    }

    public function test_soft_deleted_users_still_resolve_through_relations(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $agent->delete();

        $this->assertSame($agent->name, TicketParticipant::query()->where('ticket_id', $ticket->id)->sole()->user->name);
    }

    public function test_deactivating_an_account_keeps_history_but_removes_agent_rights(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $agent->update(['is_active' => false]);

        $this->assertFalse(TicketAgentAccess::isAgent($agent->fresh()));
        $this->assertSame(1, $ticket->participants()->count());
        $this->assertTrue($ticket->fresh()->hasActiveParticipant($agent));
    }

    public function test_renaming_or_deleting_department_and_location_keeps_the_snapshot(): void
    {
        $sales = $this->department('Sales');
        $location = $this->location('สาขา 1');
        $requester = $this->staff($sales);
        $ticket = app(TicketSubmissionService::class)->submitInternal($requester, [
            'description' => 'จอไม่ติด',
            'location_id' => $location->id,
        ]);

        $sales->update(['department_name' => 'Sales & Marketing']);
        $location->update(['name' => 'สาขาบางนา']);
        $this->assertSame('Sales', $ticket->fresh()->requester_department_name);
        $this->assertSame('สาขา 1', $ticket->fresh()->location_name);

        $requester->forceDelete();
        $sales->delete();
        $fresh = $ticket->fresh();
        $this->assertNull($fresh->requester_department_id);
        $this->assertSame('Sales', $fresh->requester_department_name);
    }

    public function test_a_ticket_with_history_cannot_be_deleted_by_accident(): void
    {
        $ticket = $this->startedBy($this->agent());

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->delete();
    }

    public function test_leaving_keeps_the_participation_for_individual_kpi(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        app(TicketParticipantService::class)->leave($ticket, $agent);

        $this->assertSame(1, TicketParticipant::query()->where('user_id', $agent->id)->count());
    }

    /**
     * ตัวตนทางประวัติ (migration 2026_10_23_000008) ต้องถูกตั้งตั้งแต่สร้างประวัติ ในทุกเส้นทางที่เขียน
     * และต้องอยู่รอดหลังบัญชีถูกลบถาวร — ส่วน closed_by_ref_id ล้างพร้อม closed_by เมื่อเปิดงานใหม่
     */
    public function test_every_history_write_records_an_identity_that_survives_a_force_delete(): void
    {
        $lead = $this->agent();
        $helper = $this->agent();
        $requester = $this->staff($this->department('Sales'));

        $ticket = $this->ticketFrom($requester);                         // created
        $this->startedBy($lead, $ticket);                                // joined (insertOrIgnore) + status_changed
        app(TicketParticipantService::class)->join($ticket, $helper);    // joined
        app(TicketParticipantService::class)->setCoordinator($ticket, $lead, $helper); // coordinator_set
        app(TicketParticipantService::class)->leave($ticket, $helper);   // left
        app(TicketParticipantService::class)->join($ticket, $helper);    // rejoin = update แถวเดิม
        app(TicketNoteService::class)->add($ticket, $helper, 'เปลี่ยนสายแลนแล้ว'); // note
        $this->transition($ticket, Ticket::ACTION_CLOSE, $helper, ['note' => 'จบ']);

        $this->assertSame($helper->id, (int) $ticket->fresh()->closed_by_ref_id);
        $this->assertTrue(TicketParticipant::query()->get()->every(fn ($row) => (int) $row->user_ref_id === (int) $row->user_id));
        $this->assertTrue(TicketActivity::query()->get()->every(fn ($row) => (int) $row->user_ref_id === (int) $row->user_id));
        $this->assertSame(
            ['coordinator_set', 'created', 'joined', 'left', 'note', 'status_changed'],
            TicketActivity::query()->whereNotNull('user_ref_id')->distinct()->orderBy('type')->pluck('type')->all(),
        );

        $helper->forceDelete();
        $requester->forceDelete();

        $this->assertNull($ticket->fresh()->closed_by);
        $this->assertSame($helper->id, (int) $ticket->fresh()->closed_by_ref_id);
        $this->assertSame([$helper->id], TicketParticipant::query()->whereNull('user_id')->pluck('user_ref_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, TicketActivity::query()->whereNull('user_id')->whereNull('user_ref_id')->count(), 'ทุกเหตุการณ์ยังรู้ว่าเป็นของใคร');
        $this->assertSame($requester->id, (int) TicketActivity::query()->where('type', 'created')->value('user_ref_id'));

        // เปิดงานใหม่ล้างผู้ปิดล่าสุดทั้ง FK และ ref ปิดอีกครั้งได้ผู้ปิดคนใหม่
        $this->transition($ticket, Ticket::ACTION_REOPEN, $lead, ['note' => 'ยังเสีย']);
        $this->assertNull($ticket->fresh()->closed_by_ref_id);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $lead, ['note' => 'จบจริง']);
        $this->assertSame($lead->id, (int) $ticket->fresh()->closed_by_ref_id);
    }
}
