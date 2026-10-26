@extends('tickets.public.layout')

@section('title', 'ส่งข้อมูลสำเร็จ')

{{--
    แสดงเฉพาะสรุปที่ session นี้เพิ่งส่งเอง (เลข Ticket ชื่อผู้แจ้ง สาขา) — ไม่มีข้อมูลอื่นของ Ticket หรือของระบบ
    ไม่มีหน้าหลักสาธารณะ (/ ส่งไปหน้าเข้าสู่ระบบของพนักงาน) จึงมีปุ่มเดียวคือแจ้งปัญหาใหม่ (/support พร้อม submission key ใหม่)
    ไม่มีปุ่ม "ติดต่อทีม IT" และไม่มีการติดตามสถานะสาธารณะ เพราะระบบยังไม่มีช่องทางเหล่านั้น
--}}
@section('content')
<section class="ticket-public__card ticket-public__done" aria-labelledby="ticket-done-title">
    @include('tickets.public.brand')

    <span class="ticket-public__done-icon" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
    <h1 class="ticket-public__done-title" id="ticket-done-title">ส่งข้อมูลสำเร็จ</h1>
    <p class="ticket-public__done-lead">ทีม IT ได้รับข้อมูลของคุณแล้ว</p>
    <p class="ticket-public__done-text">ทีม IT จะตรวจสอบเรื่องของคุณโดยเร็วที่สุด</p>

    <dl class="ticket-public__summary">
        <div class="ticket-public__summary-row">
            <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-file-earmark-text-fill"></i></span>
            <div>
                <dt>เลขที่ Ticket</dt>
                <dd class="ticket-public__number" data-ticket-number>{{ $ticketNumber }}</dd>
            </div>
        </div>
        @if ($requesterName !== '')
            <div class="ticket-public__summary-row">
                <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                <div>
                    <dt>ชื่อผู้แจ้ง</dt>
                    <dd>{{ $requesterName }}</dd>
                </div>
            </div>
        @endif
        @if ($locationName !== '')
            <div class="ticket-public__summary-row">
                <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-geo-alt-fill"></i></span>
                <div>
                    <dt>สาขา / สถานที่</dt>
                    <dd>{{ $locationName }}</dd>
                </div>
            </div>
        @endif
    </dl>

    <a class="ticket-button ticket-button--primary ticket-public__again" href="{{ route('support.create') }}">
        <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> แจ้งปัญหาใหม่
    </a>
</section>
@endsection
