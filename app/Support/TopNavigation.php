<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Http\Request;

/**
 * เมนูแนวนอนบน Topbar (layouts/components/top-navigation) — ที่เดียวที่ตัดสินว่าใครเห็นเมนูอะไร
 *
 * แทนแถบข้างเดิมที่เขียนเงื่อนไขบทบาทกระจายอยู่ใน layouts/app.blade.php และ partial อีกสามไฟล์
 * Blade วาดผลลัพธ์จากที่นี่อย่างเดียว ไม่ตัดสินสิทธิ์เอง
 *
 * โครงเมนูตามบทบาท (เมนูใช้บ่อยอยู่แถวบนสุด ส่วนที่เหลือจัดกลุ่มเป็นดร็อปดาวน์ตามหน้าที่):
 *   พนักงาน/หัวหน้าแผนก  งานของฉัน · บันทึกงานประจำวัน · บอร์ดงาน · แชร์งาน · กระดานไอเดีย · รายงาน▾ · เพิ่มเติม▾
 *   admin              บอร์ดรวม · บันทึกงานประจำวัน · แชร์งาน · กระดานไอเดีย · รายงาน▾ · ผู้ดูแลระบบ▾ · เพิ่มเติม▾
 *   viewer             แดชบอร์ด · กระดานไอเดีย · รายงาน▾ · การประชุม · พนักงาน · การแจ้งเตือน
 *
 * ชุดเมนูของแต่ละบทบาทเท่ากับแถบข้างเดิมทุกรายการ — การซ่อนเมนูไม่ใช่การให้สิทธิ์
 * ทุก route ยังตรวจ middleware/policy ของตัวเองเสมอ
 *
 * @phpstan-type Badge array{count: int, display: string, tone: string, label: string|null, hooks: list<string>, hidden: bool}
 * @phpstan-type Link array{type: 'link', key: string, label: string, icon: string, url: string, active: bool, current: string|null, badge: Badge|null}
 * @phpstan-type Section array{label: string|null, items: list<Link>}
 * @phpstan-type Group array{type: 'group', key: string, label: string, icon: string, active: bool, attention: bool, align: string, sections: list<Section>}
 */
class TopNavigation
{
    /**
     * @param  array{
     *     ticketsInstalled?: bool,
     *     ticketQueueCount?: int,
     *     shareRequestCount?: int,
     *     approvalTotal?: int,
     *     notificationCount?: int,
     *     notificationDisplay?: string,
     * }  $context  ค่าที่ view composer ของ layouts.app คำนวณไว้แล้ว ไม่ยิงคิวรีนับซ้ำที่นี่
     * @return list<Link|Group>
     */
    public static function for(User $user, Request $request, array $context = []): array
    {
        $builder = new self($user, $request, $context);

        $items = match (true) {
            $user->role === 'admin' => $builder->adminItems(),
            $user->role === 'viewer' => $builder->viewerItems(),
            default => $builder->memberItems(),
        };

        // กลุ่มที่ไม่เหลือรายการใดตามสิทธิ์จะไม่ถูกวาดเป็นดร็อปดาวน์ว่าง
        return array_values(array_filter($items, fn (array $item): bool => $item['type'] === 'link' || $item['sections'] !== []));
    }

    private function __construct(
        private readonly User $user,
        private readonly Request $request,
        private readonly array $context,
    ) {}

