@extends('tickets.public.layout')

@section('title', 'แจ้งปัญหา IT')

{{--
    ยังไม่ได้ยืนยันตัวด้วย LINE (PublicTicketController::create เมื่อ LineLogin::enabled())
    ต้องเข้าสู่ระบบด้วย LINE ก่อนเห็นฟอร์ม — กันการเปิดเคสซ้ำ: หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว
    ปุ่มเป็นลิงก์ธรรมดาไป support.line.redirect ทำงานได้โดยไม่ใช้ JavaScript
--}}
@section('content')
<section class="ticket-public__card ticket-public__done" aria-labelledby="ticket-line-title">
    @include('tickets.public.brand')

    <span class="ticket-public__done-icon ticket-public__done-icon--line" aria-hidden="true"><i class="bi bi-line"></i></span>
    <h1 class="ticket-public__done-title" id="ticket-line-title">เข้าสู่ระบบด้วย LINE</h1>
    <p class="ticket-public__done-text">
        ยืนยันตัวตนด้วยบัญชี LINE ก่อนแจ้งปัญหา เพื่อให้ทีม IT ติดตามเรื่องของคุณได้ถูกคน
        — แต่ละบัญชีแจ้งได้ครั้งละหนึ่งเรื่อง และแจ้งเรื่องใหม่ได้เมื่อเจ้าหน้าที่ปิดงานเรื่องเดิมแล้ว
    </p>

    @error('line')
        <div class="ticket-form__alert" role="alert">
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
            <span>{{ $message }}</span>
        </div>
    @enderror

    <a class="ticket-button ticket-button--line ticket-public__again" href="{{ route('support.line.redirect') }}">
        <i class="bi bi-line" aria-hidden="true"></i> เข้าสู่ระบบด้วย LINE
    </a>
</section>
@endsection
