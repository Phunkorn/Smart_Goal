@extends('layouts.app')

@section('title', 'รายงาน')

@push('styles')
<style>
    .report-page { max-width:1440px; margin:0 auto; }
    .report-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; margin-bottom:18px; }
    .report-head h1 { margin:0; font-size:30px; font-weight:850; }
    .report-head p { margin:6px 0 0; color:var(--text-muted); }
    .export-btn { min-height:42px; border-radius:12px; border:0; background:var(--green); color:#fff; padding:0 16px; display:inline-flex; align-items:center; gap:8px; font-weight:800; text-decoration:none; }
    .metric-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
    .metric { background:#fff; border:1px solid var(--border); border-radius:14px; padding:16px; box-shadow:var(--shadow-sm); }
    .metric .label { color:var(--text-muted); font-size:12px; font-weight:800; }
    .metric .value { font-size:30px; font-weight:850; margin-top:4px; }
    .report-grid { display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:16px; align-items:start; }
    .report-card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; box-shadow:var(--shadow-sm); min-width:0; }
    .report-card h2 { font-size:17px; font-weight:850; margin:0 0 4px; }
    .desc { color:var(--text-muted); font-size:13px; margin-bottom:16px; }
    .bar-list, .dept-list, .employee-list { display:grid; gap:12px; }
    .bar-row { display:grid; grid-template-columns:130px minmax(0,1fr) 44px; gap:10px; align-items:center; }
    .bar-label { font-weight:800; font-size:13px; }
    .track { height:12px; border-radius:999px; background:var(--surface-2); overflow:hidden; }
    .fill { height:100%; border-radius:999px; width:var(--w); }
    .tone-gray { background:#94a3b8; } .tone-blue { background:#2563eb; } .tone-purple { background:#7c3aed; }
    .tone-green { background:#079455; } .tone-amber { background:#f59e0b; } .tone-red { background:#dc2626; }
    .dept-card, .employee-row { border:1px solid var(--border); border-radius:12px; padding:13px; }
    .row-title { display:flex; justify-content:space-between; gap:12px; font-weight:850; }
    .mini { color:var(--text-muted); font-size:12px; margin-top:6px; display:flex; flex-wrap:wrap; gap:10px; }
    .month-grid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; align-items:end; min-height:220px; }
    .month-col { display:grid; gap:8px; align-content:end; text-align:center; color:var(--text-muted); font-size:12px; }
    .month-bars { height:150px; display:flex; align-items:end; justify-content:center; gap:5px; }
    .month-bar { width:16px; border-radius:8px 8px 2px 2px; min-height:4px; }
    @media (max-width:1200px){ .metric-grid{grid-template-columns:repeat(2,1fr);} .report-grid{grid-template-columns:1fr;} }
    @media (max-width:760px){ .report-head{align-items:flex-start;flex-direction:column;} .metric-grid,.month-grid{grid-template-columns:1fr;} .bar-row{grid-template-columns:1fr;} }
</style>
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
                                <a href="{{ route('reports.employee', $employee['id']) }}" style="color:inherit;text-decoration:none;">{{ $employee['name'] }}</a>
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
