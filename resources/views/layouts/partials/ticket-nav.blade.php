{{--
    เมนู Smart Ticket ในแถบข้าง — เมนูเดียว ชื่อตามความสามารถจริงของบัญชี (ไม่ใช่ชื่อแผนก)
    ตรงกับหน้าที่ /tickets แสดงจริง (TicketController::index สามทาง):

      เห็น Ticket ทั้งหมด (TicketAgentAccess::seesAllTickets)
        - Handler (isAgent)          → "จัดการ Ticket" + ป้าย Ticket ที่รอรับงาน (AppServiceProvider คำนวณเฉพาะ agent)
        - admin ที่ไม่ใช่ Handler      → "Ticket ทั้งหมด" (อ่านอย่างเดียว)
      หัวหน้าแผนกของแผนกที่รับผิดชอบ Ticket แต่ไม่ใช่ Handler → "Ticket ของแผนก" (อ่านอย่างเดียว)
      หัวหน้าแผนกที่แผนกไม่ได้จัดการ Ticket → "แจ้งปัญหา IT" เหมือนพนักงานทั่วไป
      ผู้แจ้ง (TicketPolicy::create)   → "แจ้งปัญหา IT"

    รายงานแจ้งปัญหาอยู่ในกลุ่ม "รายงาน" (layouts/partials/report-nav) ร่วมกับรายงานอื่น
    การซ่อนเมนูไม่ใช่การให้สิทธิ์ — ทุก route ตรวจ TicketPolicy เอง
    $withSection = true → มีหัวข้อ "Smart Ticket" ของตัวเอง (พนักงาน/หัวหน้า) · admin วางในหมวดเดิมของตัวเอง
    $ticketsInstalled = false เมื่อฐานยังไม่ได้ migrate ตาราง Ticket — ไม่แสดงเมนูเลย
--}}
@if (($ticketsInstalled ?? false) && auth()->user()->can('viewAny', \App\Models\Ticket::class))
    @php
        $ticketUser = auth()->user();
        $ticketLabel = match (true) {
            \App\Support\TicketAgentAccess::isAgent($ticketUser) => 'จัดการ Ticket',
            \App\Support\TicketAgentAccess::seesAllTickets($ticketUser) => 'Ticket ทั้งหมด',
            \App\Support\TicketAgentAccess::seesDepartmentTickets($ticketUser) => 'Ticket ของแผนก',
            $ticketUser->can('create', \App\Models\Ticket::class) => 'แจ้งปัญหา IT',
            default => null,
        };
        $ticketCount = (int) ($ticketQueueCount ?? 0);
        $onTickets = request()->routeIs('tickets.*');
    @endphp

    @if ($ticketLabel !== null)
        @if ($withSection ?? false)
            <div class="nav-section-label">Smart Ticket</div>
        @endif
        <a href="{{ route('tickets.index') }}"
            class="nav-item {{ $onTickets ? 'active' : '' }}"
            @if($onTickets) aria-current="{{ request()->routeIs('tickets.index') ? 'page' : 'location' }}" @endif>
            <i class="bi bi-headset" aria-hidden="true"></i>
            <span class="nav-item__label">{{ $ticketLabel }}</span>
            @if ($ticketCount > 0)
                <span class="nav-item__count" aria-label="รอรับงาน {{ $ticketCount }} รายการ">{{ $ticketCount }}</span>
            @endif
        </a>
    @endif
@endif
