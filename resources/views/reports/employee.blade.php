@extends('layouts.app')

@section('title', 'รายงานรายบุคคล')

@push('styles')
    @vite('resources/css/pages/report-employee.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/employee.js')
@endpush

@section('content')
<div class="employee-report" aria-labelledby="employee-report-title">
    <nav class="report-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('reports.index') }}">รายงาน</a><i class="bi bi-chevron-right" aria-hidden="true"></i><a href="{{ route('reports.employees.index') }}">รายงานรายบุคคล</a><i class="bi bi-chevron-right" aria-hidden="true"></i><span>{{ $employee->name }}</span></nav>
    <header class="employee-report__header">
        <div class="employee-report__profile">
            <div class="employee-report__avatar">@if($employee->profile_image)<img src="{{ route('media.profile', $employee) }}" alt="รูปโปรไฟล์ของ {{ $employee->name }}">@else<span aria-hidden="true">{{ \App\Support\WorkBoardDesign::initials($employee->name) }}</span>@endif</div>
            <div><span class="employee-report__eyebrow">Individual report</span><h1 id="employee-report-title">{{ $employee->name }}</h1><p><i class="bi bi-building" aria-hidden="true"></i>{{ $employee->department?->department_name ?? 'ไม่ระบุแผนก' }}</p></div>
        </div>
        <div class="employee-report__actions">
            <form method="GET" action="{{ route('reports.employee', $employee) }}" class="employee-report__period">
                <label for="employeeReportPeriod">ช่วงเวลา</label><select id="employeeReportPeriod" name="period" data-report-period>@foreach($filterOptions['periods'] as $value => $label)<option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>@endforeach</select>
                <div data-report-custom-dates @if($filters['period'] !== 'custom') hidden @endif><input type="date" name="start_date" value="{{ $filters['start_date'] }}" aria-label="ตั้งแต่วันที่"><input type="date" name="end_date" value="{{ $filters['end_date'] }}" aria-label="ถึงวันที่"></div>
                <button class="btn btn-primary" type="submit">แสดงผล</button>
            </form>
            <a href="{{ route('reports.employees.index') }}" class="btn btn-outline-secondary"><i class="bi bi-people" aria-hidden="true"></i> เปลี่ยนพนักงาน</a>
            <a href="{{ route('reports.employeeExportCsv', ['user' => $employee->id, ...request()->query()]) }}" class="btn btn-outline-success"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
        </div>
    </header>

    @php
        $kpiCards = [
            ['label' => 'งานทั้งหมด', 'value' => number_format($totalJobs), 'note' => 'ในช่วงที่เลือก', 'icon' => 'bi-collection'],
            ['label' => 'ปิดงานได้', 'value' => number_format($completedJobs), 'note' => 'เสร็จจริงในช่วงนี้', 'icon' => 'bi-check2-circle', 'tone' => 'good', 'alert' => $completedJobs > 0],
            ['label' => 'ส่งตรงเวลา', 'value' => $onTimeRate, 'unit' => '%', 'note' => $onTimeEligible > 0 ? 'จาก '.number_format($onTimeEligible).' งานที่มีกำหนดส่ง' : 'ยังไม่มีงานที่มีกำหนดส่ง', 'icon' => 'bi-stopwatch'],
            ['label' => 'ล่าช้า', 'value' => number_format($overdueJobs), 'note' => $overdueJobs > 0 ? 'เลยกำหนดส่งแล้ว' : 'ไม่มีงานเลยกำหนด', 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'alert' => $overdueJobs > 0],
        ];
    @endphp
    @include('reports.components.kpi-band', ['cards' => $kpiCards, 'ariaLabel' => 'สรุปตัวเลขของ '.$employee->name])

    @php
        $charts = [
            ['id' => 'employeeTrendChart', 'kind' => 'line', 'class' => 'employee-chart-card--trend', 'title' => 'งานเข้าเทียบกับงานที่ปิดได้', 'description' => 'ถ้างานเข้าสูงกว่างานที่เสร็จต่อเนื่อง แปลว่างานค้างกำลังสะสม', 'label' => 'กราฟเส้นเปรียบเทียบงานที่สร้างกับงานที่เสร็จ'],
            ['id' => 'employeeStatusChart', 'kind' => 'doughnut', 'class' => 'employee-chart-card--status', 'title' => 'ตอนนี้งานค้างอยู่ที่ขั้นไหน', 'description' => 'สัดส่วนสถานะปัจจุบันของงานในรายงาน', 'label' => 'กราฟวงกลมสัดส่วนสถานะงาน'],
            ['id' => 'employeeCompletedChart', 'kind' => 'bar', 'class' => 'employee-chart-card--completed', 'title' => 'ปิดงานได้เดือนละเท่าไร', 'description' => 'นับจากวันที่งานเสร็จจริง ไม่ใช่วันครบกำหนด', 'label' => 'กราฟแท่งจำนวนงานที่เสร็จในแต่ละเดือน'],
            ['id' => 'employeePriorityChart', 'kind' => 'doughnut', 'class' => 'employee-chart-card--priority', 'title' => 'งานที่รับผิดชอบเป็นงานระดับไหน', 'description' => 'สัดส่วนตามระดับความสำคัญของงาน', 'label' => 'กราฟวงกลมสัดส่วนความสำคัญของงาน'],
        ];
    @endphp
    <section class="employee-report__dashboard" aria-label="กราฟรายงานรายบุคคล">
        {{-- ค่าหลักคือตัวเลขเดียว จึงแสดงเป็นตัวเลขใหญ่กับแถบสัดส่วน อ่านเร็วกว่าโดนัท --}}
        <article class="employee-report__panel employee-report__ontime">
            <div class="employee-report__panel-head"><div><h2>ส่งงานตรงเวลาแค่ไหน</h2><p>นับเฉพาะงานที่เสร็จแล้วและมีกำหนดส่ง</p></div></div>
            @if($onTimeEligible === 0)
                <div class="report-empty"><i class="bi bi-calendar-x" aria-hidden="true"></i><strong>ยังไม่มีงานที่มีกำหนดส่ง</strong><span>ตัวเลขนี้จะแสดงเมื่อมีงานที่ระบุวันครบกำหนด</span></div>
            @else
                @php($onTimeCount = (int) round($onTimeEligible * $onTimeRate / 100))
                @php($lateCount = max(0, $onTimeEligible - $onTimeCount))
                <div class="employee-report__ontime-figure">
                    <strong>{{ $onTimeRate }}<small>%</small></strong>
                    <span class="report-meter" style="--report-meter-tone:#1d4ed8" role="img" aria-label="ส่งตรงเวลา {{ $onTimeRate }} เปอร์เซ็นต์"><span style="width:{{ $onTimeRate }}%"></span></span>
                    <ul>
                        <li><i class="bi bi-check2-circle" aria-hidden="true"></i>ตรงเวลา <strong>{{ number_format($onTimeCount) }}</strong> งาน</li>
                        <li class="{{ $lateCount > 0 ? 'is-late' : '' }}"><i class="bi bi-clock-history" aria-hidden="true"></i>เกินกำหนด <strong>{{ number_format($lateCount) }}</strong> งาน</li>
                    </ul>
                </div>
            @endif
        </article>

        @foreach($charts as $chart)
            <article class="employee-report__panel employee-chart-card report-card-order-{{ $loop->index }} {{ $chart['class'] }}" data-report-chart data-chart-kind="{{ $chart['kind'] }}" data-chart-state="loading">
                <div class="employee-report__panel-head"><div><h2>{{ $chart['title'] }}</h2><p>{{ $chart['description'] }}</p></div><span>{{ $filters['period_label'] }}</span></div>
                <div class="report-chart-shell"><div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div><div class="report-chart-wrap"><canvas id="{{ $chart['id'] }}" aria-label="{{ $chart['label'] }}" role="img">{{ $chart['label'] }}</canvas></div><div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีข้อมูลในช่วงเวลานี้</strong></div><div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong></div></div>
            </article>
        @endforeach

        <article class="employee-report__panel employee-report__attention">
            <div class="employee-report__panel-head"><div><h2>งานที่ต้องติดตาม</h2><p>งานล่าช้าหรือใกล้ครบกำหนด</p></div></div>
            <div class="employee-report__attention-list">@forelse($attentionJobs as $job)<a href="{{ $job['url'] }}" class="employee-report__attention-item"><i class="bi {{ $job['is_overdue'] ? 'bi-exclamation-circle-fill' : 'bi-clock-fill' }}" aria-hidden="true"></i><span><strong>{{ $job['topic'] }}</strong><small>{{ $job['project'] }} · {{ $job['due_at']?->locale('th')->translatedFormat('j M Y') }}</small></span><em>{{ $job['is_overdue'] ? 'ล่าช้า' : 'ใกล้ครบกำหนด' }}</em></a>@empty<div class="report-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>ไม่มีงานที่ต้องติดตาม</strong></div>@endforelse</div>
        </article>
    </section>

    <section class="employee-report__panel employee-report__tasks" aria-labelledby="employee-task-table-title">
        <div class="employee-report__panel-head"><div><h2 id="employee-task-table-title">รายละเอียดงาน</h2><p>ตรวจสอบที่มาของตัวเลขในรายงาน</p></div><span>{{ $taskRows->count() }} งาน</span></div>
        <div class="employee-report__table-wrap"><table><thead><tr><th>ชื่องาน</th><th>โปรเจกต์</th><th>สถานะ</th><th>ความสำคัญ</th><th>เริ่ม</th><th>กำหนดส่ง</th><th>เสร็จ</th></tr></thead><tbody>@forelse($taskRows as $job)<tr><th><a href="{{ $job['url'] }}">{{ $job['topic'] }}</a></th><td>{{ $job['project'] }}</td><td><span class="report-tag report-tone-{{ $job['status']['tone'] }}">{{ $job['status']['label'] }}</span></td><td><span class="report-tag report-tone-{{ $job['priority']['tone'] }}">{{ $job['priority']['label'] }}</span></td><td>{{ $job['start_at']?->locale('th')->translatedFormat('j M Y') ?? '-' }}</td><td>{{ $job['due_at']?->locale('th')->translatedFormat('j M Y') ?? '-' }}</td><td>{{ $job['completed_at']?->locale('th')->translatedFormat('j M Y') ?? '-' }}</td></tr>@empty<tr><td colspan="7"><div class="report-empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>ยังไม่มีข้อมูลในช่วงเวลานี้</strong></div></td></tr>@endforelse</tbody></table></div>
    </section>

    <script type="application/json" id="employee-report-chart-data">@json($chartData)</script>
</div>
@endsection
