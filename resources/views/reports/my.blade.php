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

    @php
        $kpiCards = [
            ['label' => 'งานทั้งหมด', 'value' => number_format($totalJobs), 'note' => 'ในช่วง'.$filters['period_label'], 'icon' => 'bi-collection'],
            ['label' => 'กำลังทำอยู่', 'value' => number_format($inProgressJobs), 'note' => 'งานที่เริ่มแล้วและยังไม่ปิด', 'icon' => 'bi-play-circle'],
            ['label' => 'ใกล้ครบกำหนด', 'value' => number_format($dueSoonJobs), 'note' => $dueSoonJobs > 0 ? 'ครบกำหนดภายใน 7 วัน' : 'ไม่มีงานครบกำหนดใน 7 วัน', 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'alert' => $dueSoonJobs > 0],
            ['label' => 'เลยกำหนดแล้ว', 'value' => number_format($overdueJobs), 'note' => $overdueJobs > 0 ? 'ต้องรีบจัดการก่อนเป็นอันดับแรก' : 'ไม่มีงานเลยกำหนด', 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'alert' => $overdueJobs > 0],
        ];
    @endphp
    @include('reports.components.kpi-band', ['cards' => $kpiCards, 'ariaLabel' => 'สรุปตัวเลขงานของฉัน'])

    {{-- งานล่าช้าและใกล้ครบกำหนดต้องเห็นก่อนงานอื่น จึงอยู่เหนือรายการงานที่กำลังจะถึง --}}
    <section class="personal-report__panel personal-report__attention">
        <div class="personal-report__section-head"><div><h2>ต้องจัดการก่อน</h2><p>งานที่เลยกำหนดแล้วหรือใกล้ครบกำหนด</p></div><span>{{ $attentionJobs->count() }} งาน</span></div>
        <div class="personal-report__schedule">
            @forelse($attentionJobs as $job)
                @php($isOverdue = $job['reason'] === 'เกินกำหนด')
                <a href="{{ $job['url'] }}" class="personal-report__schedule-item {{ $isOverdue ? 'is-overdue' : '' }}">
                    <span class="personal-report__attention-icon" aria-hidden="true"><i class="bi {{ $isOverdue ? 'bi-exclamation-circle-fill' : 'bi-clock-fill' }}"></i></span>
                    <span class="personal-report__schedule-main"><strong>{{ $job['topic'] }}</strong><small>{{ $job['project'] }}</small></span>
                    <span class="personal-report__schedule-meta">
                        <span class="personal-report__tag personal-report__tag--{{ $isOverdue ? 'red' : 'amber' }}">{{ $job['reason'] }}</span>
                        <time datetime="{{ $job['due_at']?->toDateString() }}">{{ $job['due_at']?->locale('th')->isoFormat('D MMM YY') ?? 'ไม่มีกำหนด' }}</time>
                    </span>
                </a>
            @empty
                <div class="personal-report__empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>ไม่มีงานที่ต้องรีบจัดการ</strong><span>ไม่พบงานตามตัวกรองที่เลยกำหนดหรือใกล้ครบกำหนด</span></div>
            @endforelse
        </div>
    </section>

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
                <div class="personal-report__empty"><i class="bi bi-calendar2-check" aria-hidden="true"></i><strong>ไม่พบงานตามตัวกรอง</strong><span>งานที่มีวันครบกำหนดจะแสดงที่นี่</span></div>
            @endforelse
        </div>
    </section>

    <div class="personal-report__analytics">
        <section class="personal-report__panel personal-report__chart-card report-card-order-0" data-report-chart data-chart-kind="bar" data-chart-state="loading">
            <div class="personal-report__section-head"><div><h2>อีก 3 เดือนข้างหน้ามีงานครบกำหนดเดือนละเท่าไร</h2><p>นับจากวันครบกำหนดของงานที่ยังไม่เสร็จ</p></div></div>
            <div class="report-chart-shell">
                <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                <div class="report-chart-wrap"><canvas id="personalWorkloadChart" aria-label="กราฟภาระงาน 3 เดือนข้างหน้า" role="img">กราฟภาระงาน 3 เดือนข้างหน้า</canvas></div>
                <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีงานครบกำหนดใน 3 เดือนนี้</strong><span>ดูจำนวนรายเดือนได้จากรายการด้านล่าง</span></div>
                <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
            </div>
            <ul class="personal-report__chart-summary" aria-label="ข้อมูลภาระงานแบบข้อความ">@foreach($workloadSummary as $item)<li><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }} งาน</strong></li>@endforeach</ul>
        </section>
        <section class="personal-report__panel personal-report__chart-card report-card-order-1" data-report-chart data-chart-kind="doughnut" data-chart-state="loading">
            <div class="personal-report__section-head"><div><h2>งานของฉันเป็นงานระดับไหน</h2><p>สัดส่วนตามระดับความสำคัญ ครบทั้ง 5 ระดับ</p></div></div>
            <div class="personal-report__priority-layout">
                <div class="report-chart-shell">
                    <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                    <div class="report-chart-wrap"><canvas id="personalPriorityChart" aria-label="กราฟสัดส่วนความสำคัญของงาน" role="img">กราฟสัดส่วนความสำคัญของงาน</canvas></div>
                    <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-pie-chart" aria-hidden="true"></i><strong>ยังไม่มีงานในช่วงนี้</strong><span>ดูรายละเอียดแต่ละระดับได้จากรายการข้าง ๆ</span></div>
                    <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
                </div>
                <ul class="personal-report__legend">@foreach($prioritySummary as $item)<li><span class="personal-report__priority-dot personal-report__tone--{{ $item['tone'] }}" aria-hidden="true"></span><span>{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></li>@endforeach</ul>
            </div>
        </section>
    </div>

    <script type="application/json" id="personalReportChartData">@json($chartData)</script>
</div>
@endsection
