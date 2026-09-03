@extends('layouts.app')

@section('title', 'รายงานภาพรวมองค์กร')

@push('styles')
    @vite('resources/css/pages/report-organization.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/index.js')
@endpush

@section('content')
<div class="report-page" aria-labelledby="organization-report-title">
    <nav class="report-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('reports.index') }}">รายงาน</a><i class="bi bi-chevron-right" aria-hidden="true"></i><span>ภาพรวมองค์กร</span></nav>
    <header class="report-page__header">
        <div>
            <div class="report-page__eyebrow">Organization analytics</div>
            <h1 id="organization-report-title">รายงานภาพรวมองค์กร</h1>
            <p>วิเคราะห์งานที่สร้างและงานที่เสร็จในช่วง {{ $filters['start_date'] }} – {{ $filters['end_date'] }}</p>
        </div>
        @if(auth()->user()->role === 'viewer')
            <span class="report-page__readonly"><i class="bi bi-eye" aria-hidden="true"></i> ดูข้อมูลเท่านั้น</span>
        @endif
    </header>

    @include('reports.components.filters')

    @php
        // ตัวเลขทั้งหมดมาจาก AdminReportService ที่คำนวณไว้อยู่แล้ว ไม่มีคิวรีเพิ่ม
        //
        // การ์ดทุกใบกดได้และกรองทั้งหน้าตามสถานะนั้น ตัวเลขจึงเชื่อมกับกราฟด้านล่าง
        // ก่อนหน้านี้เป็นเลขลอย ๆ ที่ไม่มีกราฟไหนอ้างถึง ผู้ใช้เลยรู้สึกว่าคนละทิศคนละทาง
        //
        // การ์ด "ต้องติดตาม" ถูกถอดออกเพราะนับซ้อนกับ "ล่าช้า" (ล่าช้า + ใกล้ครบกำหนด)
        // ผลรวมของการ์ดจึงไม่ตรงกับงานทั้งหมด และผู้อ่านต้องเดาว่าซ้อนกันแค่ไหน
        // งานกลุ่มนี้ยังอยู่ครบในบล็อก "งานที่ต้องติดตาม" ด้านล่าง ซึ่งกดเปิดงานได้จริง
        $scopedQuery = fn (?string $status) => array_filter(array_merge(request()->query(), ['status' => $status]), fn ($value) => $value !== null && $value !== '');
        $currentStatus = $filters['status'] ?? null;

        $kpiCards = [
            ['label' => 'งานทั้งหมด', 'value' => number_format($totalJobs), 'note' => 'ในช่วงที่เลือก', 'icon' => 'bi-collection',
                'href' => route('reports.organization', $scopedQuery(null)), 'current' => $currentStatus === null],
            ['label' => 'ปิดงานได้', 'value' => number_format($completedJobs), 'note' => 'คิดเป็น '.$completionRate.'% ของงานทั้งหมด', 'icon' => 'bi-check2-circle', 'tone' => 'good', 'alert' => $completedJobs > 0,
                'href' => route('reports.organization', $scopedQuery('done')), 'current' => $currentStatus === 'done'],
            ['label' => 'ยังทำอยู่', 'value' => number_format($activeJobs), 'note' => 'งานที่ยังไม่ปิด', 'icon' => 'bi-hourglass-split',
                'href' => route('reports.organization', $scopedQuery('active')), 'current' => $currentStatus === 'active'],
            ['label' => 'ล่าช้า', 'value' => number_format($overdueJobs), 'note' => $overdueJobs > 0 ? 'เลยกำหนดส่งแล้ว ต้องตามด่วน' : 'ไม่มีงานเลยกำหนด', 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'alert' => $overdueJobs > 0,
                'href' => route('reports.organization', $scopedQuery('late')), 'current' => $currentStatus === 'late'],
        ];
    @endphp
    @include('reports.components.kpi-band', ['cards' => $kpiCards, 'ariaLabel' => 'สรุปตัวเลขภาพรวมองค์กร'])

    <section class="report-dashboard" aria-label="แดชบอร์ดภาพรวมองค์กร">
        @include('reports.components.charts')
        @include('reports.components.department-table')
        @include('reports.components.attention-list')
    </section>

    <script type="application/json" id="report-chart-data">@json($chartData)</script>
</div>
@endsection