    /** @return list<Link|Group> */
    private function memberItems(): array
    {
        $isHead = $this->user->isDepartmentHead();

        return [
            $this->link('mytasks', 'งานของฉัน', 'bi-briefcase', route('mytasks.index'), 'mytasks.index', 'mytasks.*'),
            // บันทึกงานประจำวันเป็นเรื่องส่วนตัวเช่นเดียวกับงานของฉัน จึงมาก่อนเมนูของทีม
            $this->link('daily-logs', 'บันทึกงานประจำวัน', 'bi-journal-check', route('daily-logs.index'), 'daily-logs.index', 'daily-logs.*'),
            // หัวหน้าแผนกเปิดบอร์ดในบริบทของแผนกที่ตัวเองดูแล
            $this->link('work-board', 'บอร์ดงาน', 'bi-kanban',
                $isHead ? route('work-board.department', $this->user->department_id) : route('work-board.index'),
                $isHead ? 'work-board.department' : 'work-board.index', 'work-board.*'),
            $this->shareLink(),
            $this->link('workspace', 'กระดานไอเดีย', 'bi-easel', route('workspace.index'), 'workspace.index', 'workspace.*'),
            $this->reportGroup(),
            $this->group('more', 'เพิ่มเติม', 'bi-grid', [
                /*
                 * เครื่องมือดูแลแผนกของหัวหน้า:
                 *   งานข้ามแผนกของทีม — ป้ายนับเรื่องที่รอหัวหน้าตัดสินจริง
                 *   ประกาศ — ประกาศถึงแผนก/ทุกแผนก (AnnouncementPolicy) พนักงานเห็นประกาศในสรุปประจำวัน
                 */
                $this->section('แผนกของฉัน', $isHead ? [
                    $this->link('approvals', 'งานข้ามแผนกของทีม', 'bi-shield-check', route('admin.approvals.index'), 'admin.approvals.index', 'admin.approvals.*',
                        $this->approvalTotal() > 0 ? $this->countBadge($this->approvalTotal(), ['data-approval-count']) : null),
                    $this->link('announcements', 'ประกาศ', 'bi-megaphone', route('announcements.index'), 'announcements.index', 'announcements.*'),
                ] : []),
                $this->section('Smart Ticket', array_filter([$this->ticketLink()])),
                $this->section('การสื่อสาร', [$this->notificationLink()]),
            ], 'end'),
        ];
    }

    /** @return list<Link|Group> */
    private function adminItems(): array
    {
        $ticketSettings = $this->ticketsInstalled() && $this->user->can('manageSettings', Ticket::class)
            ? $this->link('ticket-settings', 'ตั้งค่า Smart Ticket', 'bi-sliders', route('admin.tickets.index'), 'admin.tickets.index', 'admin.tickets.*')
            : null;

        return [
            // การประชุมอยู่ใน Member Workspace ของ admin เพื่อให้ดูในบริบทของสมาชิก จึงไม่มีเมนูของตัวเอง
            $this->link('board', 'บอร์ดรวม', 'bi-kanban', route('board.index'), 'board.index', 'board.*'),
            $this->link('daily-logs', 'บันทึกงานประจำวัน', 'bi-journal-check', route('daily-logs.index'), 'daily-logs.index', 'daily-logs.*'),
            $this->shareLink(),
            $this->link('workspace', 'กระดานไอเดีย', 'bi-easel', route('workspace.index'), 'workspace.index', 'workspace.*'),
            $this->reportGroup(),
            $this->group('admin', 'ผู้ดูแลระบบ', 'bi-gear', [
                $this->section('บุคลากรและแผนก', [
                    $this->link('employees', 'พนักงาน', 'bi-people', route('employees.index'), 'employees.index', 'employees.*'),
                    $this->link('departments', 'จัดการแผนก', 'bi-diagram-3', route('admin.departments.index'), 'admin.departments.index', 'admin.departments.*'),
                ]),
                $this->section('การตั้งค่าระบบ', array_filter([
                    $this->link('accounts', 'บัญชีระบบ', 'bi-person-gear', route('admin.accounts.index'), 'admin.accounts.index', 'admin.accounts.*'),
                    // หมวดงานของบันทึกงานประจำวันเป็นตาราง lookup ที่ admin แก้ได้เอง จึงเป็นการตั้งค่าข้อมูล
                    $this->link('work-log-categories', 'หมวดงานประจำวัน', 'bi-tags', route('admin.work-log-categories.index'), 'admin.work-log-categories.index', 'admin.work-log-categories.*'),
                    $ticketSettings,
                    // บันทึกระบบกับถังขยะรวมเป็นเมนูเดียว อยู่ท้ายกลุ่มเพราะเป็นการย้อนตรวจ ไม่ใช่งานประจำวัน
                    $this->link('audit', 'Audit Log', 'bi-shield-lock', route('admin.audit.index'), 'admin.audit.index', 'admin.audit.*'),
                ])),
            ], 'end'),
            $this->group('more', 'เพิ่มเติม', 'bi-grid', [
                $this->section('การสื่อสารและบริการ', array_filter([
                    $this->notificationLink(),
                    $this->ticketLink(),
                ])),
                /*
                 * admin เป็นผู้ดูแลระบบ ไม่ใช่ผู้อนุมัติงาน จึงเห็นคำขออนุมัติเฉพาะตอนมีของค้างจริง
                 * คิวของ admin ถูกจำกัดเหลือเฉพาะคำขอที่ไม่มีหัวหน้าแผนกรับผิดชอบแล้ว (AdminApprovalQuery::scopeAssignments)
                 */
                $this->section('การทำงาน', $this->approvalTotal() > 0 ? [
                    $this->link('approvals', 'คำขออนุมัติ', 'bi-shield-check', route('admin.approvals.index'), 'admin.approvals.index', 'admin.approvals.*',
                        $this->countBadge($this->approvalTotal(), ['data-approval-count'])),
                ] : []),
            ], 'end'),
        ];
    }

