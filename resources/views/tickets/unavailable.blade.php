@extends('tickets.public.layout')

@section('title', 'Smart Ticket ยังไม่พร้อมใช้งาน')

{{-- แสดงโดย EnsureTicketsInstalled เมื่อฐานข้อมูลยังไม่ได้ migrate ตาราง Smart Ticket --}}
@section('content')
<section class="ticket-public__card ticket-public__done" aria-labelledby="ticket-unavailable-title">
    @include('tickets.public.brand')

    <span class="ticket-public__done-icon ticket-public__done-icon--muted" aria-hidden="true"><i class="bi bi-tools"></i></span>
    <h1 class="ticket-public__done-title" id="ticket-unavailable-title">Smart Ticket ยังไม่พร้อมใช้งาน</h1>
    <p class="ticket-public__done-text">{{ $message }}</p>
</section>
@endsection
