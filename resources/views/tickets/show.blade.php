@extends('layouts.app')

@section('title', $ticket->ticket_number)

@push('styles')
    @vite('resources/css/pages/tickets.css')
@endpush

@push('scripts')
    @vite(['resources/js/pages/tickets/form.js', 'resources/js/pages/tickets/detail.js'])
@endpush

@section('content')
@php
    use App\Models\Ticket;
    use App\Support\TicketDesign;

    $status = TicketDesign::ticketStatus($ticket);
@endphp
{{--
    หัว Ticket: เลข สถานะ และเวลาแจ้ง · การ์ด "รายละเอียดปัญหา" (components/problem): ปัญหาที่แจ้งและข้อเท็จจริงพร้อมไอคอน
    จอกว้าง: หัวเต็มความกว้าง แล้วสองคอลัมน์ — main (รายละเอียด รูป ประวัติ) ซ้าย · aside (การดำเนินการ ผู้เข้าร่วม ผู้ประสานงาน) ขวา
    มือถือ: wrapper ทั้งสองเป็น display: contents แล้วเรียงด้วย order เป็น หัว → รายละเอียด → รูป → ผู้เข้าร่วม
    → ผู้ประสานงาน → ประวัติ → การดำเนินการ (ค่า order อยู่ใน tickets/responsive.css) markup ชุดเดียว ไม่มีสำเนาสำหรับมือถือ
    ปุ่มของเจ้าหน้าที่ (ปิดงาน ติดตามงาน ยกเลิกงาน) อยู่ในการ์ดการดำเนินการ — ปิดงานเป็นขั้นตอนที่รวมผลการแก้ไขและรูป
--}}
<div class="ticket-page ticket-page--detail" data-ticket-detail>
    {{-- /tickets เป็นคิวงานของทีม IT หรือหน้าแจ้งปัญหาของผู้ใช้ทั่วไป (TicketController::index) — ป้ายบอกปลายทางจริง --}}
    <nav class="ticket-crumbs" aria-label="ตำแหน่งของหน้า">
        <a class="ticket-back" href="{{ route('tickets.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            {{ \App\Support\TicketAgentAccess::seesAllTickets(auth()->user()) ? 'กลับไปหน้ารับแจ้งปัญหา' : 'กลับไปหน้าแจ้งปัญหา IT' }}
        </a>
        <span class="ticket-crumbs__here" aria-current="page">Ticket / {{ $ticket->ticket_number }}</span>
    </nav>

    @include('tickets.components.flash')

    <div class="ticket-detail">
        <header class="ticket-detail__header panel ticket-panel">
            <span class="ticket-detail__mark" aria-hidden="true"><i class="bi bi-ticket-detailed"></i></span>
            <div class="ticket-detail__headline">
                <span class="eyebrow">Smart Ticket</span>
                <div class="ticket-detail__heading">
                    <h1>{{ $ticket->ticket_number }}</h1>
                    <span class="badge-soft {{ $status['tone'] }} ticket-status">{{ $status['label'] }}</span>
                </div>
                <p class="ticket-detail__when">
                    แจ้งเมื่อ <time datetime="{{ $ticket->created_at->toIso8601String() }}">{{ TicketDesign::when($ticket->created_at) }}</time>
                    @if ($ticket->reopen_count > 0)
                        · เปิดใหม่ {{ $ticket->reopen_count }} ครั้ง
                    @endif
                </p>
            </div>
        </header>

        <div class="ticket-detail__main">
            @include('tickets.components.problem')

            @if ($ticket->attachments->isNotEmpty())
                <section class="ticket-detail__photos panel ticket-panel" aria-labelledby="ticket-photos-title">
                    <h2 class="ticket-panel__title" id="ticket-photos-title">รูปภาพประกอบ <span class="ticket-panel__count">{{ $ticket->attachments->count() }}</span></h2>
                    @include('tickets.components.photo-gallery', ['photos' => $ticket->attachments])
                </section>
            @endif

            @include('tickets.components.timeline')

            @if ($ticket->isClosed())
                <p class="ticket-detail__closed-note panel ticket-panel">Ticket นี้ปิดแล้ว — ถ้าปัญหากลับมาอีก เจ้าหน้าที่ IT เปิดงานใหม่ได้</p>
            @endif
        </div>

        <div class="ticket-detail__aside">
            @if ($hasActions)
                @include('tickets.components.actions')
            @endif

            @include('tickets.components.participants')

            @if ($can['setCoordinator'] && $coordinatorCandidates->isNotEmpty())
                @include('tickets.components.coordinator')
            @endif
        </div>
    </div>

    @if ($can['followUp'])
        @include('tickets.components.status-modal', [
            'action' => Ticket::ACTION_FOLLOW_UP, 'title' => 'ย้ายเป็นงานที่ต้องติดตาม', 'noteLabel' => 'เหตุผลที่ต้องติดตาม',
            'placeholder' => 'เช่น รออะไหล่จากผู้ขาย คาดว่าได้รับวันพฤหัส', 'submitLabel' => 'บันทึกงานที่ต้องติดตาม',
            'tone' => 'warning', 'withDate' => true,
        ])
    @endif
    @if ($can['close'])
        {{-- ปิดงานแบบขั้นตอน: ผลการแก้ไข → รูปหลังแก้ไข (ไม่บังคับ) → ตรวจทาน --}}
        @include('tickets.components.close-modal')
    @endif
    @if ($can['cancel'])
        {{-- ยกเลิกงาน: ปิดแบบยกเลิก ต้องระบุเหตุผล (ผู้แจ้งเห็นเหตุผลในความคืบหน้า) --}}
        @include('tickets.components.status-modal', [
            'action' => Ticket::ACTION_CANCEL, 'title' => 'ยกเลิกงาน', 'noteLabel' => 'เหตุผลที่ยกเลิก',
            'placeholder' => 'เช่น ผู้แจ้งแก้ไขได้เองแล้ว หรือแจ้งซ้ำกับ Ticket อื่น', 'submitLabel' => 'ยืนยันยกเลิกงาน',
            'tone' => 'danger', 'withDate' => false,
        ])
    @endif
    @if ($can['reopen'])
        @include('tickets.components.status-modal', [
            'action' => Ticket::ACTION_REOPEN, 'title' => 'เปิดงานใหม่', 'noteLabel' => 'เหตุผลที่เปิดใหม่',
            'placeholder' => 'เช่น ผู้ใช้แจ้งว่าอาการเดิมกลับมาอีก', 'submitLabel' => 'เปิดงานใหม่',
            'tone' => 'primary', 'withDate' => false,
        ])
    @endif
</div>
@endsection
