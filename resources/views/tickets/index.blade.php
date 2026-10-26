@extends('layouts.app')

@section('title', 'รับแจ้งปัญหา')

@push('styles')
    @vite('resources/css/pages/tickets.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/tickets/index.js')
@endpush

@section('content')
{{--
    หน้ารับแจ้งปัญหาของทีม IT — เฉพาะบัญชีที่เห็น Ticket ทั้งหมด (TicketAgentAccess::seesAllTickets)
    ผู้ใช้อื่นได้หน้า tickets/landing (TicketController::index)

    ลำดับตามสัญญาออกแบบ (docs/UI_DESIGN_SYSTEM.md): หัวหน้า + การกระทำ → ภาพรวม → งานที่ต้องทำ (คิว)
    ทุกตัวเลขมาจาก TicketQueryService ไม่มีตัวเลขเปรียบเทียบหรือสถานะออนไลน์ที่ระบบไม่ได้บันทึก
    admin ที่ไม่ใช่เจ้าหน้าที่ IT เห็นหน้านี้แบบอ่านอย่างเดียว — ปุ่มรับงาน/เข้าร่วมแสดงในคิวและ modal เฉพาะที่ TicketPolicy อนุญาต
    ไม่มีปุ่มแจ้งปัญหา — ผู้ที่เห็นหน้านี้ (admin / Handler) แจ้ง Ticket เองไม่ได้ (แยกหน้าที่ — TicketAgentAccess::canReport)

    ข้อมูลล่าสุดอัตโนมัติ ไม่มีปุ่มรีเฟรช (tickets/desk.js): event ticket ของ realtime-sync, กลับมาที่แท็บ และรอบตรวจขณะเปิดหน้าอยู่
    โหลดหน้านี้ (URL เดิม) แบบ async แล้วแทนที่เฉพาะ [data-ticket-overview], [data-ticket-queue-results] และเวลาข้อมูล
--}}
<div class="ticket-page ticket-desk" data-ticket-list>
    <header class="ticket-desk__head">
        <div class="ticket-desk__title">
            <span class="ticket-desk__eyebrow">Smart Ticket</span>
            <h1>รับแจ้งปัญหา</h1>
            <p>
                @if ($isAgent)
                    รับงาน ติดตาม และปิด Ticket ร่วมกับทีม — เจ้าหน้าที่หลายคนเข้าร่วม Ticket เดียวกันได้
                @else
                    ภาพรวม Ticket ทั้งระบบ (ดูอย่างเดียว — การรับและปิดงานเป็นของเจ้าหน้าที่ IT)
                @endif
            </p>
        </div>
        @if ($ticketSoundToggle)
            {{-- เปิด/ปิดเสียงแจ้ง Ticket ใหม่ของเบราว์เซอร์นี้ (มีผลทุกหน้าและทุกแท็บ) — components/ticket-alert.js ตั้งสถานะจริงตอนโหลด --}}
            <div class="ticket-desk__sound">
                <span class="ticket-desk__sound-label">เสียงแจ้งเตือน Ticket ใหม่: <strong data-ticket-sound-state>เปิด</strong></span>
                <button type="button" class="icon-btn icon-btn--touch-target" data-ticket-sound-toggle aria-pressed="true"
                    aria-label="ปิดเสียงแจ้งเตือน Ticket ใหม่" title="เสียงแจ้งเตือน Ticket ใหม่: เปิด">
                    <i class="bi bi-volume-up-fill" aria-hidden="true"></i>
                </button>
            </div>
        @endif
        @include('tickets.components.desk-stamp')
    </header>

    @include('tickets.components.flash')

    {{-- การ์ดภาพรวมเป็นตัวกรองสถานะของคิวด้วย (คงคำค้นหาและสาขาไว้) — คิวจึงไม่มีแท็บสถานะซ้ำ --}}
    @include('tickets.components.overview', ['overview' => $overview, 'createdToday' => $createdToday, 'filters' => $filters])

    {{-- คิวงานกว้างเต็มพื้นที่ (ตาราง 7 คอลัมน์) --}}
    @include('tickets.components.queue', ['statusTabs' => false])

    @include('tickets.components.preview-modal')
</div>
@endsection
