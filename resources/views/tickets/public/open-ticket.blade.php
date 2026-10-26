@extends('tickets.public.layout')

@section('title', 'มีเรื่องที่ยังไม่ปิด')

{{--
    บัญชี LINE นี้มี Ticket ที่ยังไม่ปิด (TicketSubmissionService::openTicketForLine) — แจ้งเรื่องใหม่ได้เมื่อเจ้าหน้าที่ปิดงานแล้ว
    แสดงเฉพาะข้อมูลของ Ticket ที่บัญชี LINE นี้แจ้งเอง: เลข สถานะ เวลาแจ้ง และหัวข้อ (ไม่มีชื่อเจ้าหน้าที่หรือข้อมูลภายใน)
    พารามิเตอร์: $ticket
--}}
@php
    use App\Support\TicketDesign;

    $status = TicketDesign::status($ticket->status);
@endphp
@section('content')
<section class="ticket-public__card ticket-public__done" aria-labelledby="ticket-open-title">
    @include('tickets.public.brand')

    <span class="ticket-public__done-icon ticket-public__done-icon--muted" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
    <h1 class="ticket-public__done-title" id="ticket-open-title">คุณมีเรื่องที่ยังไม่ปิด</h1>
    <p class="ticket-public__done-text">
        ทีม IT กำลังดูแลเรื่องเดิมของคุณอยู่ — แจ้งเรื่องใหม่ได้เมื่อเจ้าหน้าที่ปิดงานเรื่องนี้แล้ว
    </p>

    <dl class="ticket-public__summary">
        <div class="ticket-public__summary-row">
            <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-file-earmark-text-fill"></i></span>
            <div>
                <dt>เลขที่ Ticket</dt>
                <dd class="ticket-public__number" data-ticket-number>{{ $ticket->ticket_number }}</dd>
            </div>
        </div>
        <div class="ticket-public__summary-row">
            <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-activity"></i></span>
            <div>
                <dt>สถานะ</dt>
                <dd>{{ $status['label'] }}</dd>
            </div>
        </div>
        <div class="ticket-public__summary-row">
            <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-chat-left-text-fill"></i></span>
            <div>
                <dt>เรื่องที่แจ้ง</dt>
                <dd>{{ $ticket->summary }}</dd>
            </div>
        </div>
        <div class="ticket-public__summary-row">
            <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-clock-fill"></i></span>
            <div>
                <dt>แจ้งเมื่อ</dt>
                <dd><time datetime="{{ $ticket->created_at->toIso8601String() }}">{{ TicketDesign::when($ticket->created_at) }}</time></dd>
            </div>
        </div>
    </dl>
</section>
@endsection
