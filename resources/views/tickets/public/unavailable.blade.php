@extends('tickets.public.layout')

@section('title', 'ปิดให้บริการชั่วคราว')

{{--
    ระบบบังคับผูก LINE (config tickets.public.require_line) แต่ยังไม่ได้ตั้งค่าช่องทาง LINE Login
    (LineLogin::unavailable) — ตอบ 503 และไม่แสดงฟอร์ม เพื่อไม่ให้มี Ticket ที่ไม่ผูก LINE
    ไม่บอกรายละเอียดการตั้งค่าภายในแก่ผู้ใช้ภายนอก
--}}
@section('content')
<section class="ticket-public__card ticket-public__done" aria-labelledby="ticket-unavailable-title">
    @include('tickets.public.brand')

    <span class="ticket-public__done-icon ticket-public__done-icon--muted" aria-hidden="true"><i class="bi bi-tools"></i></span>
    <h1 class="ticket-public__done-title" id="ticket-unavailable-title">ปิดให้บริการชั่วคราว</h1>
    <p class="ticket-public__done-text">
        ระบบแจ้งปัญหาออนไลน์ยังไม่พร้อมใช้งานในขณะนี้ กรุณาติดต่อทีม IT ทางโทรศัพท์ หรือลองใหม่ภายหลัง
    </p>
</section>
@endsection