    /**
     * viewer อ่านอย่างเดียว — ไม่มีบันทึกงานประจำวัน แชร์งาน หรือประกาศ เพราะทุก route นั้นกัน viewer ไว้
     *
     * @return list<Link|Group>
     */
    private function viewerItems(): array
    {
        return [
            $this->link('board', 'แดชบอร์ด', 'bi-grid-1x2', route('board.index'), 'board.index', 'board.*'),
            // กระดานที่ตั้งเป็น "ทั้งองค์กร" เปิดให้อ่านได้ทุกคน ส่วนการวาดถูกปิดที่ WorkspaceBoardPolicy
            $this->link('workspace', 'กระดานไอเดีย', 'bi-easel', route('workspace.index'), 'workspace.index', 'workspace.*'),
            $this->reportGroup(),
            $this->link('meetings', 'การประชุม', 'bi-calendar-event', route('meetings.index'), 'meetings.index', 'meetings.*'),
            $this->link('employees', 'พนักงาน', 'bi-people', route('employees.index'), 'employees.index', 'employees.*'),
            $this->notificationLink(),
        ];
    }

    /**
     * รายงานทุกชนิดที่บัญชีนี้มีสิทธิ์อยู่ในกลุ่มเดียว
     *   รายงานปฏิบัติงาน   WorkLogPolicy::viewReport
     *   รายงานโปรเจกต์      ทุกบัญชี (ReportController จำกัดขอบเขตข้อมูลเอง)
     *   รายงานแจ้งปัญหา     TicketPolicy::viewReport (admin / Handler) เมื่อติดตั้ง Smart Ticket แล้ว
     *
     * @return Group
     */
    private function reportGroup(): array
    {
        return $this->group('reports', 'รายงาน', 'bi-clipboard-data', [
            $this->section(null, array_filter([
                $this->user->can('viewReport', WorkLog::class)
                    ? $this->link('reports-operational', 'รายงานปฏิบัติงาน', 'bi-bar-chart-line', route('reports.operational'), 'reports.operational', 'reports.operational.*')
                    : null,
                $this->link('reports-projects', 'รายงานโปรเจกต์', 'bi-folder2-open', route('reports.projects'), 'reports.projects', 'reports.projects.*'),
                $this->ticketsInstalled() && $this->user->can('viewReport', Ticket::class)
                    ? $this->link('reports-tickets', 'รายงานแจ้งปัญหา', 'bi-headset', route('reports.tickets'), 'reports.tickets', 'reports.tickets.*')
                    : null,
            ])),
        ]);
    }

