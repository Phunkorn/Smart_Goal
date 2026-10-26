<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Services\TicketParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ทุก route การดำเนินงานของ Ticket ผ่าน HTTP จริง — ตรวจสิทธิ์จริง ไม่พึ่งการซ่อนปุ่ม
 */
class TicketActionRoutesTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    public function test_taking_a_ticket_over_http(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();

        $this->actingAs($agent)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => 'new'])
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertNotNull($ticket->first_responded_at);
        $this->assertTrue($ticket->hasActiveParticipant($agent));
    }

    public function test_the_second_agent_of_a_race_gets_a_clear_conflict_message(): void
    {
        $first = $this->agent();
        $second = $this->agent();
        $ticket = $this->publicTicket();

        $this->actingAs($first)->patch(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => 'new']);

        $this->actingAs($second)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => 'new'])
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHasErrors(['status' => 'มีเจ้าหน้าที่คนอื่นเปลี่ยนสถานะเป็น "กำลังดำเนินการ" ไปก่อนแล้ว หน้านี้แสดงสถานะล่าสุดให้แล้ว กรุณาตรวจสอบอีกครั้ง']);

        $this->actingAs($second)
            ->patchJson(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => 'new'])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'status' => 'active']);

        // หน้าที่โหลดใหม่บอกผู้ใช้ว่าเกิดอะไรขึ้น
        $this->actingAs($second)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => 'start', 'expected_status' => 'new']);
        $this->actingAs($second)->get(route('tickets.show', $ticket))
            ->assertSee('มีเจ้าหน้าที่คนอื่นเปลี่ยนสถานะ')
            ->assertSee('role="alert"', false);
    }

    public function test_full_workflow_over_http_with_required_notes(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);
        $url = route('tickets.status.update', $ticket);

        $this->actingAs($agent)->patch($url, ['action' => 'follow_up', 'expected_status' => 'active', 'note' => ''])
            ->assertSessionHasErrors('note');
        $this->actingAs($agent)->patch($url, ['action' => 'follow_up', 'expected_status' => 'active', 'note' => 'รออะไหล่', 'follow_up_date' => '2000-01-01'])
            ->assertSessionHasErrors('follow_up_date');
        $this->actingAs($agent)->patch($url, ['action' => 'follow_up', 'expected_status' => 'active', 'note' => 'รออะไหล่', 'follow_up_date' => now('Asia/Bangkok')->addDay()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->actingAs($agent)->patch($url, ['action' => 'resume', 'expected_status' => 'follow_up'])->assertSessionHasNoErrors();
        $this->actingAs($agent)->patch($url, ['action' => 'close', 'expected_status' => 'active', 'note' => 'แก้ไขเรียบร้อย'])->assertSessionHasNoErrors();
        $this->actingAs($agent)->patch($url, ['action' => 'reopen', 'expected_status' => 'closed', 'note' => 'อาการกลับมา'])->assertSessionHasNoErrors();

        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);
        $this->assertSame(1, $ticket->fresh()->reopen_count);
        $this->assertSame(5, $ticket->activities()->where('type', TicketActivity::TYPE_STATUS_CHANGED)->count());
    }

    public function test_a_ticket_cannot_jump_from_new_to_closed_over_http(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();
        // ข้อมูลเก่าที่มีผู้เข้าร่วมบน Ticket รอรับงาน (ก่อนกติกา "รับงานก่อนเข้าร่วม") — ก็ยังปิดข้ามขั้นไม่ได้
        app(TicketParticipantService::class)->joinLocked($ticket, $agent);

        $this->actingAs($agent)->patch(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'new', 'note' => 'ซ้ำ'])
            ->assertSessionHasErrors('action');

        $this->assertSame(Ticket::STATUS_NEW, $ticket->fresh()->status);
    }

    public function test_people_without_rights_are_refused_on_every_action_route(): void
    {
        $sales = $this->department('Sales');
        $requester = $this->staff($sales);
        $worker = $this->agent();
        $ticket = $this->startedBy($worker, $this->ticketFrom($requester));

        $routes = [
            ['post', route('tickets.participants.store', $ticket), []],
            ['delete', route('tickets.participants.destroy', $ticket), []],
            ['patch', route('tickets.coordinator.update', $ticket), ['coordinator_id' => $worker->id]],
            ['patch', route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'active', 'note' => 'x']],
        ];

        foreach ([
            'requester' => $requester,
            'department head' => $this->staff($sales, head: true),
            'admin (not agent)' => $this->staff($this->department('Management'), role: 'admin'),
            'other employee' => $this->staff($sales),
        ] as $who => $user) {
            foreach ($routes as [$method, $url, $data]) {
                $this->assertSame(403, $this->actingAs($user)->{$method}($url, $data)->status(), "{$who}: {$method} {$url}");
            }
        }

        $viewer = $this->staff($sales, role: 'viewer');
        foreach ($routes as [$method, $url, $data]) {
            $this->actingAs($viewer)->{$method}($url, $data)->assertForbidden();
        }
        $this->actingAs($viewer)->post(route('tickets.notes.store', $ticket), ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->staff($sales))->post(route('tickets.notes.store', $ticket), ['body' => 'x'])->assertForbidden();

        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);
        $this->assertSame(1, $ticket->participants()->count());
    }

    public function test_join_and_leave_over_http_keep_history(): void
    {
        $worker = $this->agent();
        $helper = $this->agent();
        $ticket = $this->startedBy($worker);

        $this->actingAs($helper)->post(route('tickets.participants.store', $ticket))->assertSessionHas('success');
        $this->actingAs($helper)->post(route('tickets.participants.store', $ticket))->assertSessionHas('success', 'คุณเข้าร่วม Ticket นี้อยู่แล้ว');
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);

        $this->actingAs($helper)->delete(route('tickets.participants.destroy', $ticket))->assertSessionHas('success');
        $this->assertFalse($ticket->fresh()->hasActiveParticipant($helper));
        $this->assertSame(2, $ticket->participants()->count());

        $this->actingAs($helper)->postJson(route('tickets.participants.store', $ticket))->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue($ticket->fresh()->hasActiveParticipant($helper));
    }

    public function test_coordinator_must_be_a_current_participant_who_is_still_an_agent(): void
    {
        $lead = $this->agent();
        $helper = $this->agent();
        $ticket = $this->startedBy($lead);
        app(TicketParticipantService::class)->join($ticket, $helper);
        $url = route('tickets.coordinator.update', $ticket);

        $this->actingAs($lead)->patch($url, ['coordinator_id' => $helper->id])->assertSessionHasNoErrors();
        $this->assertSame($helper->id, $ticket->fresh()->coordinator_id);

        // ผู้เข้าร่วมที่ถูกปิดสิทธิ์ agent แล้ว เป็นผู้ประสานงานไม่ได้
        $lostRights = $this->agent();
        app(TicketParticipantService::class)->join($ticket, $lostRights);
        $this->override($lostRights, enabled: false);
        $this->actingAs($lead)->patch($url, ['coordinator_id' => $lostRights->id])->assertSessionHasErrors('coordinator_id');

        $outsider = $this->agent();
        $this->actingAs($lead)->patch($url, ['coordinator_id' => $outsider->id])->assertSessionHasErrors('coordinator_id');

        $this->actingAs($lead)->patch($url, ['coordinator_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($ticket->fresh()->coordinator_id);
    }

    public function test_notes_with_photos_are_saved_together(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $this->actingAs($agent)->post(route('tickets.notes.store', $ticket), [
            'body' => 'เปลี่ยนสาย LAN แล้ว',
            'photos' => [$this->photo('before.jpg'), $this->photo('after.jpg')],
        ])->assertSessionHas('success');

        $note = $ticket->activities()->where('type', TicketActivity::TYPE_NOTE)->sole();
        $this->assertSame('เปลี่ยนสาย LAN แล้ว', $note->body);
        $this->assertSame(2, TicketAttachment::query()->where('ticket_activity_id', $note->id)->count());

        $this->actingAs($agent)->post(route('tickets.notes.store', $ticket), ['body' => ''])->assertSessionHasErrors('body');
        $this->actingAs($agent)->post(route('tickets.notes.store', $ticket), ['body' => 'x', 'photos' => [$this->decompressionBombPng()]])
            ->assertSessionHasErrors('photos.0');
        $this->assertSame(1, $ticket->activities()->where('type', TicketActivity::TYPE_NOTE)->count());
    }

    public function test_the_requester_cannot_call_any_agent_endpoint_on_their_own_ticket(): void
    {
        $requester = $this->staff($this->department('Sales'));
        $agent = $this->agent();
        $ticket = $this->startedBy($agent, $this->ticketFrom($requester));

        // ผู้แจ้งเพิ่มบันทึกหลังส่งไม่ได้ และเรียก endpoint จัดการ Ticket ตรง ๆ ไม่ได้
        $this->actingAs($requester)->post(route('tickets.notes.store', $ticket), ['body' => 'ลองรีสตาร์ทแล้วยังไม่หาย'])->assertForbidden();
        $this->actingAs($requester)->post(route('tickets.participants.store', $ticket))->assertForbidden();
        $this->actingAs($requester)->patch(route('tickets.coordinator.update', $ticket), ['coordinator_id' => $agent->id])->assertForbidden();
        $this->actingAs($requester)->patch(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'active', 'note' => 'x'])->assertForbidden();
        $this->assertSame(0, $ticket->activities()->where('type', 'note')->count());
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);
    }

    public function test_missing_tickets_are_not_found(): void
    {
        $this->actingAs($this->agent())->get(route('tickets.show', 999))->assertNotFound();
        $this->actingAs($this->agent())->patch(route('tickets.status.update', 999), ['action' => 'start', 'expected_status' => 'new'])->assertNotFound();
    }
}
