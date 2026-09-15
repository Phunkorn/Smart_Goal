@extends('layouts.app')

@section('title', 'สรุปรายวัน — รายงานปฏิบัติงาน')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{-- รายงานฉบับเต็มของ "สรุปรายวัน" — ทุกรายการงานของเดือนที่เลือก ครบทุกวันที่มีข้อมูล --}}
<div class="report-page report-operational report-operational--detail" aria-labelledby="operational-report-title">
    @include('reports.components.operational.header', [
        'scope' => $scope,
        'routeName' => 'reports.operational.daily',
        'title' => 'สรุปรายวัน',
        'detailTitle' => 'สรุปรายวัน',
        'csvReport' => 'daily',
    ])

    <section class="report-panel operational-card" aria-labelledby="operational-daily-title">
        <div class="operational-card__heading">
            <div>
                <h2 id="operational-daily-title"><i class="bi bi-calendar-week" aria-hidden="true"></i> รายการงานทั้งเดือน</h2>
                <p>{{ number_format($rows->count()) }} รายการ ทุกวันที่มีข้อมูลใน{{ $scope->monthLabel() }}</p>
            </div>
            <a href="{{ route('reports.operational', $scope->query()) }}" class="operational-card__link">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปภาพรวม
            </a>
        </div>

        @include('reports.components.operational.daily-items-table', [
            'rows' => $rows,
            'caption' => 'รายการงานทั้งหมดของ'.$scope->monthLabel(),
            'emptyText' => 'ยังไม่มีรายการงานในเดือนนี้',
        ])
    </section>
</div>
@endsection
