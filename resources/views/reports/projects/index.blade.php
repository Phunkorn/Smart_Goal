@extends('layouts.app')

@section('title', 'รายงานโปรเจกต์ประจำเดือน')

@push('styles')
    @vite('resources/css/pages/report-projects.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/projects.js')
@endpush

@section('content')
{{--
    รายงานโปรเจกต์ประจำเดือน — ภาพรวมและรายบุคคลในหน้าเดียว

    รายชื่อพนักงานและคนที่เลือกผ่านการตรวจสิทธิ์ใน ReportController::projects() แล้ว
    ตัวเลขและแถวทั้งหมดมาจาก ProjectReportService ชุดเดียวกับ CSV

    KPI และกราฟสรุปทั้งเดือนของคนที่เลือก ตัวกรองมีผลกับตาราง หน้ารายละเอียด และ CSV
    ตารางแสดงเพียง 10 งานแรก ที่เหลือพร้อมไฟล์แนบอยู่ในหน้า "ดูรายละเอียดทั้งหมด"
--}}
@php
    $isOwnReport = $owner !== null && (int) $owner->id === (int) auth()->id();
    $subject = $isOwnReport ? 'คุณ' : $owner?->name;
    // ตารางเดียวกับหน้า "ดูรายละเอียดทั้งหมด" ทุกคอลัมน์ — $previewRows มาจาก controller พร้อมหลักฐานที่ตรวจสิทธิ์แล้ว
    $columnCount = 14;
    $statusChart = $chartData['status'];
    $lateChart = $chartData['late'];
    $breakdown = $chartData['breakdown'];
    $chartEmpty = '<div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีงานในช่วงนี้</strong></div><div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong></div>';
