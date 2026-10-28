<?php

namespace Tests\Feature\Tickets;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\TicketAgent;
use App\Models\TicketLocation;
use App\Services\TicketSubmissionService;
use App\Support\TicketAgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * หน้าตั้งค่า Smart Ticket ของ admin — สิทธิ์ การเปลี่ยนค่า และ AuditTrail
 */
class TicketSettingsPageTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_only_admins_can_open_or_change_settings(): void
    {
        $sales = $this->department('Sales');
        $location = $this->location('สาขา 1');
        $target = $this->staff($sales);

        $routes = [
            ['get', route('admin.tickets.index'), []],
            ['patch', route('admin.tickets.departments.update', $sales), ['ticket_responsible' => 1]],
            ['post', route('admin.tickets.agents.store'), ['user_id' => $target->id]],
            ['put', route('admin.tickets.agents.update', $target), ['access' => 'granted', 'receives_alerts' => 1]],
            ['post', route('admin.tickets.locations.store'), ['name' => 'สาขาใหม่']],
            ['patch', route('admin.tickets.locations.update', $location), ['name' => 'แก้ชื่อ', 'is_active' => 1]],
        ];

        foreach ([
            'employee' => $this->staff($sales),
            'department head' => $this->staff($sales, head: true),
            'agent' => $this->agent(),
            'viewer' => $this->staff($sales, role: 'viewer'),
        ] as $who => $user) {
            foreach ($routes as [$method, $url, $data]) {
                $this->assertSame(403, $this->actingAs($user)->{$method}($url, $data)->status(), "{$who}: {$method} {$url}");
            }
        }

        $this->assertFalse($sales->fresh()->ticket_responsible);
        $this->assertSame(0, TicketAgent::query()->where('user_id', $target->id)->count());
        $this->assertSame('สาขา 1', $location->fresh()->name);
        $this->assertSame(1, TicketLocation::count());
    }

    public function test_the_page_lists_departments_agents_and_locations(): void
    {
        $it = $this->itDepartment();
        $member = $this->staff($it);
        $this->department('ฝ่ายบัญชี');
        $this->location('สาขาบางนา');

        $this->actingAs($this->admin())->get(route('admin.tickets.index'))
            ->assertOk()
            ->assertSee('IT Support')
            ->assertSee('ฝ่ายบัญชี')
            ->assertSee($member->name)
            ->assertSee('ตามแผนก')
            ->assertSee('value="สาขาบางนา"', false);
    }

    public function test_empty_setup_explains_what_to_do(): void
    {
        $this->actingAs($this->admin())->get(route('admin.tickets.index'))
            ->assertSee('ยังไม่ได้เลือกแผนกที่รับผิดชอบ')
            ->assertSee('ยังไม่มีเจ้าหน้าที่')
            ->assertSee('ยังไม่มีรายการสาขา');
    }

    public function test_toggling_a_department_makes_its_members_agents_and_is_audited(): void
    {
        $admin = $this->admin();
        $sales = $this->department('Sales');
        $member = $this->staff($sales);

        $this->actingAs($admin)->patch(route('admin.tickets.departments.update', $sales), ['ticket_responsible' => 1])
            ->assertSessionHas('success');
        $this->assertTrue(TicketAgentAccess::isAgent($member->fresh()));

        $log = ActivityLog::query()->where('subject_type', Department::class)->where('subject_id', $sales->id)->sole();
        $this->assertSame(['ticket_responsible' => false], $log->changes['before']);
        $this->assertSame(['ticket_responsible' => true], $log->changes['after']);

        // ค่าเดิมซ้ำไม่ใช่การเปลี่ยน — ไม่เขียน audit ใหม่
        $this->actingAs($admin)->patch(route('admin.tickets.departments.update', $sales), ['ticket_responsible' => 1]);
        $this->assertSame(1, ActivityLog::query()->where('subject_type', Department::class)->count());
    }

    public function test_per_person_access_and_alerts(): void
    {
        $admin = $this->admin();
        $member = $this->agent();
        $outsider = $this->staff($this->department('Operations'));

        // ปิดรายคน
        $this->actingAs($admin)->put(route('admin.tickets.agents.update', $member), ['access' => 'revoked', 'receives_alerts' => 1]);
        $this->assertFalse(TicketAgentAccess::isAgent($member->fresh()));

        // เพิ่มคนจากแผนกอื่น
        $this->actingAs($admin)->post(route('admin.tickets.agents.store'), ['user_id' => $outsider->id])->assertSessionHas('success');
        $this->assertTrue(TicketAgentAccess::isAgent($outsider->fresh()));

        // กลับไป "ตามแผนก" แต่ปิดการแจ้งเตือน — ยังเป็น agent ตามแผนก และไม่ถูกตรึงสิทธิ์ไว้
        $this->actingAs($admin)->put(route('admin.tickets.agents.update', $member), ['access' => 'department', 'receives_alerts' => 0]);
        $member = $member->fresh();
        $this->assertTrue(TicketAgentAccess::isAgent($member));
        $this->assertFalse(TicketAgentAccess::receivesAlerts($member));
        $this->assertNull(TicketAgent::query()->where('user_id', $member->id)->sole()->is_enabled);

        $member->department->update(['ticket_responsible' => false]);
        $this->assertFalse(TicketAgentAccess::isAgent($member->fresh()), 'ตามแผนก = หมดสิทธิ์เมื่อแผนกถูกปิด');

        // ค่าตั้งต้นล้วน ไม่เหลือแถว override
        $this->actingAs($admin)->put(route('admin.tickets.agents.update', $member), ['access' => 'department', 'receives_alerts' => 1]);
        $this->assertSame(0, TicketAgent::query()->where('user_id', $member->id)->count());

        $this->assertGreaterThanOrEqual(4, ActivityLog::query()->where('description', 'like', 'Admin changed Smart Ticket agent access%')->count());
    }

    public function test_viewers_and_inactive_accounts_cannot_be_made_agents(): void
    {
        $admin = $this->admin();

        foreach ([$this->staff($this->department('Ops'), role: 'viewer'), $this->staff($this->department('Ops2'), active: false)] as $user) {
            $this->actingAs($admin)->post(route('admin.tickets.agents.store'), ['user_id' => $user->id])
                ->assertSessionHasErrors('user_id');
            $this->assertSame(0, TicketAgent::query()->where('user_id', $user->id)->count());
        }
    }

    public function test_locations_can_be_added_renamed_and_deactivated_but_not_duplicated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.tickets.locations.store'), ['name' => '  สาขาบางนา  '])->assertSessionHas('success');
        $location = TicketLocation::query()->sole();
        $this->assertSame('สาขาบางนา', $location->name);

        $this->actingAs($admin)->post(route('admin.tickets.locations.store'), ['name' => 'สาขาบางนา'])->assertSessionHasErrors('name');

        $ticket = app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'ผู้แจ้ง', 'requester_department' => 'Callcenter', 'description' => 'จอไม่ติด', 'location_id' => $location->id,
        ], null);

        $this->actingAs($admin)->patch(route('admin.tickets.locations.update', $location), ['name' => 'สาขาบางนา (ใหม่)', 'is_active' => 0])
            ->assertSessionHas('success');
        $location->refresh();
        $this->assertSame('สาขาบางนา (ใหม่)', $location->name);
        $this->assertFalse($location->is_active);
        $this->assertSame('สาขาบางนา', $ticket->fresh()->location_name, 'snapshot ของ Ticket เก่าไม่เปลี่ยน');

        $this->assertSame(2, ActivityLog::query()->where('subject_type', TicketLocation::class)->count());

        // สาขาที่ปิดใช้งานไม่อยู่ในฟอร์มแจ้งปัญหา
        $this->get(route('support.create'))->assertDontSee('สาขาบางนา (ใหม่)');
    }

    /**
     * admin กด "เพิ่ม" คนเดียวกันสองครั้งพร้อมกัน (ดับเบิลคลิก / สองแท็บ)
     *
     * จำลองคำขออีกอันที่ชนะไปก่อน: ทันทีหลังคำขอนี้อ่าน ticket_agents แล้วไม่พบแถว อีกคำขอเพิ่มแถวของคนเดียวกัน
     * เดิมคำขอนี้ insert ซ้ำ → ชน unique(user_id) → HTTP 500 ตอนนี้ต้องได้ผลปกติ แถวเดียว และสิทธิ์ถูกต้อง
     */
    public function test_adding_the_same_agent_twice_at_once_does_not_fail_or_duplicate(): void
    {
        $admin = $this->admin();
        $outsider = $this->staff($this->department('Sales'));

        $injected = false;
        DB::listen(function ($query) use (&$injected, $outsider): void {
            if ($injected || ! preg_match('/^select \* from "ticket_agents" where "user_id" = \?/', $query->sql)) {
                return;
            }

            $injected = true;
            DB::table('ticket_agents')->insert([
                'user_id' => $outsider->id,
                'is_enabled' => true,
                'receives_alerts' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($admin)->post(route('admin.tickets.agents.store'), ['user_id' => $outsider->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($injected, 'จำลองการแข่งกันได้จริง');
        $this->assertSame(1, TicketAgent::query()->where('user_id', $outsider->id)->count());
        $this->assertTrue(TicketAgentAccess::isAgent($outsider->fresh()));
    }

    public function test_repeating_an_agent_change_is_idempotent(): void
    {
        $admin = $this->admin();
        $outsider = $this->staff($this->department('Sales'));

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($admin)->postJson(route('admin.tickets.agents.store'), ['user_id' => $outsider->id])->assertSuccessful();
        }

        $this->assertSame(1, TicketAgent::query()->where('user_id', $outsider->id)->count());
        $this->assertSame(1, ActivityLog::query()->where('description', 'like', '%agent access%'.$outsider->name)->count(), 'บันทึก audit เฉพาะครั้งที่เปลี่ยนจริง');
    }

    private function admin()
    {
        return $this->staff($this->department('Management'), role: 'admin');
    }
}
