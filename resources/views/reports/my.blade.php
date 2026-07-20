@extends('layouts.app')

@section('title', 'รายงานของฉัน')

@push('styles')
<style>
    .my-report-page { max-width: 1380px; margin: 0 auto; }
    .my-report-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; margin-bottom: 18px; }
    .my-report-kicker { display: inline-flex; align-items: center; gap: 8px; padding: 7px 11px; border-radius: 999px; background: var(--accent-dim); color: var(--accent-strong); font-size: 12px; font-weight: 800; margin-bottom: 10px; }
    .my-report-head h1 { margin: 0; font-size: 28px; font-weight: 850; letter-spacing: 0; }
    .my-report-head p { margin: 6px 0 0; color: var(--text-muted); }
    .year-form { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
    .year-form select, .report-action { min-height: 42px; border: 1px solid var(--border); border-radius: 12px; padding: 0 14px; font-weight: 800; background: #fff; color: var(--text-main); display:inline-flex; align-items:center; gap:8px; text-decoration:none; }
    .report-action.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
    .metric-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .metric-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-sm); min-width: 0; }
    .metric-card .label { color: var(--text-muted); font-size: 12px; font-weight: 800; }
    .metric-card .value { font-size: 30px; line-height: 1; font-weight: 850; margin-top: 8px; }
    .report-layout { display: grid; grid-template-columns: minmax(0, 1fr) 390px; gap: 16px; align-items: start; }
    .panel-report { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: var(--shadow-sm); min-width: 0; }
    .panel-report h2 { margin: 0; font-size: 17px; font-weight: 850; }
    .panel-report .desc { color: var(--text-muted); margin: 5px 0 16px; font-size: 13px; }
    .month-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 9px; align-items: end; min-height: 220px; }
    .month-col { display: grid; gap: 8px; text-align: center; color: var(--text-muted); font-size: 12px; }
    .month-bars { height: 150px; display: flex; align-items: end; justify-content: center; gap: 5px; padding-top: 10px; }
    .month-bar { width: 14px; border-radius: 8px 8px 2px 2px; min-height: 4px; }
    .monthly-list { display:grid; gap:10px; }
    .monthly-row { display:grid; grid-template-columns:74px minmax(0, 1fr) 94px; gap:12px; align-items:center; padding:10px 0; border-bottom:1px solid var(--border); }
    .monthly-row:last-child { border-bottom:0; }
    .monthly-label { font-weight:850; }
    .monthly-bars { display:grid; gap:6px; }
    .monthly-track { height:10px; border-radius:999px; background:var(--surface-2); overflow:hidden; }
    .monthly-fill { height:100%; width:var(--w); border-radius:999px; }
    .monthly-count { color:var(--text-muted); font-size:12px; font-weight:800; text-align:right; }
    .tone-purple { background: #7c3aed; } .tone-blue { background: #0ea5e9; } .tone-green { background: #16a34a; } .tone-amber { background: #f59e0b; } .tone-red { background: #ef4444; }
    .status-list { display: grid; gap: 12px; }
    .status-row { display: grid; grid-template-columns: 110px minmax(0, 1fr) 38px; gap: 10px; align-items: center; }
    .bar-track { height: 12px; border-radius: 999px; background: var(--surface-2); overflow: hidden; }
    .bar-fill { height: 100%; width: var(--w); border-radius: 999px; }
    .job-table-wrap { overflow: auto; border: 1px solid var(--border); border-radius: 14px; }
    .job-table { width: 100%; border-collapse: collapse; min-width: 820px; }
    .job-table th { background: var(--surface-2); color: var(--text-muted); font-size: 12px; text-align: left; padding: 12px 14px; white-space: nowrap; }
    .job-table td { padding: 13px 14px; border-top: 1px solid var(--border); vertical-align: middle; }
    .job-link { font-weight: 800; color: var(--text-main); text-decoration: none; }
    .job-link:hover { color: var(--accent-strong); }
    .pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 9px; font-size: 12px; font-weight: 800; white-space: nowrap; }
    .pill.gray { background: #f1f5f9; color: #475569; } .pill.amber { background: #fff7ed; color: #c2410c; } .pill.purple { background: #f3e8ff; color: #6d28d9; }
    .pill.blue { background: #e0f2fe; color: #0369a1; } .pill.green { background: #dcfce7; color: #15803d; } .pill.red { background: #fee2e2; color: #b91c1c; }
    .empty-report { padding: 36px 18px; text-align: center; color: var(--text-muted); }
    @media (max-width: 1180px) { .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .report-layout { grid-template-columns: 1fr; } .month-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
    @media (max-width: 720px) { .my-report-head { flex-direction: column; align-items: flex-start; } .metric-grid, .month-grid { grid-template-columns: 1fr; } .monthly-row { grid-template-columns:1fr; gap:6px; } .monthly-count { text-align:left; } .status-row { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
@php
    $maxMonth = max(1, $monthlySummary->max(fn ($month) => max($month['total'], $month['done'])));
    $maxStatus = max(1, $statusSummary->max('value'));
    $statusMap = [
        1 => ['label' => 'รอดำเนินการ', 'tone' => 'amber'],
        2 => ['label' => 'กำลังทำ', 'tone' => 'purple'],
        3 => ['label' => 'ตรวจสอบ', 'tone' => 'blue'],
        4 => ['label' => 'เสร็จสิ้น', 'tone' => 'green'],
        5 => ['label' => 'พักงานชั่วคราว', 'tone' => 'gray'],
    ];
@endphp

<div class="my-report-page">
    <div class="my-report-head">
        <div>
            <div class="my-report-kicker"><i class="bi bi-clipboard-data-fill"></i> รายงานส่วนตัว</div>
            <h1>สรุปงานของฉัน ปี {{ $year }}</h1>
            <p>ดูงานที่รับผิดชอบ งานที่สร้าง งานที่เป็นหัวหน้า และงานที่เข้าร่วม เฉพาะบัญชีของคุณ</p>
        </div>
        <form method="GET" action="{{ route('reports.my') }}" class="year-form">
            <select name="year" onchange="this.form.submit()">
                @foreach($availableYears as $availableYear)
                    <option value="{{ $availableYear }}" @selected($availableYear === $year)>ปี {{ $availableYear }}</option>
                @endforeach
            </select>
            <a href="{{ route('reports.myExportCsv', ['year' => $year]) }}" class="report-action"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            <button type="button" class="report-action primary" onclick="window.print()"><i class="bi bi-filetype-pdf"></i> บันทึก PDF</button>
        </form>
    </div>

    <section class="metric-grid">
        <div class="metric-card"><div class="label">งานทั้งหมดในปีนี้</div><div class="value">{{ $totalJobs }}</div></div>
        <div class="metric-card"><div class="label">รับ/เกี่ยวข้องเดือนนี้</div><div class="value">{{ $thisMonthJobs }}</div></div>
        <div class="metric-card"><div class="label">กำลังทำอยู่</div><div class="value">{{ $activeJobs }}</div></div>
        <div class="metric-card"><div class="label">สำเร็จแล้ว</div><div class="value">{{ $completedJobs }}</div></div>
        <div class="metric-card"><div class="label">ล่าช้า</div><div class="value">{{ $overdueJobs }}</div></div>
    </section>

    <div class="report-layout">
        <div class="d-grid gap-3">
            <section class="panel-report">
                <h2>งานรายเดือน</h2>
                <div class="desc">แท่งสีม่วงคืองานทั้งหมด สีเขียวคืองานที่เสร็จแล้วในเดือนนั้น</div>
                <div class="monthly-list">
                    @foreach($monthlySummary as $month)
                        <div class="monthly-row">
                            <div class="monthly-label">{{ $month['label'] }}</div>
                            <div class="monthly-bars">
                                <div class="monthly-track" title="งานทั้งหมด {{ $month['total'] }}">
                                    <div class="monthly-fill tone-purple" style="--w: {{ round(($month['total'] / $maxMonth) * 100) }}%;"></div>
                                </div>
                                <div class="monthly-track" title="สำเร็จ {{ $month['done'] }}">
                                    <div class="monthly-fill tone-green" style="--w: {{ round(($month['done'] / $maxMonth) * 100) }}%;"></div>
                                </div>
                            </div>
                            <div class="monthly-count">{{ $month['total'] }} งาน / สำเร็จ {{ $month['done'] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="panel-report">
                <h2>รายการงานย้อนหลัง</h2>
                <div class="desc">แสดงเฉพาะงานที่เกี่ยวข้องกับบัญชีของคุณในปีที่เลือก</div>
                <div class="job-table-wrap">
                    <table class="job-table">
                        <thead>
                            <tr>
                                <th>เลขงาน</th>
                                <th>ชื่องาน</th>
                                <th>แผนก</th>
                                <th>สถานะ</th>
                                <th>ความคืบหน้า</th>
                                <th>กำหนดส่ง</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($jobs as $job)
                                @php
                                    $status = $statusMap[(int) $job->job_status] ?? $statusMap[1];
                                    $isLate = $job->job_due_at && $job->job_status !== 4 && $job->job_due_at->lt(now());
                                @endphp
                                <tr>
                                    <td>IT-{{ $job->job_id }}</td>
                                    <td><a href="{{ route('tasks.show', $job->job_id) }}" class="job-link">{{ $job->job_topic }}</a></td>
                                    <td>{{ optional($job->department)->department_name ?? '-' }}</td>
                                    <td><span class="pill {{ $isLate ? 'red' : $status['tone'] }}">{{ $isLate ? 'ล่าช้า' : $status['label'] }}</span></td>
                                    <td>{{ (int) $job->job_progress }}%</td>
                                    <td>{{ optional($job->job_due_at)->format('d/m/Y') ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-report">ยังไม่มีงานในปีนี้</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="panel-report">
            <h2>สัดส่วนสถานะ</h2>
            <div class="desc">ดูจำนวนงานของคุณในแต่ละสถานะ</div>
            <div class="status-list">
                @foreach($statusSummary as $item)
                    <div class="status-row">
                        <strong>{{ $item['label'] }}</strong>
                        <div class="bar-track"><div class="bar-fill tone-{{ $item['tone'] }}" style="--w: {{ round(($item['value'] / $maxStatus) * 100) }}%;"></div></div>
                        <strong>{{ $item['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        </aside>
    </div>
</div>
@endsection