@endphp
<div class="project-report" aria-labelledby="project-report-title">
    @include('reports.components.projects.header', ['routeName' => 'reports.projects', 'title' => 'รายงานโปรเจกต์ประจำเดือน'])

    <section class="project-report__kpis" aria-label="สรุปตัวเลข{{ $periodPhrase }}">
        @foreach($kpis as $kpi)
            <article class="project-report__kpi project-report__kpi--{{ $kpi['tone'] }}" data-project-kpi="{{ $kpi['key'] }}">
                <span class="project-report__kpi-icon" aria-hidden="true"><i class="bi {{ $kpi['icon'] }}"></i></span>
                <div class="project-report__kpi-body">
                    <span class="project-report__kpi-label">{{ $kpi['label'] }}</span>
                    <strong class="project-report__kpi-value">{{ number_format($kpi['value']) }} <small>{{ $kpi['unit'] }}</small></strong>
                    @if($kpi['note'] ?? null)
                        <span class="project-report__kpi-note">{{ $kpi['note'] }}</span>
                    @endif
                </div>
            </article>
        @endforeach
    </section>

    @include('reports.components.projects.filters', ['routeName' => 'reports.projects'])

    {{--
        กราฟสรุปทั้งเดือน ไม่ขึ้นกับตัวกรอง ข้อมูลจาก ProjectReportService::chartData()
        รายบุคคล 2 ใบ: แนวโน้มรายเดือน และสถานะงาน
        ภาพรวมแผนก 4 ใบ (2 × 2): เพิ่มงานล่าช้าที่ต้องติดตาม และงานที่ปิดได้รายคน
        น้ำเงินคืองานที่เสร็จ อำพันคืองานที่ได้รับ แดงใช้สื่อ "ล่าช้า" อย่างเดียว
    --}}
    <section class="project-report__charts" aria-label="กราฟรายงานโปรเจกต์ประจำเดือน">
        @if($chartProjectLabel)
            <div class="project-report__chart-scope" role="status">
                <i class="bi bi-funnel" aria-hidden="true"></i>
                กราฟแสดงเฉพาะโปรเจกต์ <strong>{{ $chartProjectLabel }}</strong>
            </div>
        @endif
        <article class="project-report__card project-report__chart-card project-report__chart-card--trend" data-report-chart data-chart-kind="bar" data-chart-state="loading">
            <div class="project-report__chart-head">
                <h2>{{ $trendTitle }}</h2>
                <p>งานที่ได้รับ (อำพัน) เทียบงานที่เสร็จ (น้ำเงิน) แต่ละเดือน {{ $isCustomPeriod ? 'นับเฉพาะวันที่อยู่ในช่วงที่เลือก' : 'แท่งสีเข้มคือเดือน'.$monthLabel }}</p>
            </div>
            <div class="report-chart-shell">
                <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                <div class="report-chart-wrap"><canvas id="projectTrendChart" aria-label="กราฟแท่งงานที่ได้รับและงานที่เสร็จรายเดือน" role="img">กราฟแท่งงานที่ได้รับและงานที่เสร็จรายเดือน</canvas></div>
                {!! $chartEmpty !!}
            </div>
        </article>

        <article class="project-report__card project-report__chart-card project-report__chart-card--status" data-report-chart data-chart-kind="horizontal-bar" data-chart-state="loading">
            <div class="project-report__chart-head">
                <h2>สถานะงาน{{ $periodPhrase }}</h2>
                <p>จำนวนงานแต่ละสถานะจากทั้งหมด {{ number_format($statusChart['total']) }} งาน</p>
            </div>
            <div class="report-chart-shell">
                <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                <div class="report-chart-wrap"><canvas id="projectStatusChart" aria-label="กราฟแท่งแนวนอนจำนวนงานแต่ละสถานะ" role="img">กราฟแท่งแนวนอนจำนวนงานแต่ละสถานะ</canvas></div>
                {!! $chartEmpty !!}
            </div>
            {{-- ตัวเลขชุดเดียวกับกราฟสำหรับโปรแกรมอ่านหน้าจอ --}}
            <table class="visually-hidden">
                <caption>จำนวนงานแต่ละสถานะ รวม {{ number_format($statusChart['total']) }} งาน</caption>
                <tbody>
                    @foreach($statusChart['keys'] as $index => $key)
                        <tr>
                            <th scope="row">{{ $statusChart['labels'][$index] }}</th>
                            <td>{{ number_format($statusChart['values'][$index]) }}</td>
                            <td>{{ $statusChart['total'] > 0 ? round($statusChart['values'][$index] / $statusChart['total'] * 100) : 0 }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </article>

        @if($isTeamView)
            <article class="project-report__card project-report__late-card" aria-labelledby="project-report-late-title">
                <div class="project-report__chart-head">
                    <h2 id="project-report-late-title">งานล่าช้าที่ต้องติดตาม</h2>
                    <p>
                        @if($lateChart['total'] > 0)
                            เลยกำหนดนานที่สุด {{ number_format(count($lateChart['items'])) }} รายการ จากงานล่าช้าทั้งหมด {{ number_format($lateChart['total']) }} งาน
                        @else
                            งานที่เลยกำหนดส่งและยังไม่ปิด
                        @endif
                    </p>
                </div>
                @if($lateChart['items'] === [])
                    <div class="report-empty">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <strong>ไม่มีงานล่าช้าใน{{ $periodPhrase }}</strong>
                    </div>
                @else
                    <ol class="project-report__late-list">
                        @foreach($lateChart['items'] as $item)
                            <li class="project-report__late-item">
                                <span class="project-report__late-rank" aria-hidden="true">{{ $loop->iteration }}</span>
                                <div class="project-report__late-body">
                                    <span class="project-report__late-topic" title="{{ $item['topic'] }}">{{ $item['topic'] }}</span>
                                    <small>{{ $item['project'] }} · {{ $item['assignee'] }}</small>
                                </div>
                                <div class="project-report__late-due">
                                    <span class="project-report__late-days">{{ $item['days'] > 0 ? 'เลย '.number_format($item['days']).' วัน' : 'ล่าช้า' }}</span>
                                    @if($item['due_label'])<small>กำหนด {{ $item['due_label'] }}</small>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </article>

            <article class="project-report__card project-report__chart-card project-report__chart-card--breakdown" data-report-chart data-chart-kind="doughnut" data-chart-state="loading">
                <div class="project-report__chart-head">
                    <h2>งานที่ปิดได้รายคน</h2>
                    <p>สัดส่วนงานที่แต่ละคนปิดได้ใน{{ $periodPhrase }} รวม {{ number_format($breakdown['total']) }} งาน</p>
                </div>
                <div class="project-report__donut">
                    <div class="report-chart-shell">
                        <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                        <div class="report-chart-wrap"><canvas id="projectBreakdownChart" aria-label="กราฟโดนัทสัดส่วนงานที่แต่ละคนปิดได้" role="img">กราฟโดนัทสัดส่วนงานที่แต่ละคนปิดได้</canvas></div>
                        <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-pie-chart" aria-hidden="true"></i><strong>ยังไม่มีงานที่ปิดได้ในช่วงนี้</strong></div>
                        <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong></div>
                    </div>
                    @if($breakdown['total'] > 0)
                        {{-- ป้ายชื่อ จำนวน และเปอร์เซ็นต์ข้างวง สีมาจาก ProjectReportService::MEMBER_COLORS ชุดเดียวกับกราฟ --}}
                        <table class="project-report__donut-legend">
                            <caption class="visually-hidden">งานที่แต่ละคนปิดได้ รวม {{ number_format($breakdown['total']) }} งาน</caption>
                            <tbody>
                                @foreach($breakdown['labels'] as $index => $label)
                                    <tr>
                                        <th scope="row"><span class="project-report__legend-dot" style="background: {{ $breakdown['colors'][$index] }}" aria-hidden="true"></span><span class="project-report__legend-name" title="{{ $label }}">{{ $label }}</span></th>
                                        <td>{{ number_format($breakdown['values'][$index]) }}</td>
                                        <td>{{ round($breakdown['values'][$index] / $breakdown['total'] * 100) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </article>
        @endif
    </section>

    <section class="project-report__card project-report__table-card" aria-labelledby="project-report-table-title">
        <div class="project-report__table-head">
            <div>
                <h2 id="project-report-table-title">รายการงานโปรเจกต์ประจำเดือน</h2>
                <p>แสดงงาน{{ $isTeamView ? 'ของ'.$scopeName : 'ที่'.$subject.'รับผิดชอบ' }}ใน{{ $periodPhrase }} จำนวน {{ number_format($taskRows->count()) }} รายการ</p>
            </div>
            @include('reports.components.projects.sort', ['routeName' => 'reports.projects'])
        </div>

        <div class="project-report__table-scroll">
            <table class="project-report__table project-report__table--details">
                <thead>
                    <tr>
                        {{-- ตารางชุดเดียวกับหน้า "ดูรายละเอียดทั้งหมด" ทุกคอลัมน์ --}}
                        @include('reports.components.projects.task-head')
                    </tr>
                </thead>
                <tbody>
                    @forelse($previewRows as $row)
                        <tr data-project-row="{{ $row['id'] }}">
                            <td class="is-index">{{ $loop->iteration }}</td>
                            @include('reports.components.projects.task-cells', ['row' => $row])
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}">
                                <div class="report-empty">
                                    <i class="bi bi-inbox" aria-hidden="true"></i>
                                    @if($hasActiveFilters)
                                        <strong>ไม่พบงานที่ตรงกับตัวกรอง</strong>
                                        <a href="{{ route('reports.projects', array_filter(['owner' => $owner?->id, 'department' => $isTeamView ? $overviewDepartmentId : null, ...$periodQuery])) }}" class="project-report__clear">ล้างตัวกรอง</a>
                                    @else
                                        <strong>ยังไม่มีงานโปรเจกต์ใน{{ $periodPhrase }}</strong>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($taskRows->isNotEmpty())
            <footer class="project-report__table-foot">
                <p role="status">แสดง {{ number_format($previewRows->count()) }} จาก {{ number_format($taskRows->count()) }} รายการ</p>
                <a href="{{ route('reports.projects.details', $query) }}" class="project-report__button project-report__button--solid">
                    <i class="bi bi-list-check" aria-hidden="true"></i> ดูรายละเอียดทั้งหมด
                </a>
            </footer>
        @endif
    </section>

    @include('reports.components.projects.evidence-templates', ['rows' => $previewRows])

    @include('reports.components.subtask-modal')
    @include('reports.components.projects.evidence-modal')

    <script type="application/json" id="project-report-chart-data">@json($chartData)</script>
</div>
@endsection
