@extends('layouts.app')

@section('title', 'แจ้งปัญหา IT')

@push('styles')
    @vite('resources/css/pages/tickets.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/tickets/index.js')
@endpush

@section('content')
{{--
    หน้าของผู้แจ้ง (พนักงานทั่วไป — TicketAgentAccess::canReport) — TicketController::reporterPage

    การ์ดเดียวตามสถานะจริงของ Ticket ที่ยังไม่ปิด (components/reporter-card) และ modal อ่านอย่างเดียว
    (components/readonly-preview) ของใบที่ยังไม่ปิด / ใบจากลิงก์ ?ticket (ตรวจ TicketPolicy::view) — ใบที่ปิดแล้วเปิดจาก modal ประวัติ
    ผู้แจ้งไม่เข้าหน้ารายละเอียดเต็มของเจ้าหน้าที่ — TicketController::show พากลับมาที่นี่

    หนึ่งผู้แจ้งมี Ticket ที่ยังไม่ปิดได้ใบเดียว: ฟอร์มแจ้งปัญหา (submit modal เดิม) มีเฉพาะตอนไม่มีใบเปิด
    สถานะใหม่มาจาก server ทุกครั้ง (no-store) และ tickets/reporter.js โหลดการ์ดใหม่เมื่อมีแจ้งเตือน Ticket ผ่าน realtime-sync เดิม
--}}
<div class="ticket-page ticket-landing" data-ticket-reporter>
    @include('tickets.components.flash')

    {{-- ส่งจากหน้าเก่าขณะมีใบเปิดอยู่แล้ว: ไม่มี modal ฟอร์มให้แสดงข้อผิดพลาด จึงแสดงเป็นแถบในหน้า --}}
    @if (! $canCreate && $errors->has('form'))
        <div class="ticket-flash ticket-flash--error" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>{{ $errors->first('form') }}</span>
        </div>
    @endif

    @include('tickets.components.reporter-card', ['hasModal' => $modalTicket !== null])

    @if ($modalTicket)
        <div class="modal fade ticket-preview" id="ticket-reporter-modal" tabindex="-1"
            aria-labelledby="ticket-readonly-title-{{ $modalTicket->id }}" aria-hidden="true"
            data-ticket-reporter-modal @if ($openModalOnLoad) data-open-on-load @endif>
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ticket-preview__dialog">
                <div class="modal-content ticket-preview__content">
                    @include('tickets.components.readonly-preview', ['ticket' => $modalTicket])
                </div>
            </div>
        </div>
    @endif

    {{-- ประวัติ Ticket ของผู้แจ้ง (modal ของปุ่มใต้การ์ด) — อยู่ใน data-ticket-reporter เพื่อให้ reporter.js แทนที่เมื่อสถานะเปลี่ยน --}}
    @include('tickets.components.reporter-history')

    @if ($canCreate)
        @include('tickets.components.submit-modal')
    @endif
</div>
@endsection
