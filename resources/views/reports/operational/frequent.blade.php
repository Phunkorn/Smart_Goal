@extends('layouts.app')

@section('title', 'งานที่ทำบ่อยที่สุด — รายงานปฏิบัติงาน')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{-- ranking ทั้งหมดของเดือน — Overview ตัดไว้ที่ 5 อันดับ --}}
<div class="report-page report-operational report-operational--detail" aria-labelledby="operational-report-title">
    @include('reports.components.operational.header', [
        'scope' => $scope,
        'routeName' => 'reports.operational.frequent',
        'title' => 'งานที่ทำบ่อยที่สุด',
        'detailTitle' => 'งานที่ทำบ่อยที่สุด',
        'csvReport' => 'frequent',
    ])

    <section class="report-panel operational-card" aria-labelledby="operational-frequent-title">
        <div class="operational-card__heading">
            <div>
                <h2 id="operational-frequent-title"><i class="bi bi-list-ol" aria-hidden="true"></i> อันดับงานทั้งหมด</h2>
                <p>{{ number_format($rows->count()) }} ลักษณะงานใน{{ $scope->monthLabel() }} เรียงตามจำนวนครั้ง</p>
            </div>
            <a href="{{ route('reports.operational', $scope->query()) }}" class="operational-card__link">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปภาพรวม
            </a>
        </div>

        @include('reports.components.operational.frequent-table', ['rows' => $rows])
    </section>
</div>
@endsection
