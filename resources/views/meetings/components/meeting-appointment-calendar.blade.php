{{--
    ปฏิทินประชุมและนัดหมาย — เนื้อหาของมุมมอง data-view="meeting"

    เหตุการณ์ถูกฝังเป็น JSON แล้ว JavaScript เป็นผู้วาดตารางเดือนทั้งหมด (รวมรอบแรก)
    ไม่ใช่ Blade วาดรอบแรกแล้ว JS วาดรอบถัดไป — ตัววาดตารางมีที่เดียวจึงไม่มีทางเพี้ยน
    ต่างกันระหว่างครั้งแรกกับการเปลี่ยนเดือน เป็นแนวทางเดียวกับปฏิทินโปรเจกต์เดิม
    (ดู data-calendar-meetings ใน tasks/partials/calendar.blade.php)

    ข้อมูลทั้งหมดมาจาก MeetingAppointmentCalendarQuery::calendarPageData() แล้ว
    ส่วนนี้ไม่คิวรีและไม่รวมข้อมูลสอง domain เอง
--}}
@php
    /*
     * ขอบเขตของปฏิทินใบนี้ถูกตัดสินมาแล้วฝั่ง server (MeetingCalendarScope) ส่วนนี้แค่
     * ส่งต่อให้ endpoint เปลี่ยนเดือน/ตัวกรอง และฟอร์ม Export อยู่ในขอบเขตเดียวกัน
     * ค่าว่างคือปฏิทินของผู้ใช้เอง ซึ่งเป็นพฤติกรรมของปฏิทินหลัก
     */
    $calendarScopeQuery = $calendarScopeQuery ?? [];
    /*
     * "ดูปฏิทินได้" คนละเรื่องกับ "สร้างได้"
     *
     * ค่าตั้งต้นมาจาก MeetingPolicy ตามเดิม แต่หน้าที่ยืมปฏิทินไปใช้แบบอ่านอย่างเดียว
     * (Member Workspace) ส่งค่า false มาปิดปุ่มสร้างได้ โดยไม่ต้องมีปฏิทินอีกใบ
     * การปิดปุ่มนี้ไม่ใช่การกันสิทธิ์ — สิทธิ์จริงยังถูกบังคับที่ MeetingPolicy::create()
     */
    $calendarCanCreate = $calendarCanCreate ?? auth()->user()?->can('create', App\Models\Meeting::class) ?? false;
    $calendarTypeOptions = [
        \App\Services\MeetingAppointmentCalendarQuery::TYPE_ALL => 'ทั้งหมด',
        \App\Services\MeetingAppointmentCalendarQuery::TYPE_MEETING => 'ประชุม',
        \App\Services\MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT => 'นัดหมาย',
    ];
    // ประกอบ payload ไว้ที่นี่เพราะ @json() ของ Blade รับนิพจน์หลายบรรทัดไม่ได้
    $calendarPayload = [
        'month' => $calendarMonthValue,
        'monthLabel' => $calendarMonthLabel,
        'gridStart' => $calendarGridStart->format('Y-m-d'),
        'gridEnd' => $calendarGridEnd->format('Y-m-d'),
        'today' => $calendarToday->format('Y-m-d'),
        'type' => $calendarType,
        'events' => $calendarEvents,
    ];
    $calendarWeekdayLabels = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
