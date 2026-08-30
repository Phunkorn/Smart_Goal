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

    <section class="report-dashboard" aria-label="แดชบอร์ดภาพรวมองค์กร">
        @include('reports.components.charts')
        @include('reports.components.attention-list')
    </section>

    <script type="application/json" id="report-chart-data">@json($chartData)</script>
</div>
@endsection
