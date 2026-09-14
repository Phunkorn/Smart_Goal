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
    รายงานปฏิบัติงาน — ตอบคำถามเดียวว่า "คนนี้ทำงานประจำครบไหม"

    แยกจากรายงานผลงานโครงการโดยเด็ดขาด ตัวเลขที่นี่ต้องไม่ไหลไปปนกับ KPI ของ
    โครงการ เพราะจะทำให้อัตราปิดงานและความคืบหน้าของโครงการเพี้ยน

    หน้านี้ทำงานเป็นสองจังหวะ ไม่ใช่หน้าเดียวที่ยัดทุกอย่างมาพร้อมกัน:

      1. ยังไม่เลือกคน → รายชื่อลูกทีมพร้อมอัตราการทำงานประจำ
      2. เลือกคนแล้ว   → ตารางรายวันของคนนั้น และงานที่เขาทำบ่อย

    ของเดิมโยน KPI, กราฟสามตัว, ตารางชั่วโมงรายคน, เช็กลิสต์วันนี้ และฟิลเตอร์หกมิติ
    มาให้พร้อมกันตั้งแต่เปิดหน้า ผู้ใช้จึงต้องกวาดสายตาผ่านตัวเลขที่ไม่ได้ถามก่อนทุกครั้ง
    ตอนนี้เหลือตัวกรองช่วงวันอย่างเดียว เพราะคนถูกเลือกจากตารางอยู่แล้ว
    และหัวหน้าเห็นเฉพาะแผนกตัวเองอยู่แล้วจาก ReportController::forcedDepartmentId()

    ขอบเขตถูกบังคับที่ ReportController ฝั่งเซิร์ฟเวอร์ การซ่อนส่วนต่าง ๆ ตรงนี้
    เป็นเรื่องการอ่าน ไม่ใช่การบังคับสิทธิ์
--}}
@php
    /*
     * owner_id ถูกตั้งได้สองทาง และทั้งสองทางหมายถึง "กำลังดูคนคนเดียว" เหมือนกัน
     *   - พนักงานทั่วไป: ถูกล็อกไว้ที่ตัวเองโดย ReportController::forcedOwnerId()
     *   - หัวหน้าแผนก: กดเลือกจากตารางรายชื่อ (?owner=)
     */
    $viewingPerson = $filters['owner_id'] !== null;
    $isOwnScope = $viewingPerson && (int) $filters['owner_id'] === (int) auth()->id();
    $ownerName = $isOwnScope ? 'ของคุณ' : ($selectedOwner?->name ?? 'ผู้ปฏิบัติงาน');
    $scopeTitle = $isOwnScope ? 'รายงานปฏิบัติงานของฉัน' : 'รายงานปฏิบัติงาน';
