@extends('layouts.app')

@section('title', 'รายงาน')

@push('styles')
    @vite('resources/css/pages/reports.css')
@endpush

@section('content')
{{--
    หน้าเลือกประเภทรายงาน — ใช้ร่วมกันทุก role

    รายการการ์ดมาจาก ReportController::landingCards() ที่เดียว ไม่ใช่การเช็ค role
    ใน Blade เพราะ "ใครเห็นรายงานไหน" เป็นเรื่องสิทธิ์ที่ต้องตัดสินฝั่งเซิร์ฟเวอร์
    การซ่อนการ์ดในหน้าจอไม่ใช่การบังคับสิทธิ์ (ปลายทางแต่ละหน้าตรวจซ้ำอีกชั้น)

    พนักงานทั่วไปเห็นสองการ์ด: รายงานตัวเอง และรายงานปฏิบัติงานของตัวเอง
    หัวหน้าแผนกและ admin เห็นรายงานโปรเจกต์ของแผนก (ภาพรวม) และรายบุคคลเพิ่มขึ้นมา
--}}
<div class="report-landing" aria-labelledby="report-landing-title">
    <header class="report-landing__header">
        <span class="report-landing__eyebrow">Smart Goal Analytics</span>
        <h1 id="report-landing-title">รายงาน</h1>
        <p>เลือกประเภทของรายงานที่คุณต้องการดู</p>
    </header>

    <section class="report-landing__grid" aria-label="ประเภทรายงาน">
        @foreach($cards as $card)
            @include('reports.components.landing-card', $card)
        @endforeach
    </section>

    <aside class="report-landing__note" aria-label="คำแนะนำการใช้งาน">
        <i class="bi bi-lightbulb" aria-hidden="true"></i>
        <div>
            <strong>คำแนะนำ</strong>
            <p>รายงานปฏิบัติงานตอบคำถามว่างานประจำของวันนี้ตรวจไปแล้วหรือยัง ส่วนรายงานอื่นใช้ดูผลงานย้อนหลัง</p>
        </div>
    </aside>
</div>
@endsection
