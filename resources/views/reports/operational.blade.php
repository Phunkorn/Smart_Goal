@extends('layouts.app')

@section('title', 'ภาระงานปฏิบัติการ')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{--
    รายงานภาระงานปฏิบัติการ — งานประจำ งานแทรก และงานนอกสถานที่

    แยกจากรายงานผลงานโครงการโดยเด็ดขาด ตัวเลขที่นี่ต้องไม่ไหลไปปนกับ KPI ของ
    โครงการ เพราะจะทำให้อัตราปิดงานและความคืบหน้าของโครงการเพี้ยน

    รายงานนี้ตอบคำถามที่รายงานโครงการตอบไม่ได้: วันที่โครงการไม่ขยับ
    พนักงานเอาเวลาไปทำอะไร
--}}
<div class="report-page report-operational" aria-labelledby="operational-report-title">
    <nav class="report-breadcrumb" aria-label="breadcrumb">
        <a href="{{ route('reports.index') }}">รายงาน</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span>ภาระงานปฏิบัติการ</span>
    </nav>

    <header class="report-page__header">
        <div>
            <div class="report-page__eyebrow">Operational workload</div>
            <h1 id="operational-report-title">ภาระงานปฏิบัติการ</h1>
            <p>
                ชั่วโมงงานประจำ งานแทรก และงานนอกสถานที่ ที่ไม่ปรากฏบนบอร์ดโปรเจกต์
                — {{ $filters['period_label'] }} · {{ $filters['kind_label'] }}
            </p>
        </div>
    </header>

    @include('reports.components.filters', [
        'action' => route('reports.operational'),
        'exportRoute' => 'reports.operationalExportCsv',
        'showPriority' => false,
        'description' => 'ใช้ช่วงเวลา แผนก ประเภทงาน และหมวดงานกับข้อมูลทุกส่วนในรายงาน',
        'extraFilters' => 'reports.components.operational-filters',
    ])

    @php
        $kpiCards = [
            [
                'label' => 'ชั่วโมงงานรวม',
                'value' => $totalHoursLabel,
                'unit' => ' ชม.',
                'note' => 'เวลาที่บันทึกไว้ทั้งหมดในช่วงที่เลือก',
                'icon' => 'bi-clock-history',
            ],
            [
                'label' => 'จำนวนบันทึก',
                'value' => number_format($totalCount),
                'note' => $peopleCount > 0 ? 'จากผู้บันทึก '.number_format($peopleCount).' คน' : 'ยังไม่มีผู้บันทึกในช่วงนี้',
                'icon' => 'bi-journal-check',
            ],
            [
                'label' => 'งานแทรก',
                'value' => number_format($interruptCount),
                'note' => 'งานที่แทรกเข้ามาระหว่างวัน',
                'icon' => 'bi-lightning-charge',
                'tone' => 'warning',
                'alert' => $interruptCount > 0,
            ],
            [
                /*
                 * ตัวเลขสำคัญที่สุดของรายงานนี้ — เวลาที่ไม่ได้ผูกกับโปรเจกต์ใด
                 * คือคำตอบว่าทำไมบอร์ดโปรเจกต์ดูเหมือนไม่มีความเคลื่อนไหว
                 */
                'label' => 'เวลาที่ไม่ได้ลงโปรเจกต์',
                'value' => $unlinkedHoursLabel,
                'unit' => ' ชม.',
                'note' => $totalMinutes > 0
                    ? 'คิดเป็น '.$unlinkedShare.'% ของเวลาที่บันทึกไว้'
                    : 'ยังไม่มีข้อมูลในช่วงนี้',
                'icon' => 'bi-diagram-3',
                'tone' => 'warning',
                'alert' => $unlinkedShare >= 50,
            ],
        ];
    @endphp

    @include('reports.components.kpi-band', [
        'cards' => $kpiCards,
        'ariaLabel' => 'สรุปตัวเลขภาระงานปฏิบัติการ',
    ])

    {{-- ข้อมูลกราฟใช้สัญญาเดียวกับรายงานอื่น (JSON island id="report-chart-data")
         เพื่อให้ chart-lifecycle.js ที่มีอยู่แล้วใช้งานได้โดยไม่ต้องแก้ --}}
    <script type="application/json" id="report-chart-data">@json($chartData)</script>

    <section class="report-dashboard" aria-label="แดชบอร์ดภาระงานปฏิบัติการ">
        @include('reports.components.operational-charts')
    </section>

    @include('reports.components.operational-member-table')

    @if($topTitles->isNotEmpty())
        <section class="report-panel report-operational-top" aria-labelledby="operational-top-title">
            <div class="report-panel__heading">
                <div>
                    <h2 id="operational-top-title">งานที่ทำบ่อยที่สุด</h2>
                    <p>ใช้เป็นหลักฐานประสบการณ์ย้อนหลังได้ ไม่ใช่มีแต่รายชื่อโปรเจกต์</p>
                </div>
            </div>

            <ol class="report-operational-top__list">
                @foreach($topTitles as $item)
                    <li>
                        <span class="report-operational-top__name">{{ $item['title'] }}</span>
                        <span class="report-operational-top__count">{{ $item['count'] }} ครั้ง</span>
                        <span class="report-operational-top__hours">{{ $item['hours_label'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
@endsection
