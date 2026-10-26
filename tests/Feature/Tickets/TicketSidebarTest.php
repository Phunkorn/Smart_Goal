<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * เมนู Smart Ticket ใน sidebar แสดงตามสิทธิ์เดียวกับ route — และ route ยังตรวจเองเสมอ
 */
class TicketSidebarTest extends TestCase
{
    use BuildsTickets;
    use RefreshDatabase;

    public function test_the_ticket_menu_matches_the_policy_for_every_role(): void
    {
        $sales = $this->department('Sales');
        $link = 'href="'.route('tickets.index').'"';

        foreach ([
            'employee' => [$this->staff($sales), route('mytasks.index')],
            'department head' => [$this->staff($sales, head: true), route('mytasks.index')],
            'agent' => [$this->agent(), route('mytasks.index')],
            'admin' => [$this->staff($this->department('Management'), role: 'admin'), route('board.index')],
            'viewer' => [$this->staff($sales, role: 'viewer'), route('board.index')],
        ] as $who => [$user, $page]) {
            $content = $this->actingAs($user)->get($page)->assertOk()->getContent();
            $allowed = Gate::forUser($user)->allows('viewAny', Ticket::class);

            $this->assertSame($allowed, str_contains($content, $link), "menu for {$who}");
            $this->assertSame($allowed ? 200 : 403, $this->actingAs($user)->get(route('tickets.index'))->status(), "route for {$who}");
        }
    }

