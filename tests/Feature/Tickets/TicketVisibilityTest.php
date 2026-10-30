<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketQueryService;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ใครเห็น Ticket ใบไหน — รายการ (TicketQueryService::visibleTo) และหน้ารายละเอียด
 * (TicketPolicy::view) ต้องให้คำตอบเดียวกันทุกใบ ทุกบทบาท
 *
 * เทสต์ HTTP ของหน้ารายการและหน้ารายละเอียดจริงเพิ่มใน Phase 3 เมื่อมี route
 */
class TicketVisibilityTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $people;

    /** @var array<string, Ticket> */
    private array $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        // แผนกที่รับผิดชอบ Ticket — หัวหน้าของแผนกเท่านั้นที่ยังดูใบของลูกทีมได้ (TicketAgentAccess::seesDepartmentTickets)
        $sales = $this->overseenDepartment('Sales');
        $hr = $this->overseenDepartment('HR');
        $marketing = $this->department('Marketing');

        $this->people = [
            'salesHead' => $this->overseeingHead($sales),
            'salesMember' => $this->reporterIn($sales),
            'salesColleague' => $this->reporterIn($sales),
            'hrHead' => $this->overseeingHead($hr),
            'hrMember' => $this->reporterIn($hr),
            // หัวหน้าแผนกที่แผนกไม่ได้จัดการ Ticket — ผู้แจ้งธรรมดา
            'marketingHead' => $this->staff($marketing, head: true),
            'marketingMember' => $this->staff($marketing),
            'agent' => $this->agent(),
            'admin' => $this->staff($this->department('Management'), role: 'admin'),
            'viewer' => $this->staff($sales, role: 'viewer'),
            'inactive' => $this->staff($sales, active: false),
        ];

        $this->tickets = [
            'bySalesMember' => $this->ticketFrom($this->people['salesMember']),
            'byMarketingMember' => $this->ticketFrom($this->people['marketingMember']),
            'bySalesColleague' => $this->ticketFrom($this->people['salesColleague']),
            // หัวหน้าแผนกแจ้งเองไม่ได้แล้ว — ใบนี้คือใบที่แจ้งไว้ก่อนได้เป็นหัวหน้า
            'bySalesHead' => $this->ticketFromBeforeRoleChange($this->people['salesHead']),
            'byHrMember' => $this->ticketFrom($this->people['hrMember']),
            'public' => $this->publicTicket(),
        ];
    }

    public function test_an_employee_sees_only_their_own_tickets(): void
    {
        $this->assertVisible('salesMember', ['bySalesMember']);
    }

    public function test_a_department_head_sees_their_own_and_their_departments_tickets_only(): void
    {
        $this->assertVisible('salesHead', ['bySalesMember', 'bySalesColleague', 'bySalesHead']);
        $this->assertVisible('hrHead', ['byHrMember']);
    }

    /**
     * หัวหน้าแผนกที่แผนกไม่ได้รับผิดชอบ Ticket เป็นผู้แจ้งธรรมดา
     * เห็นเฉพาะใบของตัวเอง ไม่เห็นใบของลูกทีม (และไม่มีหน้ารายการของแผนก)
     */
    public function test_a_head_of_a_department_that_does_not_handle_tickets_sees_only_their_own(): void
    {
        $head = $this->people['marketingHead'];

        $this->assertVisible('marketingHead', []);
        $this->assertFalse(Gate::forUser($head)->allows('view', $this->tickets['byMarketingMember']));
        $this->assertTrue(Gate::forUser($head)->allows('create', Ticket::class));

        $own = $this->ticketFrom($head, 'จอของหัวหน้าการตลาดดับ');
        $this->assertTrue(Gate::forUser($head->fresh())->allows('view', $own));
    }

    public function test_a_department_head_cannot_open_another_departments_ticket(): void
    {
        $this->assertFalse(Gate::forUser($this->people['hrHead'])->allows('view', $this->tickets['bySalesMember']));
        $this->assertFalse(Gate::forUser($this->people['salesHead'])->allows('view', $this->tickets['public']));
    }

    public function test_a_department_head_reads_but_cannot_work_on_their_departments_tickets(): void
    {
        $head = $this->people['salesHead'];
        $ticket = $this->tickets['bySalesMember'];

        $this->assertTrue(Gate::forUser($head)->allows('view', $ticket));

        foreach (['join', 'start', 'addNote', 'setCoordinator', 'leave'] as $ability) {
            $this->assertFalse(Gate::forUser($head)->allows($ability, $ticket), $ability);
        }
    }

    public function test_agents_and_admins_see_every_ticket(): void
    {
        $everything = array_keys($this->tickets);

        $this->assertVisible('agent', $everything);
        $this->assertVisible('admin', $everything);
    }

    public function test_viewers_and_inactive_accounts_see_nothing(): void
    {
        $this->assertVisible('viewer', []);
        $this->assertVisible('inactive', []);
    }

    public function test_the_list_scope_and_the_detail_policy_agree_for_every_person_and_ticket(): void
    {
        $query = app(TicketQueryService::class);

        foreach ($this->people as $who => $person) {
            $listed = $query->visibleTo($person)->pluck('id')->all();

            foreach ($this->tickets as $which => $ticket) {
                $this->assertSame(
                    Gate::forUser($person)->allows('view', $ticket),
                    in_array($ticket->id, $listed, true),
                    "{$who} / {$which}"
                );
            }
        }
    }

    public function test_department_scope_follows_the_snapshot_when_the_requester_moves_department(): void
    {
        $member = $this->people['salesMember'];
        $member->update(['department_id' => $this->people['hrHead']->department_id]);

        // Ticket ที่แจ้งตอนอยู่แผนก Sales ยังอยู่กับหัวหน้า Sales ที่ดูแลอยู่ในตอนนั้น
        $this->assertTrue(Gate::forUser($this->people['salesHead'])->allows('view', $this->tickets['bySalesMember']));
        $this->assertFalse(Gate::forUser($this->people['hrHead'])->allows('view', $this->tickets['bySalesMember']));
    }

    public function test_a_self_declared_department_on_a_public_ticket_grants_no_department_access(): void
    {
        // ผู้แจ้งภายนอกเลือก "HR" บนฟอร์ม /support ซึ่งตรงกับชื่อแผนกจริงในระบบ
        $outsider = app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'คุณภายนอก',
            'requester_department' => 'HR',
            'description' => 'ปริ้นเตอร์ใช้งานไม่ได้',
            'location_name' => 'สาขาบางนา',
        ], '203.0.113.9');

        $this->assertSame('HR', $outsider->requester_department_name);
        $this->assertNull($outsider->requester_department_id, 'ชื่อแผนกที่ผู้แจ้งเลือกเองต้องไม่ผูก id ของแผนก');

        // สิทธิ์ของหัวหน้าแผนกอ่านจาก requester_department_id เท่านั้น — ชื่อที่ตรงกันต้องไม่เปิดสิทธิ์
        foreach (['hrHead', 'hrMember', 'salesHead'] as $who) {
            $person = $this->people[$who];
            $this->assertFalse(Gate::forUser($person)->allows('view', $outsider), "detail for {$who}");
            $this->assertNotContains(
                $outsider->id,
                app(TicketQueryService::class)->visibleTo($person)->pluck('id')->all(),
                "list for {$who}",
            );
        }

        // ทีม IT ยังเห็นใบนี้ตามปกติ
        $this->assertTrue(Gate::forUser($this->people['agent'])->allows('view', $outsider));
    }

    /** @param  array<int, string>  $expected */
    private function assertVisible(string $who, array $expected): void
    {
        $person = $this->people[$who];
        $expectedIds = collect($expected)->map(fn (string $key) => $this->tickets[$key]->id)->sort()->values()->all();

        $listed = app(TicketQueryService::class)->visibleTo($person)->pluck('id')->sort()->values()->all();
        $this->assertSame($expectedIds, $listed, "list for {$who}");

        foreach ($this->tickets as $key => $ticket) {
            $this->assertSame(
                in_array($key, $expected, true),
                Gate::forUser($person)->allows('view', $ticket),
                "detail {$key} for {$who}"
            );
        }
    }
}
