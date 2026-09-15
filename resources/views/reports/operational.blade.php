@extends('layouts.app')

@section('title', 'รายงานปฏิบัติงานประจำเดือน')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{--
    รายงานปฏิบัติงานประจำเดือน — Overview รายบุคคล

    ทุกส่วนของหน้า (KPI กราฟ ตาราง ลิงก์ detail และ CSV) อ้างอิงเดือนและคนเดียวกันจาก
    $scope ที่ ReportController::operationalScope() ตรวจสิทธิ์แล้ว เปลี่ยนเดือนหรือพนักงาน
    แล้วทั้งหน้าจึงเปลี่ยนตามพร้อมกัน

    ภาพรวมทีมอยู่ที่ reports/operational/team.blade.php — หัวหน้าแผนกและ admin เห็นหน้านั้นก่อน
    แล้วเลือกพนักงานจากตัวกรองด้านบนเพื่อมาที่หน้านี้
--}}
<div class="report-page report-operational" aria-labelledby="operational-report-title">
    @include('reports.components.operational.header', [
        'scope' => $scope,
        'routeName' => 'reports.operational',
        'title' => 'รายงานปฏิบัติงานประจำเดือน',
        'csvMenu' => true,
        'teamOption' => true,
    ])

    {{-- ข้อมูลกราฟใช้สัญญาเดียวกับรายงานอื่น (JSON island id="report-chart-data") --}}
    <script type="application/json" id="report-chart-data">@json($chartData)</script>

    @include('reports.components.operational.kpis', ['kpis' => $kpis])

    @include('reports.components.operational.charts', [
        'scope' => $scope,
        'workTypes' => $workTypes,
        'totalHoursText' => $totalHoursText,
        'dailyDescription' => 'งานประจำและงานนอกสถานที่ของทุกวันใน'.$scope->monthLabel(),
    ])

    <div class="operational-grid operational-grid--tops">
        <section class="report-panel operational-card" aria-labelledby="operational-frequent-title">
            <div class="operational-card__heading">
                <div>
                    <h2 id="operational-frequent-title"><i class="bi bi-list-ol" aria-hidden="true"></i> งานที่ทำบ่อยที่สุด</h2>
                    <p>5 อันดับแรกจาก {{ number_format($frequentTotal) }} ลักษณะงาน</p>
                </div>
                <a href="{{ route('reports.operational.frequent', $scope->query()) }}" class="operational-card__link">
                    ดูทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
            @include('reports.components.operational.frequent-table', ['rows' => $frequentWork])
        </section>

        <section class="report-panel operational-card" aria-labelledby="operational-delay-title">
            <div class="operational-card__heading">
                <div>
                    <h2 id="operational-delay-title"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> เหตุผลที่ล่าช้าหรือเกินกำหนด</h2>
                    <p>5 สาเหตุแรกจาก {{ number_format($delayGroupTotal) }} สาเหตุ</p>
                </div>
                <a href="{{ route('reports.operational.delays', $scope->query()) }}" class="operational-card__link">
                    ดูทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
            @include('reports.components.operational.delay-reasons-table', ['groups' => $delayGroups])
        </section>
    </div>

    <section class="report-panel operational-card" aria-labelledby="operational-week-title">
        <div class="operational-card__heading">
            <div>
                <h2 id="operational-week-title"><i class="bi bi-calendar-week" aria-hidden="true"></i> สรุปรายวัน</h2>
                <p>
                    @if($week)
                        สัปดาห์ {{ $week['label'] }} (จันทร์–เสาร์)
                    @else
                        ยังไม่มีรายการงานใน{{ $scope->monthLabel() }}
                    @endif
                </p>
            </div>
            <a href="{{ route('reports.operational.daily', $scope->query()) }}" class="operational-card__link">
                ดูรายงานฉบับเต็ม <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        @include('reports.components.operational.daily-items-table', [
            'rows' => $weekRows,
            'caption' => 'รายการงานของสัปดาห์ที่แสดง',
            'emptyText' => $week ? 'ยังไม่มีรายการงานในสัปดาห์นี้' : 'ยังไม่มีรายการงานในเดือนนี้',
        ])
    </section>
</div>
@endsection