    public function test_the_menu_has_its_own_section_between_reports_and_notifications(): void
    {
        $content = $this->actingAs($this->staff($this->department('Sales')))->get(route('mytasks.index'))->getContent();

        // รายงาน → Smart Ticket → การสื่อสาร (การแจ้งเตือน) → ระบบ (ตั้งค่า)
        $positions = [
            strpos($content, 'href="'.route('reports.projects').'"'),
            strpos($content, '<div class="nav-section-label">Smart Ticket</div>'),
            strpos($content, 'href="'.route('tickets.index').'"'),
            strpos($content, '<div class="nav-section-label">การสื่อสาร</div>'),
            strpos($content, 'href="'.route('notifications.index').'"'),
            strpos($content, 'href="'.route('settings.index').'"'),
        ];

        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    public function test_only_admins_see_the_settings_menu(): void
    {
        $settings = 'href="'.route('admin.tickets.index').'"';

        $admin = $this->actingAs($this->staff($this->department('Management'), role: 'admin'))->get(route('board.index'))->getContent();
        $this->assertStringContainsString($settings, $admin);
        $this->assertLessThan(strpos($admin, 'href="'.route('admin.audit.index').'"'), strpos($admin, $settings));

        $this->actingAs($this->agent())->get(route('mytasks.index'))->assertDontSee($settings, false);
    }

    public function test_agents_see_how_many_tickets_are_waiting(): void
    {
        $this->publicTicket();
        $this->publicTicket();
        $this->startedBy($this->agent());

        $this->actingAs($this->agent())->get(route('mytasks.index'))
            ->assertSee('aria-label="รอรับงาน 2 รายการ"', false);

        $this->actingAs($this->staff($this->department('Sales')))->get(route('mytasks.index'))
            ->assertDontSee('aria-label="รอรับงาน', false);
    }

    /**
     * Smart Ticket เป็นเมนูเดียวทุกบทบาท ชื่อตามความสามารถจริงของบัญชี (ไม่ใช่ชื่อแผนก):
     *   ผู้แจ้ง = "แจ้งปัญหา IT" · หัวหน้าแผนก (ไม่ใช่ Handler) = "Ticket ของแผนก"
     *   Handler (รวมหัวหน้าที่เป็น Handler) = "จัดการ Ticket" · admin ที่ไม่ใช่ Handler = "Ticket ทั้งหมด"
     * รายงานแจ้งปัญหาอยู่ในกลุ่ม "รายงาน" เฉพาะผู้มีสิทธิ์ (TicketPolicy::viewReport) ไม่มีกลุ่มพับได้ของ Ticket แล้ว
     */
    public function test_one_ticket_link_named_by_capability_and_the_ticket_report_lives_under_reports(): void
    {
        $sales = $this->department('Sales');
        $queue = 'href="'.route('tickets.index').'"';
        $report = 'href="'.route('reports.tickets').'"';
        $handlerOverride = $this->staff($sales);
        $this->override($handlerOverride, enabled: true);

        foreach ([
            'employee' => [$this->staff($sales), route('mytasks.index'), 'แจ้งปัญหา IT', false],
            'department head' => [$this->staff($sales, head: true), route('mytasks.index'), 'Ticket ของแผนก', false],
            'agent' => [$this->agent(), route('mytasks.index'), 'จัดการ Ticket', true],
            'agent head' => [$this->staff($this->itDepartment(), head: true), route('mytasks.index'), 'จัดการ Ticket', true],
            'handler by override' => [$handlerOverride->fresh(), route('mytasks.index'), 'จัดการ Ticket', true],
            'admin' => [$this->staff($this->department('Management'), role: 'admin'), route('board.index'), 'Ticket ทั้งหมด', true],
        ] as $who => [$user, $page, $label, $seesReport]) {
            $content = $this->actingAs($user)->get($page)->assertOk()->getContent();

            $this->assertSame(1, substr_count($content, $queue), $who);
            $this->assertMatchesRegularExpression('~'.preg_quote($queue, '~').'\s+class="nav-item\s*"[^>]*>\s*<i class="bi bi-headset" aria-hidden="true"></i>\s*<span class="nav-item__label">'.preg_quote($label, '~').'</span>~u', $content, $who);
            $this->assertStringNotContainsString('data-ticket-nav', $content, $who.': ไม่มีกลุ่มพับได้ของ Ticket');
            $this->assertSame($seesReport ? 1 : 0, substr_count($content, $report), $who);

            if ($seesReport) {
                // ลิงก์รายงานแจ้งปัญหาอยู่ในกลุ่ม "รายงาน" ถัดจากรายงานโปรเจกต์
                $group = substr($content, (int) strpos($content, '<details class="report-nav'), (int) strpos($content, '</details>') - (int) strpos($content, '<details class="report-nav'));
                $this->assertStringContainsString($report, $group, $who);
                $this->assertLessThan(strpos($group, $report), strpos($group, 'href="'.route('reports.projects').'"'), $who);
            }
        }

        // ผู้แจ้งไม่เห็นเมนูของ Handler และ Handler ไม่เห็นเมนูแจ้งปัญหา
        $this->actingAs($this->staff($sales))->get(route('mytasks.index'))->assertDontSee('จัดการ Ticket');
        $this->actingAs($this->agent())->get(route('mytasks.index'))->assertDontSee('<span class="nav-item__label">แจ้งปัญหา IT</span>', false);
    }

    public function test_ticket_pages_highlight_the_ticket_link_and_the_ticket_report_highlights_only_inside_reports(): void
    {
        $agent = $this->agent();

        $queue = $this->actingAs($agent)->get(route('tickets.index'))->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('tickets.index'), '/').'"\s+class="nav-item active"\s+aria-current="page"/', $queue);
        $this->assertStringContainsString('<details class="report-nav" >', $queue, 'กลุ่มรายงานปิดอยู่บนหน้าคิว');

        $report = $this->actingAs($agent)->get(route('reports.tickets'))->getContent();
        $this->assertStringContainsString('<details class="report-nav report-nav--active" open>', $report);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('reports.tickets'), '/').'"\s+class="nav-item report-nav__link active"\s+aria-current="page"/', $report);
        $this->assertSame(1, substr_count($report, 'report-nav__link active'), 'ไฮไลต์เฉพาะรายงานแจ้งปัญหา');
        $this->assertDoesNotMatchRegularExpression('/href="'.preg_quote(route('tickets.index'), '/').'"\s+class="nav-item active"/', $report);

        foreach ([$this->staff($this->department('Sales')), $this->staff($this->department('HR'), head: true)] as $user) {
            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(route('tickets.index'), '/').'"\s+class="nav-item active"\s+aria-current="page"/',
                $this->actingAs($user)->get(route('tickets.index'))->getContent(),
            );
        }
    }
}
