@extends('layouts.app')

@section('title', 'รายงานปฏิบัติงาน')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{--
    รายงานปฏิบัติงาน — งานประจำและงานนอกสถานที่

    แยกจากรายงานผลงานโครงการโดยเด็ดขาด ตัวเลขที่นี่ต้องไม่ไหลไปปนกับ KPI ของ
    โครงการ เพราะจะทำให้อัตราปิดงานและความคืบหน้าของโครงการเพี้ยน

    หน้าเดียวใช้สองขอบเขต ต่างกันที่ "คำถามที่ต้องตอบ" ไม่ใช่แค่ข้อมูลที่กรองต่างกัน:

    - พนักงาน (isPersonalScope) — ตอบว่า "วันนี้ฉันตรวจงานประจำครบหรือยัง"
      จึงเหลือตารางของวันนี้ ตัวเลขของตัวเองไม่กี่ตัว และกราฟเดียว ไม่มีตัวกรอง
      แผนก ไม่มีตารางรายคน และไม่มีกราฟที่ไว้เทียบคนในทีม เพราะทั้งหมดนั้นมีค่า
      เดียวหรือไม่มีความหมายเมื่อดูของคนเดียว การแสดงไว้จึงเป็นภาระในการอ่านเปล่า ๆ

    - หัวหน้าแผนกและ admin — ตอบว่า "ทีมตรวจครบหรือยัง และเวลาหายไปไหน"
      จึงได้ทั้งตารางรายคน กราฟเทียบคน และตัวกรองแผนก

    ขอบเขตถูกบังคับที่ ReportController::forcedOwnerId() ฝั่งเซิร์ฟเวอร์ การซ่อน
    ส่วนต่าง ๆ ตรงนี้เป็นเรื่องการอ่าน ไม่ใช่การบังคับสิทธิ์
--}}
@php
    // พนักงานทั่วไปถูกล็อกขอบเขตไว้ที่ตัวเองแล้ว (owner_id ไม่ได้มาจาก query string)
    $isPersonalScope = $filters['owner_id'] !== null;
    $selectedOwner = $filterOptions['owners']->firstWhere('id', $filters['owner_id']);
    $isOwnScope = $isPersonalScope && (int) $filters['owner_id'] === (int) auth()->id();
    $scopeTitle = $isOwnScope ? 'รายงานปฏิบัติงานของฉัน' : ($isPersonalScope ? 'รายงานปฏิบัติงานรายบุคคล' : 'รายงานปฏิบัติงาน');