    /**
     * Smart Ticket — เมนูเดียว ชื่อตามความสามารถจริงของบัญชี (ไม่ใช่ชื่อแผนก)
     * ตรงกับหน้าที่ /tickets แสดงจริง (TicketController::index สามทาง):
     *   Handler (isAgent)                      → "จัดการ Ticket" + ป้าย Ticket ที่รอรับงาน
     *   admin ที่ไม่ใช่ Handler                   → "Ticket ทั้งหมด" (อ่านอย่างเดียว)
     *   หัวหน้าแผนกที่รับผิดชอบ Ticket แต่ไม่ใช่ Handler → "Ticket ของแผนก" (อ่านอย่างเดียว)
     *   ผู้แจ้ง (TicketPolicy::create)             → "แจ้งปัญหา IT"
     * ฐานที่ยังไม่ได้ migrate ตาราง Ticket ไม่แสดงเมนูเลย
     *
     * @return Link|null
     */
    private function ticketLink(): ?array
    {
        if (! $this->ticketsInstalled() || ! $this->user->can('viewAny', Ticket::class)) {
            return null;
        }

        $label = match (true) {
            TicketAgentAccess::isAgent($this->user) => 'จัดการ Ticket',
            TicketAgentAccess::seesAllTickets($this->user) => 'Ticket ทั้งหมด',
            TicketAgentAccess::seesDepartmentTickets($this->user) => 'Ticket ของแผนก',
            $this->user->can('create', Ticket::class) => 'แจ้งปัญหา IT',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        $queue = (int) ($this->context['ticketQueueCount'] ?? 0);

        return $this->link('tickets', $label, 'bi-headset', route('tickets.index'), 'tickets.index', 'tickets.*',
            $queue > 0 ? $this->countBadge($queue, ['data-ticket-queue-count'], 'รอรับงาน '.$queue.' รายการ') : null);
    }

    /**
     * แชร์งาน — ป้ายนับเฉพาะคำขอร่วมงานที่รอผู้ใช้ตัดสินในฐานะผู้แชร์
     * จำนวนงานที่แชร์เปิดรับเป็นข้อมูล ไม่ใช่เรื่องรอทำ จึงไม่ขึ้นป้าย
     *
     * @return Link
     */
    private function shareLink(): array
    {
        $count = (int) ($this->context['shareRequestCount'] ?? 0);

        return $this->link('shares', 'แชร์งาน', 'bi-share', route('shares.index'), 'shares.index', 'shares.*',
            $count > 0 ? $this->countBadge($count, ['data-share-request-count'], 'คำขอร่วมงานรอคุณอนุมัติ '.$count.' รายการ', 'request') : null);
    }

    /**
     * การแจ้งเตือน — ป้ายถูกวาดเสมอแม้เป็นศูนย์ (hidden) เพราะ realtime-sync.js
     * อัปเดตทุก [data-notification-count] ในหน้าเมื่อมีแจ้งเตือนใหม่
     *
     * @return Link
     */
    private function notificationLink(): array
    {
        $count = (int) ($this->context['notificationCount'] ?? 0);

        return $this->link('notifications', 'การแจ้งเตือน', 'bi-bell', route('notifications.index'), 'notifications.index', 'notifications.*', [
            'count' => $count,
            'display' => (string) ($this->context['notificationDisplay'] ?? $count),
            'tone' => 'danger',
            'label' => null,
            'hooks' => ['data-notification-count', 'data-nav-notification-count'],
            'hidden' => $count === 0,
        ]);
    }

    /**
     * @param  Badge|null  $badge
     * @return Link
     */
    private function link(string $key, string $label, string $icon, string $url, string $pageRoute, string $pattern, ?array $badge = null): array
    {
        $active = $this->request->routeIs($pattern, $pageRoute);

        return [
            'type' => 'link',
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
            'active' => $active,
            // หน้าหลักของเมนูเป็น "page" ส่วนหน้าย่อยภายใต้เมนูเดียวกันเป็น "location"
            'current' => $active ? ($this->request->routeIs($pageRoute) ? 'page' : 'location') : null,
            'badge' => $badge,
        ];
    }

    /**
     * @param  list<Section>  $sections
     * @return Group
     */
    private function group(string $key, string $label, string $icon, array $sections, string $align = 'start'): array
    {
        $sections = array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== []));
        $links = array_merge(...array_map(fn (array $section): array => $section['items'], $sections ?: [['items' => []]]));

        return [
            'type' => 'group',
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'active' => collect($links)->contains('active', true),
            /*
             * จุดเตือนบนหัวดร็อปดาวน์เมื่อข้างในมีเรื่องรอผู้ใช้ (คำขออนุมัติ Ticket รอรับงาน)
             * ไม่นับการแจ้งเตือน เพราะกระดิ่งบน Topbar บอกจำนวนนั้นอยู่แล้วตลอดเวลา
             */
            'attention' => collect($links)->contains(fn (array $link): bool => $link['badge'] !== null
                && $link['key'] !== 'notifications'
                && $link['badge']['count'] > 0),
            'align' => $align,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<int, Link|null>  $items
     * @return Section
     */
    private function section(?string $label, array $items): array
    {
        return ['label' => $label, 'items' => array_values(array_filter($items))];
    }

    /**
     * @param  list<string>  $hooks  attribute ที่สคริปต์เดิมใช้หาป้ายนี้ (เช่น data-approval-count)
     */
    private function countBadge(int $count, array $hooks, ?string $label = null, string $tone = 'danger'): array
    {
        return [
            'count' => $count,
            'display' => $count > 99 ? '99+' : (string) $count,
            'tone' => $tone,
            'label' => $label,
            'hooks' => $hooks,
            'hidden' => false,
        ];
    }

    private function approvalTotal(): int
    {
        return (int) ($this->context['approvalTotal'] ?? 0);
    }

    private function ticketsInstalled(): bool
    {
        return (bool) ($this->context['ticketsInstalled'] ?? false);
    }
}