@endphp
<div class="report-page report-operational" aria-labelledby="operational-report-title">
    <nav class="report-breadcrumb" aria-label="breadcrumb">
        <a href="{{ route('reports.index') }}">รายงาน</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        @if($viewingPerson && ! $isOwnScope)
            {{-- กลับไปหน้ารายชื่อได้โดยไม่เสียช่วงวันที่เพิ่งเลือก --}}
            <a href="{{ route('reports.operational', request()->except(['owner', 'page'])) }}">{{ $scopeTitle }}</a>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span>{{ $ownerName }}</span>
        @else
            <span>{{ $scopeTitle }}</span>
        @endif
    </nav>

    <header class="report-page__header">
        <div>
            <div class="report-page__eyebrow">Operational workload</div>
            <h1 id="operational-report-title">{{ $scopeTitle }}</h1>
            <p>
                @if($viewingPerson)
                    งานประจำ{{ $isOwnScope ? 'ของคุณ' : 'ของ '.$ownerName }} รายวัน — {{ $filters['period_label'] }}
                @else
                    เลือกพนักงานเพื่อดูว่าทำงานประจำครบตามที่กำหนดไว้หรือไม่ — {{ $filters['period_label'] }}
                @endif
            </p>
        </div>
    </header>

    {{--
        เหลือตัวกรองช่วงวันอย่างเดียว

        ฟิลเตอร์แผนก/หมวด/ผู้บันทึก/งานประจำ/โปรเจกต์/โฟกัส ถูกตัดออก เพราะคำถาม
        ของหน้านี้เหลือข้อเดียว และคนถูกเลือกจากตารางแทนดรอปดาวน์อยู่แล้ว
    --}}
    @include('reports.components.filters', [
        'action' => route('reports.operational'),
        'exportRoute' => 'reports.operationalExportCsv',
        'showPriority' => false,
        'showDepartment' => false,
        'description' => 'เลือกช่วงเวลาที่ต้องการตรวจสอบ',
    ])

    {{-- ข้อมูลกราฟใช้สัญญาเดียวกับรายงานอื่น (JSON island id="report-chart-data") --}}
    <script type="application/json" id="report-chart-data">@json($chartData)</script>

    {{--
        กราฟใบเดียว และเป็นภาพรวมของกระดานที่อยู่ใต้มันโดยตรง

        กราฟอ่านข้อมูลชุดเดียวกับการ์ดรายคน เรียงลำดับเดียวกัน นับหน่วยเดียวกัน
        และใช้สีชุดเดียวกัน กราฟจึงเป็น "ภาพย่อของกระดาน" ที่กวาดตาทีเดียวรู้ว่า
        ใครภาระหนักและใครยังไม่ขยับ แล้วค่อยลงไปอ่านรายละเอียดในการ์ดใบนั้น

        กราฟอัตราสะสมของเดิมถูกตัดออก เพราะตอบคนละคำถามกับกระดาน และอยู่คนละหน่วย
        จนอ่านเทียบกันไม่ได้
    --}}
    <section class="report-dashboard" aria-label="กราฟงานของวันนี้">
        @include('reports.components.operational-charts', [
            'chartKeys' => ['todayMembers'],
        ])
    </section>

    @if($viewingPerson)
        @include('reports.components.operational-routine-days', [
            'routineDays' => $routineDays,
            'ownerName' => $ownerName,
            'filters' => $filters,
        ])

        {{--
            รายการงานประจำทีละรายการ — ตารางรายวันบอกแค่ตัวเลข ตารางนี้บอกว่า
            "งานประจำของเขาคืออะไรบ้าง และแต่ละรายการทำหรือยัง"

            คอลัมน์ผู้รับผิดชอบถูกซ่อน เพราะทั้งตารางเป็นของคนเดียวอยู่แล้ว
        --}}
        @include('reports.components.today-checklist', [
            'todayChecklist' => $todayChecklist,
            'showChecklistOwner' => false,
        ])

    @if($topTitles->isNotEmpty())
        <section class="report-panel report-operational-top" aria-labelledby="operational-top-title">
            <div class="report-panel__heading">
                <div>
                    <h2 id="operational-top-title">งานที่ทำบ่อยที่สุด</h2>
                    <p>ใช้เป็นหลักฐานประสบการณ์ย้อนหลังได้ ไม่ใช่มีแต่รายชื่อโปรเจกต์</p>
                </div>
            </div>

            <div class="report-department-scroll report-operational-top__scroll">
                <table class="report-department-table report-operational-top__table">
                    <caption class="visually-hidden">งานที่ทำบ่อยที่สุด เรียงตามจำนวนครั้ง พร้อมเวลาที่ใช้ตรวจ</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="report-operational-top__rank-head">อันดับ</th>
                            <th scope="col">ลักษณะงาน</th>
                            <th scope="col" class="report-operational-table__number">จำนวนครั้ง</th>
                            <th scope="col" class="report-operational-table__number">เวลาที่ใช้ตรวจ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topTitles as $index => $item)
                            <tr>
                                <td class="report-operational-top__rank">{{ $index + 1 }}</td>
                                <th scope="row" class="report-operational-top__name">{{ $item['title'] }}</th>
                                <td class="report-operational-table__number report-operational-top__count">{{ number_format($item['count']) }} ครั้ง</td>
                                <td class="report-operational-table__number report-operational-top__hours">
                                    {{ number_format($item['minutes']) }} นาที
                                    @if($item['minutes'] >= 60)
                                        <small>{{ $item['hours_label'] }}</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    @else
        @include('reports.components.operational-people', [
            'routineCompliance' => $routineCompliance,
            'filters' => $filters,
        ])
    @endif
</div>
@endsection
