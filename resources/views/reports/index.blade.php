@extends('layouts.app')

@section('title', 'รายงาน')

@push('styles')
    @vite('resources/css/pages/reports.css')
@endpush

@section('content')
<div class="report-landing" aria-labelledby="report-landing-title">
    <header class="report-landing__header">
        <span class="report-landing__eyebrow">Smart Goal Analytics</span>
        <h1 id="report-landing-title">รายงาน</h1>
        <p>เลือกประเภทของรายงานที่คุณต้องการดู</p>
    </header>

    <section class="report-landing__grid" aria-label="ประเภทรายงาน">
        @include('reports.components.landing-card', [
            'tone' => 'organization',
            'icon' => 'bi-bar-chart-line',
            'title' => 'ดูภาพรวมองค์กร',
            'description' => 'ติดตามแนวโน้มและภาพรวมการทำงานของทุกแผนกในช่วงเวลาที่เลือก',
            'features' => ['แนวโน้มงานและสถิติองค์กร', 'ประสิทธิภาพแต่ละแผนก', 'สถานะและความสำคัญของงาน', 'งานที่ต้องติดตาม'],
            'cta' => 'เข้าสู่รายงานภาพรวมองค์กร',
            'route' => route('reports.organization'),
        ])

        @include('reports.components.landing-card', [
            'tone' => 'employee',
            'icon' => 'bi-person-lines-fill',
            'title' => 'ดูรายงานรายบุคคล',
            'description' => 'เลือกพนักงานเพื่อดูผลงานจากงานที่รับผิดชอบจริงและตรวจสอบรายละเอียดได้',
            'features' => ['สถิติการทำงานของพนักงาน', 'อัตราส่งงานตรงเวลา', 'งานที่รับผิดชอบ', 'รายละเอียดงานสำหรับตรวจสอบ'],
            'cta' => 'เลือกพนักงานเพื่อดูรายงาน',
            'route' => route('reports.employees.index'),
        ])
    </section>

    <aside class="report-landing__note" aria-label="คำแนะนำการใช้งาน">
        <i class="bi bi-lightbulb" aria-hidden="true"></i>
        <div><strong>คำแนะนำ</strong><p>ใช้รายงานภาพรวมเพื่อติดตามทั้งองค์กร หรือเลือกรายงานรายบุคคลเพื่อดูงานของพนักงานแต่ละคน</p></div>
    </aside>
</div>
@endsection
