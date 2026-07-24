@extends('layouts.app')

@section('title', 'รายงานพนักงาน')

@push('styles')
    @vite('resources/css/pages/report-employee.css')
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

