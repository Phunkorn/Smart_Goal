@extends('layouts.app')

@section('title', 'บันทึกงานประจำวัน')

@push('styles')
    @vite('resources/css/pages/daily-logs.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/daily-logs/index.js')
@endpush

@section('content')
{{--
    ไทม์ไลน์งานปฏิบัติการรายวัน — งานประจำและงานนอกสถานที่
    ซึ่งไม่มีที่อยู่ในบอร์ดโปรเจกต์ แต่กินเวลาทำงานจริงไปเป็นชั่วโมง

    หน้าเดียวใช้ทั้งพนักงาน หัวหน้าแผนก และ admin ความต่างของสิทธิ์มาจาก
    $capabilities ที่คำนวณด้วย policy ฝั่ง server เท่านั้น
--}}
<div class="daily-log" data-daily-log data-date="{{ $dateValue }}" data-owner="{{ $owner->id }}"
    data-routine-fingerprint="{{ $routineFingerprint }}" data-read-only="{{ ($capabilities['isReadOnly'] ?? true) ? '1' : '0' }}">
    {{-- ป้ายชื่อและค่าคงที่ทั้งหมดมาจาก WorkLogDesign ที่เดียว ฝั่ง JavaScript
         อ่านจาก island นี้แทนการเขียนข้อความไทยซ้ำในไฟล์ .js --}}
    <script type="application/json" id="work-log-design">@json($design)</script>

    {{-- ข้อมูลของแต่ละแถวในรูปแบบเดียวกับที่ payload ของ AJAX ส่งกลับ ใช้เติมค่า
         เดิมลงฟอร์มตอนกดแก้ไข โดยไม่ต้องยิง request เพิ่มอีกหนึ่งครั้ง --}}
    <script type="application/json" id="work-log-day">@json($presentedLogs)</script>

    {{-- URL ที่ JavaScript ต้องใช้ สร้างจากฝั่งเซิร์ฟเวอร์เพื่อไม่ให้ client
         ประกอบเส้นทางเอง ซึ่งจะพังเงียบ ๆ เมื่อ route เปลี่ยน --}}
    <script type="application/json" id="work-log-routes">@json($routes)</script>

    @include('daily-logs.components.day-header')

    <div class="daily-log__body">
        <div class="daily-log__main">
            @if($capabilities['canCreate'])
                @include('daily-logs.components.launcher')
            @endif

            @include('daily-logs.components.timeline')

            @if($pendingRoutines->isNotEmpty())
                @include('daily-logs.components.pending-routines')
            @endif
        </div>

        <aside class="daily-log__side">
            @include('daily-logs.components.summary')
        </aside>
    </div>

    {{-- กล่องเดียวของหน้านี้ — ทั้งบันทึกงานครั้งเดียวและตั้งงานประจำ
         งานประจำไม่มีหน้าแยกและไม่มีกล่องของตัวเองอีกต่อไป --}}
    @if($capabilities['canCreate'] || $capabilities['canEdit'])
        @include('daily-logs.components.entry-modal')
    @endif
</div>
@endsection
