@extends('layouts.app')

@section('title', 'รายงาน')

@push('styles')
    @vite('resources/css/pages/reports.css')
@endpush

@section('content')
@php
    $maxStatus = max(1, $statusSummary->max('value'));
    $maxMonth = max(1, $monthlySummary->max(fn($m) => max($m['created'], $m['done'])));
@endphp

<div class="report-page">
    <div class="report-head">
        <div>
            <h1>รายงานภาพรวมองค์กร</h1>
            <p>ดูงานทั้งหมด งานล่าช้า อัตราสำเร็จ และภาระงานของแต่ละทีมในหน้าเดียว</p>
        </div>
        <a href="{{ route('reports.exportCsv') }}" class="export-btn"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    </div>

    <section class="metric-grid">
        <div class="metric"><div class="label">งานทั้งหมด</div><div class="value">{{ $totalJobs }}</div></div>
        <div class="metric"><div class="label">เสร็จสิ้น</div><div class="value">{{ $completedJobs }}</div></div>
        <div class="metric"><div class="label">รออนุมัติ</div><div class="value">{{ $pendingApproval }}</div></div>
        <div class="metric"><div class="label">ล่าช้า</div><div class="value">{{ $overdueJobs }}</div></div>
        <div class="metric"><div class="label">อัตราสำเร็จ</div><div class="value">{{ $completionRate }}%</div></div>
    </section>

    <div class="report-grid">
        <div class="d-grid gap-3">
            <section class="report-card">
                <h2>สถานะงาน</h2>
                <div class="desc">จำนวนงานแยกตามสถานะ</div>
                <div class="bar-list">
                    @foreach($statusSummary as $item)
                        <div class="bar-row">
                            <div class="bar-label">{{ $item['label'] }}</div>
                            <div class="track"><div class="fill tone-{{ $item['tone'] }}" style="--w:{{ round(($item['value'] / $maxStatus) * 100) }}%;"></div></div>
                            <strong>{{ $item['value'] }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="report-card">
                <h2>แนวโน้ม 6 เดือนล่าสุด</h2>
                <div class="desc">สีน้ำเงินคืองานที่สร้าง สีเขียวคืองานที่เสร็จ</div>
                <div class="month-grid">
                    @foreach($monthlySummary as $month)
                        <div class="month-col">
                            <div class="month-bars">
                                <div class="month-bar tone-blue" style="height:{{ max(4, round(($month['created'] / $maxMonth) * 150)) }}px"></div>
                                <div class="month-bar tone-green" style="height:{{ max(4, round(($month['done'] / $maxMonth) * 150)) }}px"></div>
                            </div>
                            <strong>{{ $month['label'] }}</strong>
                            <span>{{ $month['created'] }} / {{ $month['done'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        <aside class="d-grid gap-3">
            <section class="report-card">
                <h2>แผนก</h2>
                <div class="desc">คน งาน active งานเสร็จ และงานล่าช้า</div>
                <div class="dept-list">
                    @foreach($departmentSummary as $department)
                        <div class="dept-card">
                            <div class="row-title"><span>{{ $department['name'] }}</span><span>{{ $department['rate'] }}%</span></div>
                            <div class="track mt-2"><div class="fill tone-green" style="--w:{{ $department['rate'] }}%;"></div></div>
                            <div class="mini">
                                <span>{{ $department['employees'] }} คน</span>
                                <span>{{ $department['active'] }} active</span>
                                <span>{{ $department['done'] }} เสร็จ</span>
                                @if($department['overdue'] > 0)<span class="text-danger">{{ $department['overdue'] }} ล่าช้า</span>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="report-card">
                <h2>ภาระงานรายคน</h2>
                <div class="desc">เรียงจากงาน active มากที่สุด</div>
                <div class="employee-list">
                    @foreach($employeeSummary->take(10) as $employee)
                        <div class="employee-row">
                            <div class="row-title">
                                <a href="{{ route('reports.employee', $employee['id']) }}" class="employee-report-link">{{ $employee['name'] }}</a>
                                <span>{{ $employee['active'] }}</span>
                            </div>
                            <div class="mini">
                                <span>{{ $employee['department'] }}</span>
                                <span>{{ $employee['done'] }} เสร็จ</span>
                                <span>{{ $employee['total'] }} ทั้งหมด</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection

