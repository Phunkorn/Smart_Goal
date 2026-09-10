@extends('layouts.app')
@section('title', 'รายงานของฉัน')
@push('styles')
    @vite('resources/css/pages/report-my.css')
@endpush
@push('scripts')
    @vite('resources/js/pages/reports/my.js')
@endpush

@section('content')
<div class="personal-report">
    <header class="personal-report__header">
        <div>
            <div class="personal-report__eyebrow"><i class="bi bi-activity" aria-hidden="true"></i> Personal workspace</div>
            <h1>ภาพรวมงานของฉัน</h1>
            <p>ติดตามงานที่ต้องลงมือทำ กำหนดส่ง และภาระงานในช่วง {{ $filters['period_label'] }}</p>
        </div>
        <div class="personal-report__actions">
            @if(auth()->user()->isDepartmentHead())
                <a href="{{ route('reports.employees.index') }}" class="personal-report__button"><i class="bi bi-people" aria-hidden="true"></i> ดูรายงานลูกทีม</a>
            @endif
            <button type="button" class="personal-report__button personal-report__button--primary" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> บันทึก PDF</button>
        </div>
    </header>

    @php
        /*
         * พนักงานต้องตอบแค่ "วันนี้ทำอะไร และมีอะไรต้องรีบ" ไม่ได้ตัดสินใจเชิงบริหาร
         * การ์ดจึงเหลือเฉพาะตัวเลขที่นำไปลงมือต่อได้ทันที
         *
         * "งานทั้งหมด" ถูกตัดออกเพราะเป็นยอดรวมที่อ่านแล้วทำอะไรต่อไม่ได้
         * และหน้างานของฉันบอกจำนวนนี้อยู่แล้ว
         *
         * "งานที่ไปร่วม" ยังคงอยู่เพราะเป็นผลงานที่เดิมไม่ปรากฏที่ไหนเลย
         * พนักงานจึงมองไม่เห็นว่าการไปช่วยงานทีมอื่นถูกบันทึกไว้แล้วจริง
         */
        $kpiCards = [
            ['label' => 'กำลังทำอยู่', 'value' => number_format($inProgressJobs), 'note' => 'งานที่เริ่มแล้วและยังไม่ปิด', 'icon' => 'bi-play-circle'],
            ['label' => 'งานที่ไปร่วม', 'value' => number_format($joinedJobs), 'note' => $joinedJobs > 0 ? 'จากทั้งหมด '.number_format($totalJobs).' งานในช่วงนี้' : 'ยังไม่มีงานที่ไปร่วมในช่วงนี้', 'icon' => 'bi-people'],
            ['label' => 'ใกล้ครบกำหนด', 'value' => number_format($dueSoonJobs), 'note' => $dueSoonJobs > 0 ? 'ครบกำหนดภายใน 7 วัน' : 'ไม่มีงานครบกำหนดใน 7 วัน', 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'alert' => $dueSoonJobs > 0],
            ['label' => 'เลยกำหนดแล้ว', 'value' => number_format($overdueJobs), 'note' => $overdueJobs > 0 ? 'ต้องรีบจัดการก่อนเป็นอันดับแรก' : 'ไม่มีงานเลยกำหนด', 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'alert' => $overdueJobs > 0],
        ];
    @endphp
    @include('reports.components.personal-filters')

    @include('reports.components.kpi-band', ['cards' => $kpiCards, 'ariaLabel' => 'สรุปตัวเลขงานของฉัน'])

    @include('reports.components.personal-charts')

    @include('reports.components.personal-attention-table')

    {{-- ตารางผลงานเต็มชุด พนักงานต้องเห็นได้เองว่าไปร่วมงานกับใครและใครมอบหมายมา
         ไม่ใช่เห็นได้เฉพาะหัวหน้าที่เปิดหน้ารายงานรายบุคคล --}}
    @include('reports.components.personal-team-table')

    @include('reports.components.subtask-modal')

    <script type="application/json" id="personalReportChartData">@json($chartData)</script>
</div>
@endsection
