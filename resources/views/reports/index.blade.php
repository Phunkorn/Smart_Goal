@extends('layouts.app')

@section('title', 'รายงาน')

@push('styles')
    @vite('resources/css/pages/reports.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/index.js')
@endpush

@section('content')
<div class="report-page">
    <header class="report-page__header">
        <div>
            <div class="report-page__eyebrow">Organization report</div>
            <h1>รายงานภาพรวมองค์กร</h1>
            <p>ติดตามงาน ผลลัพธ์ และจุดที่ต้องเร่งดำเนินการในช่วง {{ $filters['start_date'] }} – {{ $filters['end_date'] }}</p>
        </div>
        <div class="report-page__actions">
            @if(auth()->user()->role === 'viewer')
                <span class="report-page__readonly"><i class="bi bi-eye" aria-hidden="true"></i> ดูข้อมูลเท่านั้น</span>
            @endif
            <a href="{{ route('reports.exportCsv') }}" class="btn btn-success">
                <i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV
            </a>
        </div>
    </header>

    @include('reports.components.filters')
    @include('reports.components.kpis')
    @include('reports.components.charts')
    @include('reports.components.department-performance')
    @include('reports.components.attention-list')

    <script type="application/json" id="report-chart-data">@json($chartData)</script>
</div>
@endsection
