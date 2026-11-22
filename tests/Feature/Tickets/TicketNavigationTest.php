<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * เมนู Smart Ticket บนเมนูหลักแนวนอนแสดงตามสิทธิ์เดียวกับ route — และ route ยังตรวจเองเสมอ
 * (เดิมคือ TicketSidebarTest ของแถบข้าง กติกาเดียวกันย้ายมาตรวจกับ App\Support\TopNavigation)
 */
class TicketNavigationTest extends TestCase
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

            $this->assertSame($allowed, str_contains($this->nav($content), $link), "menu for {$who}");
            $this->assertSame($allowed ? 200 : 403, $this->actingAs($user)->get(route('tickets.index'))->status(), "route for {$who}");
        }
    }

    public function test_the_menu_has_its_own_section_inside_more_before_notifications(): void
    {
        $nav = $this->nav($this->actingAs($this->staff($this->department('Sales')))->get(route('mytasks.index'))->getContent());

        // รายงาน▾ → เพิ่มเติม▾ [Smart Ticket → การสื่อสาร (การแจ้งเตือน)]
        $positions = [
            strpos($nav, 'href="'.route('reports.projects').'"'),
            strpos($nav, 'id="top-nav-more-menu"'),
            strpos($nav, 'aria-label="Smart Ticket"'),
            strpos($nav, 'href="'.route('tickets.index').'"'),
            strpos($nav, 'aria-label="การสื่อสาร"'),
            strpos($nav, 'href="'.route('notifications.index').'"'),
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

        $agentNav = $this->nav($this->actingAs($this->agent())->get(route('mytasks.index'))->getContent());
        $this->assertStringContainsString('aria-label="รอรับงาน 2 รายการ"', $agentNav);
        // ป้ายอยู่ในดร็อปดาวน์ จึงมีจุดเตือนบนหัวกลุ่มให้เห็นจากแถวบน
        $this->assertMatchesRegularExpression('/id="top-nav-more"[^>]*>[\s\S]*?<span class="top-nav__dot" aria-hidden="true"><\/span>/', $agentNav);

        $staffNav = $this->nav($this->actingAs($this->staff($this->department('Sales')))->get(route('mytasks.index'))->getContent());
        $this->assertStringNotContainsString('aria-label="รอรับงาน', $staffNav);
        $this->assertStringNotContainsString('top-nav__dot', $staffNav);
    }

    /**
     * Smart Ticket เป็นเมนูเดียวทุกบทบาท ชื่อตามความสามารถจริงของบัญชี (ไม่ใช่ชื่อแผนก):
     *   ผู้แจ้ง = "แจ้งปัญหา IT" · หัวหน้าแผนก (ไม่ใช่ Handler) = "Ticket ของแผนก"
     *   Handler (รวมหัวหน้าที่เป็น Handler) = "จัดการ Ticket" · admin ที่ไม่ใช่ Handler = "Ticket ทั้งหมด"
     * รายงานแจ้งปัญหาอยู่ในกลุ่ม "รายงาน" เฉพาะผู้มีสิทธิ์ (TicketPolicy::viewReport)
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
            // หัวหน้าแผนกที่แผนกไม่ได้จัดการ Ticket — เป็นผู้แจ้ง ไม่เห็นเมนู/หน้ารายการของแผนก
            'department head (department does not handle tickets)' => [$this->staff($sales, head: true), route('mytasks.index'), 'แจ้งปัญหา IT', false],
            'overseeing head' => [$this->overseeingHead($this->overseenDepartment('IT Helpdesk')), route('mytasks.index'), 'Ticket ของแผนก', false],
            'agent' => [$this->agent(), route('mytasks.index'), 'จัดการ Ticket', true],
            'agent head' => [$this->staff($this->itDepartment(), head: true), route('mytasks.index'), 'จัดการ Ticket', true],
            'handler by override' => [$handlerOverride->fresh(), route('mytasks.index'), 'จัดการ Ticket', true],
            'admin' => [$this->staff($this->department('Management'), role: 'admin'), route('board.index'), 'Ticket ทั้งหมด', true],
        ] as $who => [$user, $page, $label, $seesReport]) {
            $nav = $this->nav($this->actingAs($user)->get($page)->assertOk()->getContent());

            $this->assertSame(1, substr_count($nav, $queue), $who);
            $this->assertMatchesRegularExpression('~'.preg_quote($queue, '~').' class="dropdown-item nav-dropdown__item"\s*>\s*<i class="bi bi-headset nav-dropdown__icon" aria-hidden="true"></i>\s*<span class="nav-dropdown__label">'.preg_quote($label, '~').'</span>~u', $nav, $who);
            $this->assertSame($seesReport ? 1 : 0, substr_count($nav, $report), $who);

            if ($seesReport) {
                // ลิงก์รายงานแจ้งปัญหาอยู่ในกลุ่ม "รายงาน" ถัดจากรายงานโปรเจกต์
                $group = $this->menu($nav, 'reports');
                $this->assertStringContainsString($report, $group, $who);
                $this->assertLessThan(strpos($group, $report), strpos($group, 'href="'.route('reports.projects').'"'), $who);
            }
        }

        // ผู้แจ้งไม่เห็นเมนูของ Handler และ Handler ไม่เห็นเมนูแจ้งปัญหา
        $this->actingAs($this->staff($sales))->get(route('mytasks.index'))->assertDontSee('จัดการ Ticket');
        $this->actingAs($this->agent())->get(route('mytasks.index'))->assertDontSee('<span class="nav-dropdown__label">แจ้งปัญหา IT</span>', false);
    }

    public function test_ticket_pages_highlight_the_ticket_link_and_the_ticket_report_highlights_only_inside_reports(): void
    {
        $agent = $this->agent();
        $ticketLink = '/href="'.preg_quote(route('tickets.index'), '/').'" class="dropdown-item nav-dropdown__item is-active"\s+aria-current="page"/';

        $queue = $this->nav($this->actingAs($agent)->get(route('tickets.index'))->getContent());
        $this->assertMatchesRegularExpression($ticketLink, $queue);
        $this->assertMatchesRegularExpression('/class="top-nav__link top-nav__toggle is-active" data-top-nav-entry\s+id="top-nav-more"/', $queue);
        $this->assertDoesNotMatchRegularExpression('/top-nav__toggle is-active" data-top-nav-entry\s+id="top-nav-reports"/', $queue, 'กลุ่มรายงานไม่ถูกไฮไลต์บนหน้าคิว');

        $report = $this->nav($this->actingAs($agent)->get(route('reports.tickets'))->getContent());
        $this->assertMatchesRegularExpression('/class="top-nav__link top-nav__toggle is-active" data-top-nav-entry\s+id="top-nav-reports"/', $report);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('reports.tickets'), '/').'" class="dropdown-item nav-dropdown__item is-active"\s+aria-current="page"/', $report);
        $this->assertSame(1, substr_count($report, 'nav-dropdown__item is-active'), 'ไฮไลต์เฉพาะรายงานแจ้งปัญหา');
        $this->assertDoesNotMatchRegularExpression($ticketLink, $report);

        foreach ([$this->staff($this->department('Sales')), $this->staff($this->department('HR'), head: true)] as $user) {
            $this->assertMatchesRegularExpression($ticketLink, $this->nav($this->actingAs($user)->get(route('tickets.index'))->getContent()));
        }
    }

    private function nav(string $content): string
    {
        $start = (int) strpos($content, '<nav class="top-nav"');

        return substr($content, $start, (int) strpos($content, '</nav>', $start) - $start);
    }

    private function menu(string $nav, string $group): string
    {
        $start = (int) strpos($nav, 'id="top-nav-'.$group.'-menu"');

        return substr($nav, $start, (int) strpos($nav, '</li>', $start) - $start);
    }
}
