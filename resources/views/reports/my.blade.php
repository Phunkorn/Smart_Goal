@extends('layouts.app')

@section('title', 'รายงานของฉัน')

@push('styles')
    @vite('resources/css/pages/report-my.css')
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

