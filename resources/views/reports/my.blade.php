@extends('layouts.app')
@section('title', 'รายงานของฉัน')
@push('styles')
    @vite('resources/css/pages/report-my.css')
@endpush
@push('scripts')
    @vite('resources/js/pages/reports/my.js')
@endpush

@section('content')
<div class="personal-report">
    <header class="personal-report__header">
        <div>
            <div class="personal-report__eyebrow"><i class="bi bi-activity" aria-hidden="true"></i> Personal workspace</div>
            <h1>ภาพรวมงานของฉัน</h1>
            <p>ติดตามงานที่ต้องลงมือทำ กำหนดส่ง และภาระงานในช่วง {{ $filters['period_label'] }}</p>
        </div>
        <div class="personal-report__actions">
            <a href="{{ route('reports.myExportCsv', ['year' => $filters['year']]) }}" class="personal-report__button"><i class="bi bi-filetype-csv" aria-hidden="true"></i> ส่งออก CSV</a>
            <button type="button" class="personal-report__button personal-report__button--primary" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> บันทึก PDF</button>
        </div>
    </header>

    <section class="personal-report__panel personal-report__upcoming">
        <div class="personal-report__section-head"><div><h2>งานที่กำลังจะถึง</h2><p>งานที่ยังไม่เสร็จ เรียงตามกำหนดส่งใกล้ที่สุด</p></div><span>{{ $upcomingJobs->count() }} งาน</span></div>
        <div class="personal-report__schedule">
            @forelse($upcomingJobs as $job)
                <a href="{{ $job['url'] }}" class="personal-report__schedule-item">
                    <span class="personal-report__priority-dot personal-report__tone--{{ $job['priority']['tone'] }}" aria-hidden="true"></span>
                    <span class="personal-report__schedule-main"><strong>{{ $job['topic'] }}</strong><small>{{ $job['project'] }}</small></span>
                    <span class="personal-report__schedule-meta"><span class="personal-report__tag personal-report__tag--{{ $job['priority']['tone'] }}">{{ $job['priority']['label'] }}</span><span class="personal-report__tag personal-report__tag--{{ $job['status']['tone'] }}">{{ $job['status']['label'] }}</span><time datetime="{{ $job['due_at']?->toDateString() }}">{{ $job['due_at']?->locale('th')->isoFormat('D MMM YY') ?? '-' }}</time></span>
                </a>
            @empty
                <div class="personal-report__empty"><i class="bi bi-calendar2-check" aria-hidden="true"></i><strong>ไม่มีงานที่กำลังจะถึง</strong><span>งานที่มีวันครบกำหนดจะแสดงที่นี่</span></div>
            @endforelse
        </div>
    </section>

    <div class="personal-report__analytics">
        <section class="personal-report__panel">
            <div class="personal-report__section-head"><div><h2>ภาระงาน 3 เดือนข้างหน้า</h2><p>จำนวนงานตามเดือนที่ครบกำหนด</p></div></div>
            <div class="personal-report__chart"><canvas id="personalWorkloadChart" aria-label="กราฟภาระงาน 3 เดือนข้างหน้า" role="img"></canvas></div>
            <ul class="personal-report__chart-summary" aria-label="ข้อมูลภาระงานแบบข้อความ">@foreach($workloadSummary as $item)<li><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }} งาน</strong></li>@endforeach</ul>
        </section>
        <section class="personal-report__panel">
            <div class="personal-report__section-head"><div><h2>สัดส่วนความสำคัญ</h2><p>ครบทั้ง 5 ระดับที่ใช้กับงาน</p></div></div>
            <div class="personal-report__priority-layout">
                <div class="personal-report__chart personal-report__chart--doughnut"><canvas id="personalPriorityChart" aria-label="กราฟสัดส่วนความสำคัญของงาน" role="img"></canvas></div>
                <ul class="personal-report__legend">@foreach($prioritySummary as $item)<li><span class="personal-report__priority-dot personal-report__tone--{{ $item['tone'] }}" aria-hidden="true"></span><span>{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></li>@endforeach</ul>
            </div>
        </section>
    </div>

    <script type="application/json" id="personalReportChartData">@json($chartData)</script>
</div>
@endsection
