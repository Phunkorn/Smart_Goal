<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * สิทธิ์การดำเนินงานของ TicketPolicy (การมองเห็นอยู่ใน TicketVisibilityTest)
 */
class TicketPolicyTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_only_active_admins_and_users_can_open_the_ticket_menu_and_only_reporters_submit(): void
    {
        $sales = $this->department('Sales');

        // [viewAny, create] — แจ้งได้เฉพาะพนักงานทั่วไปที่ไม่ใช่ Handler / หัวหน้าแผนก / admin
        foreach ([
            'user' => [$this->staff($sales), true, true],
            'head' => [$this->staff($sales, head: true), true, false],
            'admin' => [$this->staff($sales, role: 'admin'), true, false],
            'agent (by department)' => [$this->agent(), true, false],
            'agent head' => [$this->staff($this->itDepartment(), head: true), true, false],
            'viewer' => [$this->staff($sales, role: 'viewer'), false, false],
            'inactive' => [$this->staff($sales, active: false), false, false],
        ] as $label => [$user, $viewAny, $create]) {
            $this->assertSame($viewAny, Gate::forUser($user)->allows('viewAny', Ticket::class), "viewAny {$label}");
            $this->assertSame($create, Gate::forUser($user)->allows('create', Ticket::class), "create {$label}");
        }
    }

    public function test_admin_overrides_switch_a_person_between_reporter_and_handler_immediately(): void
    {
        $member = $this->staff($this->itDepartment());
        $this->assertFalse(Gate::forUser($member)->allows('create', Ticket::class), 'สมาชิกแผนกที่รับผิดชอบเป็น Handler');

        // admin ถอนสิทธิ์รายคน (override มาก่อนแผนก) → กลายเป็นผู้แจ้ง
        $override = $this->override($member, enabled: false);
        $this->assertTrue(Gate::forUser($member->fresh())->allows('create', Ticket::class));
        $this->assertFalse(Gate::forUser($member->fresh())->allows('start', $this->publicTicket()));

        // ให้สิทธิ์คืน → แจ้งไม่ได้อีก
        $override->forceFill(['is_enabled' => true])->save();
        $this->assertFalse(Gate::forUser($member->fresh())->allows('create', Ticket::class));

        // คนนอกแผนกที่ admin ตั้งเป็น Handler รายคน ก็แจ้งไม่ได้
        $outsider = $this->staff($this->department('Sales'));
        $this->override($outsider, enabled: true);
        $this->assertFalse(Gate::forUser($outsider->fresh())->allows('create', Ticket::class));
    }

    public function test_a_handler_cannot_work_a_ticket_they_reported_but_can_still_leave_it(): void
    {
        // แจ้งไว้ตอนยังเป็นพนักงานทั่วไป แล้ว admin ตั้งเป็น Handler — Ticket ยังเป็นของเขา แต่จัดการเองไม่ได้
        $reporter = $this->staff($this->department('Sales'));
        $ticket = $this->ticketFrom($reporter);
        $this->override($reporter, enabled: true);
        $reporter = $reporter->fresh();

        foreach (['start', 'join', 'reopen', 'followUp', 'close', 'resume', 'setCoordinator', 'addNote'] as $ability) {
            $this->assertFalse($this->can($reporter, $ability, $ticket), $ability);
        }
        $this->assertSame($reporter->id, $ticket->fresh()->requester_id, 'เจ้าของเดิมไม่เปลี่ยน');

        // Handler คนอื่นจัดการได้ตามเดิม
        $colleague = $this->agent();
        $this->assertTrue($this->can($colleague, 'start', $ticket));
        $started = $this->startedBy($colleague, $ticket);
        $this->assertTrue($this->can($colleague, 'close', $started));

        // เคยเป็นผู้เข้าร่วมใบของตัวเอง (ข้อมูลเก่า) → ออกได้ แต่ทำอย่างอื่นไม่ได้
        app(TicketParticipantService::class)->joinLocked($started, $reporter);
        $started = $started->fresh();
        $this->assertTrue($this->can($reporter, 'leave', $started));
        $this->assertFalse($this->can($reporter, 'close', $started));
    }

    public function test_only_agents_can_take_a_new_ticket(): void
    {
        $ticket = $this->publicTicket();

        $this->assertTrue($this->can($this->agent(), 'start', $ticket));
        // เข้าร่วมได้หลังมีผู้รับงานแล้วเท่านั้น
        $this->assertFalse($this->can($this->agent(), 'join', $ticket));
        $this->assertTrue($this->can($this->agent(), 'join', $this->startedBy($this->agent(), $ticket)));

        foreach ($this->nonAgents() as $label => $user) {
            $this->assertFalse($this->can($user, 'start', $ticket), "start {$label}");
            $this->assertFalse($this->can($user, 'join', $ticket), "join {$label}");
        }
    }

    public function test_status_actions_need_a_current_participant(): void
    {
        $worker = $this->agent();
        $bystander = $this->agent();
        $ticket = $this->startedBy($worker);

        $this->assertTrue($this->can($worker, 'followUp', $ticket));
        $this->assertTrue($this->can($worker, 'close', $ticket));
        $this->assertFalse($this->can($bystander, 'followUp', $ticket));
        $this->assertFalse($this->can($bystander, 'close', $ticket));

        // ไม่มีการล็อก: agent อีกคนเข้าร่วมได้ทันที แล้วทำได้เท่ากับคนแรก
        $this->assertTrue($this->can($bystander, 'join', $ticket));
    }

    public function test_an_admin_who_is_not_an_agent_has_no_bypass_on_work_actions(): void
    {
        $admin = $this->staff($this->department('Management'), role: 'admin');
        $ticket = $this->publicTicket();

        $this->assertTrue($this->can($admin, 'view', $ticket));
        $this->assertTrue(Gate::forUser($admin)->allows('viewReport', Ticket::class));
        $this->assertTrue(Gate::forUser($admin)->allows('manageSettings', Ticket::class));

        foreach (['start', 'join', 'addNote', 'reopen'] as $ability) {
            $this->assertFalse($this->can($admin, $ability, $ticket), $ability);
        }
    }

    public function test_an_admin_set_as_agent_can_work_tickets(): void
    {
        $admin = $this->staff($this->department('Management'), role: 'admin');
        $this->override($admin, enabled: true);

        $this->assertTrue($this->can($admin->fresh(), 'start', $this->publicTicket()));
    }

    public function test_the_requester_cannot_add_notes_or_work_their_ticket_after_sending(): void
    {
        $requester = $this->staff($this->department('Sales'));
        $mine = $this->ticketFrom($requester);
        $theirs = $this->ticketFrom($this->staff($this->department('HR')));

        foreach (['addNote', 'start', 'join', 'close', 'setCoordinator', 'viewInternal'] as $ability) {
            $this->assertFalse($this->can($requester, $ability, $mine), $ability);
        }
        $this->assertTrue($this->can($requester, 'view', $mine));
        $this->assertFalse($this->can($requester, 'view', $theirs));
    }

    public function test_closed_tickets_accept_only_reopen_from_agents(): void
    {
        $worker = $this->agent();
        $ticket = $this->transition($this->startedBy($worker), Ticket::ACTION_CLOSE, $worker, ['note' => 'เปลี่ยนสายแลนแล้ว']);

        $this->assertTrue($this->can($worker, 'reopen', $ticket));
        foreach (['join', 'leave', 'addNote', 'followUp', 'close', 'setCoordinator'] as $ability) {
            $this->assertFalse($this->can($worker, $ability, $ticket), $ability);
        }

        $requester = $this->staff($this->department('Sales'));
        $this->assertFalse($this->can($requester, 'reopen', $ticket));
    }

    public function test_reports_are_for_admins_and_agents_and_settings_for_admins(): void
    {
        $agent = $this->agent();
        $user = $this->staff($this->department('Sales'));

        $this->assertTrue(Gate::forUser($agent)->allows('viewReport', Ticket::class));
        $this->assertFalse(Gate::forUser($agent)->allows('manageSettings', Ticket::class));
        $this->assertFalse(Gate::forUser($user)->allows('viewReport', Ticket::class));
    }

    private function can(User $user, string $ability, Ticket $ticket): bool
    {
        return Gate::forUser($user)->allows($ability, $ticket->fresh());
    }

    /** @return array<string, User> */
    private function nonAgents(): array
    {
        $sales = $this->department('Sales');
        $disabled = $this->agent();
        $this->override($disabled, enabled: false);

        return [
            'user' => $this->staff($sales),
            'head' => $this->staff($sales, head: true),
            'admin' => $this->staff($sales, role: 'admin'),
            'viewer' => $this->staff($this->itDepartment(), role: 'viewer'),
            'inactive agent' => $this->staff($this->itDepartment(), active: false),
            'disabled agent' => $disabled->fresh(),
        ];
    }
}
