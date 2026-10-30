@extends('layouts.app')

@section('title', 'Ticket ของแผนก')

@push('styles')
    @vite('resources/css/pages/tickets.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/tickets/index.js')
@endpush

@section('content')
{{--
    หัวหน้าแผนกของแผนกที่รับผิดชอบ Ticket แต่ไม่ใช่ Handler — Ticket ของแผนกแบบอ่านอย่างเดียว (TicketController::index)
    หัวหน้าแผนกที่แผนกไม่ได้จัดการ Ticket ไม่เห็นหน้านี้ — เป็นผู้แจ้งเหมือนพนักงานทั่วไป (TicketAgentAccess::seesDepartmentTickets)

    ขอบเขตคือกติกาเดิม TicketQueryService::visibleTo() = TicketPolicy::view() (ใบที่ตัวเองแจ้ง + ใบที่สมาชิกแผนกแจ้ง
    อ้างอิงแผนกจาก snapshot ตอนแจ้ง) ตัวกรองและการแบ่งหน้าเป็นชุดเดียวกับคิวงาน (components/queue แบบ readonly)
    ดูรายละเอียดผ่าน modal อ่านอย่างเดียว (TicketController::preview → components/readonly-preview)
    ไม่มีปุ่มแจ้งปัญหา รับงาน เข้าร่วม หรือปิดงาน — หัวหน้าแผนกแจ้ง Ticket เองไม่ได้ (TicketAgentAccess::canReport)
    ลิงก์ ?ticket={id} (แจ้งเตือน / redirect จาก show) เปิด modal ของใบนั้นเองเมื่อ view อนุญาต (tickets/desk.js)
    ข้อมูลล่าสุดอัตโนมัติแบบเดียวกับคิวงาน (tickets/desk.js) ไม่มีปุ่มรีเฟรช
--}}
<div class="ticket-page ticket-desk" data-ticket-list
    @if ($deepLinkTicket) data-ticket-open-preview="{{ route('tickets.preview', $deepLinkTicket) }}" @endif>
    <header class="ticket-desk__head">
        <div class="ticket-desk__title">
            <span class="ticket-desk__eyebrow">Smart Ticket</span>
            <h1>Ticket ของแผนก</h1>
            <p>ติดตามเรื่องที่สมาชิกแผนกของคุณแจ้งทีม IT (ดูอย่างเดียว) — บัญชีหัวหน้าแผนกแจ้งปัญหาเองไม่ได้</p>
        </div>
        @include('tickets.components.desk-stamp')
    </header>

    @include('tickets.components.flash')

    @include('tickets.components.queue', ['readonly' => true])

    @include('tickets.components.preview-modal')
</div>
@endsection
