@extends('layouts.app')

@section('title', 'รายงาน Smart Ticket')

@push('styles')
    @vite('resources/css/pages/report-tickets.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/tickets.js')
@endpush

@section('content')
{{--
    รายงาน Smart Ticket — ข้อมูลทั้งหมดมาจาก App\Services\TicketReportService::build()
    สิทธิ์: TicketPolicy::viewReport (admin + เจ้าหน้าที่ IT) ตรวจใน TicketReportController

    กราฟและตารางใช้ "ชุด Ticket" เดียวกัน (แจ้งในช่วง + ตัวกรอง) ตัวเลขจึงไม่ขัดกัน
    ช่องค้นหาในรายการ Ticket กรองเฉพาะรายการ ไม่เปลี่ยนกราฟและตัวเลขสรุป
    ตัวเลขทุกตัว render จาก server — กราฟเป็นภาพเสริม ถ้ากราฟล้ม ตารางยังอ่านได้ครบ

    ไม่มีการ์ดตัวเลขสรุป (KPI) และไม่มีส่วนรายละเอียดเพิ่มเติม (SLA / แผนก-สาขา / รายบุคคล / วิธีคำนวณ) บนหน้านี้
    ตัวเลขเหล่านั้นยังคำนวณใน TicketReportService::build() (view data) แต่ไม่ render
    ลำดับ: หัวรายงาน + ตัวกรอง → กราฟ 2 ใบ → รายการ Ticket
    ช่วงที่ไม่มี Ticket เลย: ข้อความเดียวแทนกราฟและตารางว่าง
--}}
@php
    $chartEmpty = fn () => '<div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><strong>ยังไม่มี Ticket ในช่วงนี้</strong></div>'
        .'<div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ตัวเลขในรายการด้านล่างยังถูกต้องครบถ้วน</span></div>';
    $skeleton = '<div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>';
    $hasFilters = collect($filters)->contains(fn ($value) => $value !== '');
    // ความสูงกราฟตามปริมาณข้อมูลจริง: 1–2 Ticket ต่อวัน / 1–2 แผนก ใช้กราฟเตี้ย (sm) ข้อมูลมากขึ้นค่อยสูงขึ้น (md, lg)
    // ทั้งสองกราฟอยู่แถวเดียวกันจึงใช้ tier เดียว = tier ที่ต้องการสูงกว่า — ตัวเลขไม่ถูกแก้ แค่เลือกขนาดกรอบ
    $trendPeak = max([0, ...$chartData['trend']['values']]);
    $departmentRows = count($chartData['departments']['labels']);
    $chartTier = max(
        match (true) { $trendPeak <= 2 => 0, $trendPeak <= 6 => 1, default => 2 },
        match (true) { $departmentRows <= 2 => 0, $departmentRows <= 5 => 1, default => 2 },
    );
@endphp
<div class="ticket-report" aria-labelledby="ticket-report-title">
    @include('reports.components.tickets.filters')

    @if ($total === 0)
        <section class="ticket-report__empty" role="status" aria-labelledby="ticket-report-empty-title" data-ticket-report-empty>
            <div class="ticket-report__empty-text">
                <h2 id="ticket-report-empty-title">ไม่มี Ticket ที่แจ้งใน{{ $periodPhrase }}{{ $hasFilters ? 'ที่ตรงกับตัวกรอง' : '' }}</h2>
                <p>รายการ Ticket กราฟ และผลงานรายบุคคลจะแสดงเมื่อมี Ticket ในช่วงที่เลือก — ลองเลือกช่วงเวลาอื่น{{ $hasFilters ? ' หรือล้างตัวกรอง' : '' }}</p>
            </div>
            @if ($hasFilters)
                <a class="ticket-report__button" href="{{ route('reports.tickets', $period->query()) }}">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> ล้างตัวกรอง
                </a>
            @endif
        </section>
    @else
        <section class="ticket-report__charts ticket-report__charts--{{ ['sm', 'md', 'lg'][$chartTier] }}" aria-label="กราฟ Ticket">
            <article class="ticket-report__section" data-report-chart data-chart-kind="bar"
                data-chart-state="{{ \App\Support\ReportChartState::initial($trend['values']) }}">
                <div class="ticket-report__section-head">
                    <div>
                        <h2>แนวโน้มจำนวน Ticket</h2>
                        <p class="ticket-report__section-note">
                            รวม {{ number_format($total) }} Ticket
                            @if ($trend['peak'])
                                · สูงสุด {{ $trend['peak']['label'] }} ({{ number_format($trend['peak']['count']) }} Ticket)
                            @endif
                        </p>
                    </div>
                    <span class="ticket-report__unit">ราย{{ $trend['unit'] }}</span>
                </div>
                <div class="report-chart-shell">
                    {!! $skeleton !!}
                    <div class="report-chart-wrap"><canvas id="ticketTrendChart" role="img" aria-label="กราฟแท่งจำนวน Ticket ที่แจ้งราย{{ $trend['unit'] }}">กราฟแท่งจำนวน Ticket ที่แจ้งราย{{ $trend['unit'] }}</canvas></div>
                    {!! $chartEmpty() !!}
                </div>
            </article>

            <article class="ticket-report__section" data-report-chart data-chart-kind="doughnut"
                data-chart-state="{{ \App\Support\ReportChartState::initial($chartData['departments']['values']) }}">
                <div class="ticket-report__section-head">
                    <div>
                        <h2>Ticket ตามแผนก/หน่วยงาน</h2>
                        <p class="ticket-report__section-note">{{ $chartData['departments']['other'] ? 'แสดง '.\App\Services\TicketReportService::CHART_DEPARTMENTS.' อันดับแรก ที่เหลือรวมเป็น “อื่น ๆ”' : 'แผนกผู้แจ้ง ณ เวลาที่แจ้ง' }}</p>
                    </div>
                </div>
                <div class="ticket-report__donut">
                    <div class="report-chart-shell">
                        {!! $skeleton !!}
                        <div class="report-chart-wrap"><canvas id="ticketDepartmentChart" role="img" aria-label="กราฟโดนัทสัดส่วน Ticket ตามแผนกผู้แจ้ง">กราฟโดนัทสัดส่วน Ticket ตามแผนกผู้แจ้ง</canvas></div>
                        <div class="ticket-report__donut-total" aria-hidden="true"><strong>{{ number_format($total) }}</strong><span>Ticket</span></div>
                        {!! $chartEmpty() !!}
                    </div>
                    {{-- ชื่อ จำนวน และสัดส่วนของทุกชิ้น — สีต้องตรงกับ departmentSliceColors ใน ticket-chart-config.js --}}
                    <ul class="ticket-report__legend" aria-label="จำนวน Ticket ตามแผนกผู้แจ้ง">
                        @foreach ($chartData['departments']['labels'] as $label)
                            @php($isOther = $chartData['departments']['other'] && $loop->last)
                            @php($count = $chartData['departments']['values'][$loop->index])
                            <li class="ticket-report__legend-item ticket-report__legend-item--{{ $isOther ? 'other' : $loop->index }}" data-department-slice>
                                <span class="ticket-report__legend-swatch" aria-hidden="true"></span>
                                <span class="ticket-report__legend-name">{{ $label }}</span>
                                <strong>{{ number_format($count) }} <small>({{ rtrim(rtrim(number_format($count / max($total, 1) * 100, 1), '0'), '.') }}%)</small></strong>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </article>
        </section>

        <script type="application/json" id="ticket-report-chart-data">@json($chartData)</script>

        @include('reports.components.tickets.ticket-table')
    @endif
</div>
@endsection
