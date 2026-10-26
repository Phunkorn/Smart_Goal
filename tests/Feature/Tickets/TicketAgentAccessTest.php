<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketAgent;
use App\Models\User;
use App\Support\TicketAgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ใครเป็นเจ้าหน้าที่ (agent) ของ Smart Ticket
 *
 * แผนกที่รับผิดชอบให้สิทธิ์โดยค่าตั้งต้น และแถว ticket_agents เป็น override รายคน
 * isAgent() กับ agentsQuery() ต้องตอบตรงกันเสมอ เพราะคำตอบแรกใช้ตัดสินสิทธิ์
 * ส่วนคำตอบหลังใช้หาผู้รับแจ้งเตือน
 */
class TicketAgentAccessTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_a_new_department_is_not_responsible_for_tickets(): void
    {
        $department = $this->department('Accounting');

        $this->assertFalse($department->fresh()->ticket_responsible);
        $this->assertFalse(TicketAgentAccess::isAgent($this->staff($department)));
    }

    public function test_every_active_member_of_a_responsible_department_is_an_agent_by_default(): void
    {
        $it = $this->itDepartment();

        $this->assertTrue(TicketAgentAccess::isAgent($this->staff($it)));
        $this->assertTrue(TicketAgentAccess::isAgent($this->staff($it, head: true)));
    }

    public function test_admin_can_switch_off_one_member_of_the_responsible_department(): void
    {
        $member = $this->agent();
        $this->override($member, enabled: false);

        $this->assertFalse(TicketAgentAccess::isAgent($member->fresh()));
    }

    public function test_admin_can_grant_someone_from_another_department(): void
    {
        $outsider = $this->staff($this->department('Operations'));
        $this->override($outsider, enabled: true);

        $this->assertTrue(TicketAgentAccess::isAgent($outsider->fresh()));
    }

    public function test_viewers_and_inactive_accounts_are_never_agents_even_with_an_override(): void
    {
        $it = $this->itDepartment();
        $viewer = $this->staff($it, role: 'viewer');
        $inactive = $this->staff($it, active: false);
        $this->override($viewer, enabled: true);
        $this->override($inactive, enabled: true);

        $this->assertFalse(TicketAgentAccess::isAgent($viewer->fresh()));
        $this->assertFalse(TicketAgentAccess::isAgent($inactive->fresh()));
    }

    public function test_a_soft_deleted_member_is_not_an_agent_even_if_still_marked_active(): void
    {
        $member = $this->agent();
        $member->delete();

        $trashed = User::withTrashed()->find($member->id);
        $this->assertTrue($trashed->is_active);
        $this->assertFalse(TicketAgentAccess::isAgent($trashed));
        $this->assertFalse(TicketAgentAccess::agentsQuery()->whereKey($member->id)->exists());
    }

    public function test_admin_is_not_an_agent_automatically_but_sees_all_tickets(): void
    {
        $admin = $this->staff($this->department('Management'), role: 'admin');

        $this->assertFalse(TicketAgentAccess::isAgent($admin));
        $this->assertTrue(TicketAgentAccess::seesAllTickets($admin));
    }

    public function test_a_null_override_follows_the_department_both_ways(): void
    {
        $it = $this->itDepartment();
        $member = $this->staff($it);
        TicketAgent::query()->forceCreate(['user_id' => $member->id, 'is_enabled' => null, 'receives_alerts' => false]);

        $this->assertTrue(TicketAgentAccess::isAgent($member->fresh()), 'null = ตามแผนก (แผนกรับผิดชอบ)');
        $this->assertSame('department', TicketAgentAccess::source($member->fresh()));
        $this->assertFalse(TicketAgentAccess::receivesAlerts($member->fresh()));
        $this->assertTrue(TicketAgentAccess::agentsQuery()->whereKey($member->id)->exists());
        $this->assertFalse(TicketAgentAccess::alertRecipientsQuery()->whereKey($member->id)->exists());

        $it->update(['ticket_responsible' => false]);
        $this->assertFalse(TicketAgentAccess::isAgent($member->fresh()), 'null = ตามแผนก (แผนกไม่รับผิดชอบ)');
        $this->assertFalse(TicketAgentAccess::agentsQuery()->whereKey($member->id)->exists());
    }

    public function test_agents_query_matches_is_agent_for_every_user(): void
    {
        $it = $this->itDepartment();
        $sales = $this->department('Sales');

        $this->staff($it);
        // override แบบ null = ตามแผนก ต้องตอบเหมือนไม่มีแถว
        TicketAgent::query()->forceCreate(['user_id' => $this->staff($it)->id, 'is_enabled' => null, 'receives_alerts' => true]);
        TicketAgent::query()->forceCreate(['user_id' => $this->staff($sales)->id, 'is_enabled' => null, 'receives_alerts' => true]);
        $this->override($this->staff($it), enabled: false);
        $this->override($this->staff($sales), enabled: true);
        $this->staff($sales);
        $this->staff($it, role: 'viewer');
        $this->staff($it, active: false);
        $this->staff($it, role: 'admin');
        $this->staff(null);

        $fromQuery = TicketAgentAccess::agentsQuery()->pluck('id')->sort()->values()->all();
        $fromRule = User::query()->get()
            ->filter(fn (User $user) => TicketAgentAccess::isAgent($user))
            ->pluck('id')->sort()->values()->all();

        $this->assertCount(4, $fromRule);
        $this->assertSame($fromRule, $fromQuery);
    }

    public function test_alert_recipients_are_agents_who_did_not_turn_alerts_off(): void
    {
        $listening = $this->agent();
        $muted = $this->agent();
        $this->override($muted, enabled: true, alerts: false);

        $this->assertSame([$listening->id], TicketAgentAccess::alertRecipientsQuery()->pluck('id')->all());
    }
}