@endphp
<div class="report-page report-operational" aria-labelledby="operational-report-title">
    <nav class="report-breadcrumb" aria-label="breadcrumb">
        <a href="{{ route('reports.index') }}">รายงาน</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span>{{ $scopeTitle }}</span>
    </nav>

    <header class="report-page__header">
        <div>
            <div class="report-page__eyebrow">Operational workload</div>
            <h1 id="operational-report-title">
                {{ $scopeTitle }}
            </h1>
            <p>
                @if($isPersonalScope)
                    งานประจำของ {{ $isOwnScope ? 'คุณ' : ($selectedOwner?->name ?? 'ผู้ปฏิบัติงาน') }} พร้อมเวลาเริ่มจริง สถานะ และเหตุผลที่ต้องติดตาม
                @else
                    งานประจำของวันนี้ และชั่วโมงงานที่ไม่ปรากฏบนบอร์ดโปรเจกต์
                @endif
                — {{ $filters['period_label'] }} · {{ $filters['kind_label'] }}
            </p>
        </div>
    </header>

    @include('reports.components.filters', [
        'action' => route('reports.operational'),
        'exportRoute' => 'reports.operationalExportCsv',
        'showPriority' => false,
        // ตัวกรองแผนกไม่มีความหมายเมื่อขอบเขตถูกล็อกไว้ที่คนคนเดียวอยู่แล้ว
        'showDepartment' => ! $isPersonalScope,
        'description' => $isPersonalScope
            ? 'ใช้ช่วงเวลา ประเภทงาน และหมวดงานกับตัวเลขย้อนหลังด้านล่าง'
            : 'ใช้ช่วงเวลา แผนก ประเภทงาน และหมวดงานกับข้อมูลทุกส่วนในรายงาน',
        'extraFilters' => 'reports.components.operational-filters',
    ])

    @php
        /*
         * การ์ดตัวเลขของสองขอบเขต

         * พนักงานได้สามใบที่พูดถึงตัวเองล้วน ๆ ส่วนใบ "เวลาที่ไม่ได้ลงโปรเจกต์"
         * เป็นตัวเลขไว้บริหารภาระงานของทีม ไม่ใช่สิ่งที่พนักงานต้องลงมือแก้เอง
         * และการ์ด "จำนวนบันทึก" ก็ไม่ต้องบอกว่ามาจากผู้บันทึกกี่คน เพราะมีคนเดียว
         */
        $kpiCards = [
            [
                'label' => $isOwnScope ? 'ชั่วโมงงานของฉัน' : ($isPersonalScope ? 'ชั่วโมงงานรายบุคคล' : 'ชั่วโมงงานรวม'),
                'value' => $totalHoursLabel,
                'unit' => ' ชม.',
                'note' => 'เวลาที่บันทึกไว้ทั้งหมดในช่วงที่เลือก',
                'icon' => 'bi-clock-history',
            ],
            [
                'label' => 'จำนวนบันทึก',
                'value' => number_format($totalCount),
                'note' => $isPersonalScope
                    ? 'รายการที่คุณบันทึกไว้ในช่วงที่เลือก'
                    : ($peopleCount > 0 ? 'จากผู้บันทึก '.number_format($peopleCount).' คน' : 'ยังไม่มีผู้บันทึกในช่วงนี้'),
                'icon' => 'bi-journal-check',
            ],
            [
                'label' => 'งานประจำที่ทำเสร็จ',
                'value' => number_format($routineDoneCount),
                'note' => $routineCount > 0 ? 'จากงานประจำ '.number_format($routineCount).' รายการ' : 'ยังไม่มีงานประจำในช่วงนี้',
                'icon' => 'bi-check2-circle',
            ],
        ];

        if (! $isPersonalScope) {
            /*
             * ตัวเลขสำคัญที่สุดของรายงานฝั่งหัวหน้า — เวลาที่ไม่ได้ผูกกับโปรเจกต์ใด
             * คือคำตอบว่าทำไมบอร์ดโปรเจกต์ดูเหมือนไม่มีความเคลื่อนไหว
             */
            $kpiCards[] = [
                'label' => 'เวลาที่ไม่ได้ลงโปรเจกต์',
                'value' => $unlinkedHoursLabel,
                'unit' => ' ชม.',
                'note' => $totalMinutes > 0
                    ? 'คิดเป็น '.$unlinkedShare.'% ของเวลาที่บันทึกไว้'
                    : 'ยังไม่มีข้อมูลในช่วงนี้',
                'icon' => 'bi-diagram-3',
                'tone' => 'warning',
                'alert' => $unlinkedShare >= 50,
            ];
        }
    @endphp

    @include('reports.components.kpi-band', [
        'cards' => $kpiCards,
        'ariaLabel' => 'สรุปตัวเลขภาระงานปฏิบัติการ',
    ])

    @include('reports.components.routine-summary', [
        'routineSummary' => $routineSummary,
        'filters' => $filters,
    ])

    {{-- ข้อมูลกราฟใช้สัญญาเดียวกับรายงานอื่น (JSON island id="report-chart-data")
         เพื่อให้ chart-lifecycle.js ที่มีอยู่แล้วใช้งานได้โดยไม่ต้องแก้ --}}
    <script type="application/json" id="report-chart-data">@json($chartData)</script>

    <section class="report-dashboard @if($isPersonalScope) report-dashboard--personal @endif"
        aria-label="แดชบอร์ดภาระงานปฏิบัติการ">
        {{-- พนักงานได้กราฟเดียวที่อ่านของตัวเองรู้เรื่อง กราฟเทียบคนในทีมและกราฟ
             ที่ไว้อธิบายภาพรวมของแผนกเป็นของหัวหน้า --}}
        @include('reports.components.operational-charts', [
            'chartKeys' => $isPersonalScope ? ['daily'] : ['daily', 'categories', 'members'],
        ])
    </section>

    @unless($isPersonalScope)
        @include('reports.components.operational-member-table')
    @endunless

    {{--
        ตารางการตรวจงานประจำอยู่ท้ายหน้า

        เป็นรายละเอียดระดับรายการที่เปิดดูเมื่อถูกถามว่า "ที่ผ่านมาทำอะไรไปบ้าง"
        ไม่ใช่ตัวเลขสรุปที่ต้องเห็นทันทีที่เปิดหน้า ส่วนคำถามของเช้าวันนี้
        ("เหลืออะไรที่ยังไม่ตรวจ") ตอบด้วยตัวนับที่หัวตารางและแถวของวันนี้ที่อยู่บนสุด

        showChecklistOwner: พนักงานทั่วไปถูกบังคับขอบเขตให้เห็นเฉพาะของตัวเอง
        (ReportController::forcedOwnerId()) คอลัมน์ผู้รับผิดชอบจึงมีค่าเดียวทั้งตาราง
        และไม่ต้องแสดง
    --}}
    @include('reports.components.today-checklist', [
        'todayChecklist' => $todayChecklist,
        'showChecklistOwner' => $filters['owner_id'] === null,
    ])

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
