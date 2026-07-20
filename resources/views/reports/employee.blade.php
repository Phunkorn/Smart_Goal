@extends('layouts.app')

@section('title', 'รายงานพนักงาน')

@push('styles')
<style>
    .emp-report { max-width: 1280px; margin: 0 auto; }
    .emp-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; margin-bottom:18px; }
    .emp-head h1 { margin:0; font-size:28px; font-weight:850; }
    .emp-head p { margin:6px 0 0; color:var(--text-muted); }
    .emp-actions { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
    .report-btn { min-height:42px; border-radius:12px; border:1px solid var(--border); background:#fff; color:var(--text-main); padding:0 14px; display:inline-flex; align-items:center; gap:8px; font-weight:800; text-decoration:none; }
    .report-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
    .metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
    .metric { background:#fff; border:1px solid var(--border); border-radius:14px; padding:16px; box-shadow:var(--shadow-sm); }
    .metric span { color:var(--text-muted); font-size:12px; font-weight:800; }
    .metric strong { display:block; font-size:30px; margin-top:4px; }
    .grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:16px; align-items:start; }
    .card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:18px; box-shadow:var(--shadow-sm); }
    .card h2 { font-size:17px; font-weight:850; margin:0 0 12px; }
    .month-grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:8px; align-items:end; min-height:190px; }
    .month-col { display:grid; gap:7px; text-align:center; font-size:12px; color:var(--text-muted); }
    .bars { height:130px; display:flex; align-items:end; justify-content:center; gap:4px; }
    .bar { width:13px; min-height:4px; border-radius:8px 8px 2px 2px; }
    .purple { background:#7c3aed; } .green { background:#16a34a; } .blue { background:#0ea5e9; } .amber { background:#f59e0b; } .red { background:#ef4444; }
    .status-row { display:grid; grid-template-columns:110px minmax(0,1fr) 36px; gap:10px; align-items:center; margin-bottom:12px; }
    .track { height:12px; border-radius:999px; background:var(--surface-2); overflow:hidden; }
    .fill { height:100%; width:var(--w); border-radius:999px; }
    .table-wrap { overflow:auto; border:1px solid var(--border); border-radius:14px; }
    table { width:100%; min-width:760px; border-collapse:collapse; }
    th { background:var(--surface-2); color:var(--text-muted); text-align:left; font-size:12px; padding:12px; }
    td { border-top:1px solid var(--border); padding:12px; }
    .pill { border-radius:999px; padding:5px 9px; font-size:12px; font-weight:800; display:inline-flex; white-space:nowrap; }
    .pill.green { background:#dcfce7; color:#15803d; } .pill.blue { background:#e0f2fe; color:#0369a1; } .pill.purple { background:#f3e8ff; color:#6d28d9; }
    .pill.amber { background:#fff7ed; color:#c2410c; } .pill.red { background:#fee2e2; color:#b91c1c; } .pill.gray { background:#f1f5f9; color:#475569; }
    @media print { .sidebar,.topbar,.emp-actions { display:none!important; } .main { margin:0!important; padding:20px!important; } .card,.metric { box-shadow:none; } }
    @media (max-width:1100px){ .metrics{grid-template-columns:repeat(2,1fr);} .grid{grid-template-columns:1fr;} .month-grid{grid-template-columns:repeat(6,1fr);} }
</style>
@endpush

@section('content')
@php
    $maxMonth = max(1, $monthlySummary->max(fn($m) => max($m['total'], $m['done'])));
    $maxStatus = max(1, $statusSummary->max('value'));
    $statusMap = [
        1 => ['label' => 'รอดำเนินการ', 'tone' => 'gray'],
        2 => ['label' => 'กำลังทำ', 'tone' => 'purple'],
        3 => ['label' => 'ตรวจสอบ', 'tone' => 'blue'],
        4 => ['label' => 'เสร็จสิ้น', 'tone' => 'green'],
        5 => ['label' => 'พักงานชั่วคราว', 'tone' => 'gray'],
    ];
@endphp

<div class="emp-report">
    <div class="emp-head">
        <div>
            <h1>รายงานงานของ {{ $employee->name }}</h1>
            <p>{{ optional($employee->department)->department_name ?? '-' }} · ปี {{ $year }}</p>
        </div>
        <div class="emp-actions">
            <form method="GET" action="{{ route('reports.employee', $employee) }}">
                <select name="year" class="report-btn" onchange="this.form.submit()">
                    @foreach($availableYears as $availableYear)
                        <option value="{{ $availableYear }}" @selected($availableYear === $year)>ปี {{ $availableYear }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('reports.employeeExportCsv', ['user' => $employee->id, 'year' => $year]) }}" class="report-btn"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            <button type="button" class="report-btn primary" onclick="window.print()"><i class="bi bi-filetype-pdf"></i> บันทึก PDF</button>
        </div>
    </div>

    <section class="metrics">
        <div class="metric"><span>งานทั้งหมด</span><strong>{{ $totalJobs }}</strong></div>
        <div class="metric"><span>กำลังทำ</span><strong>{{ $activeJobs }}</strong></div>
        <div class="metric"><span>สำเร็จ</span><strong>{{ $completedJobs }}</strong></div>
        <div class="metric"><span>ล่าช้า</span><strong>{{ $overdueJobs }}</strong></div>
    </section>

    <div class="grid">
        <div class="d-grid gap-3">
            <section class="card">
                <h2>งานรายเดือน</h2>
                <div class="month-grid">
                    @foreach($monthlySummary as $month)
                        <div class="month-col">
                            <div class="bars">
                                <div class="bar purple" style="height:{{ max(4, round(($month['total'] / $maxMonth) * 130)) }}px"></div>
                                <div class="bar green" style="height:{{ max(4, round(($month['done'] / $maxMonth) * 130)) }}px"></div>
                            </div>
                            <strong>{{ $month['label'] }}</strong>
                            <span>{{ $month['total'] }}/{{ $month['done'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card">
                <h2>รายการงาน</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>เลขงาน</th><th>งาน</th><th>สถานะ</th><th>ความคืบหน้า</th><th>กำหนดส่ง</th></tr>
                        </thead>
                        <tbody>
                            @forelse($jobs as $job)
                                @php
                                    $isLate = $job->job_due_at && $job->job_status !== 4 && $job->job_due_at->lt(now());
                                    $status = $isLate ? ['label' => 'ล่าช้า', 'tone' => 'red'] : ($statusMap[(int)$job->job_status] ?? $statusMap[1]);
                                @endphp
                                <tr>
                                    <td>IT-{{ $job->job_id }}</td>
                                    <td><a href="{{ route('tasks.show', $job->job_id) }}">{{ $job->job_topic }}</a></td>
                                    <td><span class="pill {{ $status['tone'] }}">{{ $status['label'] }}</span></td>
                                    <td>{{ (int) $job->job_progress }}%</td>
                                    <td>{{ optional($job->job_due_at)->format('d/m/Y') ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">ไม่มีงานในปีนี้</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="card">
            <h2>สรุปสถานะ</h2>
            @foreach($statusSummary as $item)
                <div class="status-row">
                    <strong>{{ $item['label'] }}</strong>
                    <div class="track"><div class="fill {{ $item['tone'] }}" style="--w:{{ round(($item['value'] / $maxStatus) * 100) }}%"></div></div>
                    <strong>{{ $item['value'] }}</strong>
                </div>
            @endforeach
        </aside>
    </div>
</div>
@endsection