@endphp
<section class="meeting-calendar" data-meeting-calendar
    data-calendar-events-endpoint="{{ route('meeting-calendar.events', $calendarScopeQuery) }}"
    data-calendar-month="{{ $calendarMonthValue }}" data-calendar-type="{{ $calendarType }}"
    data-calendar-today="{{ $calendarToday->format('Y-m-d') }}">

    <header class="meeting-calendar__head">
        {{--
            หัวเรื่องบอกแผนกเจ้าของปฏิทิน เพราะขอบเขตของหน้านี้คือแผนก ไม่ใช่ของคนเดียว
            (ดู MeetingQueryService::calendarScopedQuery)

            ผู้ใช้ที่ยังไม่ถูกจัดแผนกไม่มีปฏิทินแผนกให้ดู จึงต้องไม่ขึ้นหัวเรื่องว่า
            "แผนก" เปล่า ๆ แล้วทำให้เข้าใจผิดว่ากำลังเห็นของทั้งแผนกอยู่
        --}}
        <div>
            @if ($calendarDepartmentName)
                <h2>ปฏิทินประชุมและนัดหมาย แผนก{{ $calendarDepartmentName }}</h2>
                <p>การประชุมและนัดหมายทั้งหมดของแผนก{{ $calendarDepartmentName }}</p>
            @else
                <h2>ปฏิทินประชุมและนัดหมาย</h2>
                <p>การประชุมและนัดหมายที่คุณจัดหรือได้รับเชิญ — บัญชีนี้ยังไม่ถูกจัดเข้าแผนก</p>
            @endif
        </div>
        <div class="meeting-calendar__head-actions">
            <button class="meetings-page__button" type="button" data-meeting-modal-trigger="calendarExportModal"
                aria-controls="calendarExportModal" aria-haspopup="dialog">
                <i class="bi bi-filetype-pdf" aria-hidden="true"></i> Export PDF
            </button>
            @if ($calendarCanCreate)
                <button class="meetings-page__button meetings-page__button--primary" type="button"
                    data-meeting-modal-trigger="calendarCreateTypeModal" aria-controls="calendarCreateTypeModal"
                    aria-haspopup="dialog">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่ม
                </button>
            @endif
        </div>
    </header>

    <div class="meeting-calendar__controls">
        <div class="meeting-calendar__types" role="group" aria-label="กรองปฏิทินตามประเภท">
            @foreach ($calendarTypeOptions as $typeValue => $typeLabel)
                <button type="button" data-calendar-type-filter="{{ $typeValue }}"
                    class="{{ $calendarType === $typeValue ? 'is-active' : '' }}"
                    aria-pressed="{{ $calendarType === $typeValue ? 'true' : 'false' }}">{{ $typeLabel }}</button>
            @endforeach
        </div>

        <div class="meeting-calendar__month-nav">
            <button type="button" class="meeting-calendar__month-step" data-calendar-month-step="previous"
                aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <strong data-calendar-month-label aria-live="polite">{{ $calendarMonthLabel }}</strong>
            <button type="button" class="meeting-calendar__month-step" data-calendar-month-step="next"
                aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            <button type="button" class="meetings-page__button" data-calendar-today-step>วันนี้</button>
        </div>
    </div>

    {{--
        แถวชื่อวันกับตารางวันอยู่ในแผงเดียวกัน ไม่ใช่สองก้อนที่ลอยห่างกัน

        ของเดิมทั้งสองเป็นตารางคนละชุดที่มีช่องไฟของตัวเอง และทุกช่องวันเป็นการ์ด
        ขอบมนแยกใบ 42 ใบ ผลคืออ่านเป็น "กองการ์ด" ไม่ใช่ปฏิทินหนึ่งผืน
        แผงนี้ให้ขอบกับมุมโค้งครั้งเดียวที่รอบนอก แล้วใช้เส้นแบ่งบางข้างในแทน
    --}}
    <div class="meeting-calendar__board">
        <div class="meeting-calendar__weekdays" aria-hidden="true">
            @foreach ($calendarWeekdayLabels as $weekdayLabel)
                <span>{{ $weekdayLabel }}</span>
            @endforeach
        </div>

        {{-- JavaScript เติมช่องวันที่ลงในนี้ ข้อความนี้คือสิ่งที่เห็นเมื่อสคริปต์ยังไม่ทำงาน --}}
        <div class="meeting-calendar__grid" data-calendar-grid role="grid"
            aria-label="ปฏิทินประชุมและนัดหมาย {{ $calendarMonthLabel }}">
            <p class="meeting-calendar__loading">กำลังโหลดปฏิทิน…</p>
        </div>
    </div>

    {{--
        คำอธิบายสี — ชิปบอกประเภทด้วยไอคอนและข้อความอยู่แล้ว แต่เมื่อกวาดตา
        ทั้งเดือน ผู้ใช้อ่านจากสีก่อนข้อความ จึงต้องมีที่บอกว่าสีไหนคืออะไร
        aria-hidden เพราะเป็นข้อมูลซ้ำกับ aria-label ของชิปแต่ละใบ
    --}}
    <ul class="meeting-calendar__legend" aria-hidden="true">
        @foreach ($calendarTypeOptions as $typeValue => $typeLabel)
            @continue($typeValue === \App\Services\MeetingAppointmentCalendarQuery::TYPE_ALL)
            <li class="meeting-calendar__legend-item meeting-calendar__legend-item--{{ $typeValue }}">
                <span class="meeting-calendar__legend-swatch"></span>{{ $typeLabel }}
            </li>
        @endforeach
    </ul>

    {{--
        คง JSON_HEX_* ไว้เพื่อไม่ให้ชื่อประชุม/นัดหมายหลุดออกจาก <script> ได้
        JSON_UNESCAPED_SLASHES ปลอดภัยเพราะ JSON_HEX_TAG แปลง < > เป็น </> อยู่แล้ว
        จึงปิด </script> ไม่ได้ และทำให้ URL ปลายทางของแต่ละรายการอ่านออกตรง ๆ
    --}}
    <script type="application/json" data-calendar-payload>@json($calendarPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
</section>

@if ($calendarCanCreate)
    @include('meetings.components.calendar-create-type-modal')
@endif
@include('meetings.components.calendar-day-events-modal')
@include('meetings.components.calendar-export-modal', [
    'calendarType' => $calendarType,
    'calendarScopeQuery' => $calendarScopeQuery,
])
