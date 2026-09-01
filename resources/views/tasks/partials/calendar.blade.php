@php
    /**
     * ปฏิทินรวมงานและการประชุม แต่ Admin Member Workspace ดูงานของ "สมาชิกคนอื่น"
     * จึงต้องไม่มีประชุมของผู้ดูแลปนเข้าไป — จำกัดไว้เฉพาะบริบทของเจ้าของหน้าเท่านั้น
     */
    $calendarShowsMeetings = ($workspaceContext ?? 'user') === 'user';
    $calendarMeetings = $calendarShowsMeetings ? ($calendarMeetings ?? collect()) : collect();
    $calendarMeetingRange = $calendarShowsMeetings ? ($calendarMeetingRange ?? null) : null;
@endphp

<section class="mytasks-calendar" id="mytasks-calendar" data-calendar data-view-panel="calendar" role="tabpanel" aria-hidden="true" aria-labelledby="mytasks-calendar-title"
    data-task-quickview-template="{{ route('mytasks.quickview.task', ['id' => '__ID__']) }}"
    {{--
        รายละเอียดเต็มของงานคือ Task Workspace ที่เปิดผ่าน deep link บน "หน้าปัจจุบัน"
        fullUrlWithQuery รักษา query เดิมไว้ (view=calendar ฯลฯ) และแทนค่า open_task เดิมถ้ามีอยู่แล้ว
        ห้ามให้ quick-view endpoint สร้าง URL นี้ เพราะ current URL ของ AJAX คือตัว endpoint เอง
        ไม่ใช่หน้า Calendar ต้นทาง — Admin ที่เปิดจาก Member Workspace จะถูกพาไป /my-tasks ผิดหน้า
    --}}
    data-task-detail-template="{{ request()->fullUrlWithQuery(['open_task' => '__ID__']) }}"
    @if($calendarShowsMeetings)
        data-meetings-endpoint="{{ route('mytasks.calendar.meetings') }}"
        @if($calendarMeetingRange)
            data-meetings-loaded-start="{{ $calendarMeetingRange['start'] }}"
            data-meetings-loaded-end="{{ $calendarMeetingRange['end'] }}"
        @endif
    @endif
>
    <header class="mytasks-calendar__toolbar">
        <div>
            <span>MONTH VIEW</span>
            <h2 id="mytasks-calendar-title" data-calendar-title aria-live="polite"></h2>

            @if($calendarShowsMeetings)
                <p class="mytasks-calendar__legend">
                    <span class="mytasks-calendar__legend-item"><i class="bi bi-check2-square" aria-hidden="true"></i> งาน</span>
                    <span class="mytasks-calendar__legend-item mytasks-calendar__legend-item--meeting"><i class="bi bi-calendar-event-fill" aria-hidden="true"></i> ประชุม</span>
                    <span class="mytasks-calendar__loading" data-calendar-loading role="status" aria-live="polite" hidden><i class="bi bi-arrow-repeat" aria-hidden="true"></i> กำลังโหลดการประชุม</span>
                </p>
            @endif
        </div>
        <div class="mytasks-calendar__navigation" aria-label="เปลี่ยนเดือน">
            <button type="button" class="mytasks-calendar__reset" data-calendar-reset>คืนค่า</button>
            <button type="button" data-calendar-today>วันนี้</button>
            <button type="button" data-calendar-previous aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <button type="button" data-calendar-next aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            <label><span class="visually-hidden">เลือกเดือน</span><select data-calendar-month aria-label="เลือกเดือน">@foreach(['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'] as $index => $month)<option value="{{ $index }}">{{ $month }}</option>@endforeach</select></label>
            <label><span class="visually-hidden">เลือกปี</span><select data-calendar-year aria-label="เลือกปี"></select></label>
        </div>
    </header>

    <div class="mytasks-calendar__viewport">
        <div class="mytasks-calendar__canvas" role="grid" aria-labelledby="mytasks-calendar-title">
            <div class="mytasks-calendar__weekdays" role="row">
                @foreach(['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'] as $day)
                    <span role="columnheader">{{ $day }}</span>
                @endforeach
            </div>
            <div class="mytasks-calendar__grid" data-calendar-grid></div>
        </div>
    </div>

    <div class="mytasks-calendar-agenda" data-calendar-agenda>
        <section class="mytasks-calendar-agenda__section" aria-labelledby="mytasks-calendar-today-title">
            <header class="mytasks-calendar-agenda__header">
                <div>
                    <span>TODAY</span>
                    <h3 id="mytasks-calendar-today-title">งานในวันนี้</h3>
                </div>
                <b data-calendar-today-count>0 งาน</b>
            </header>
            <div class="mytasks-calendar-agenda__list" data-calendar-today-list></div>
            <p class="mytasks-calendar-agenda__empty" data-calendar-today-empty>
                <i class="bi bi-calendar2-check" aria-hidden="true"></i>
                <span>วันนี้ไม่มีงานที่ต้องดำเนินการ</span>
            </p>
        </section>

        <section class="mytasks-calendar-agenda__section" aria-labelledby="mytasks-calendar-month-agenda-title">
            <header class="mytasks-calendar-agenda__header">
                <div>
                    <span>MONTH AGENDA</span>
                    <h3 id="mytasks-calendar-month-agenda-title" data-calendar-month-agenda-title aria-live="polite">รายการของเดือนนี้</h3>
                </div>
                <b data-calendar-month-count>0 รายการ</b>
            </header>
            <div class="mytasks-calendar-agenda__list" data-calendar-month-list></div>
            <p class="mytasks-calendar-agenda__empty" data-calendar-month-empty>
                <i class="bi bi-calendar2" aria-hidden="true"></i>
                <span>เดือนนี้ยังไม่มีรายการ</span>
            </p>
        </section>
    </div>

    <div class="mytasks-calendar__popover" data-calendar-popover role="dialog" aria-modal="false" aria-labelledby="mytasks-calendar-popover-title" hidden>
        <header>
            <strong id="mytasks-calendar-popover-title" data-calendar-popover-title></strong>
            <button type="button" data-calendar-popover-close aria-label="ปิดรายการงาน"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div data-calendar-popover-list></div>
    </div>

    @if($calendarShowsMeetings)
        {{-- ประชุมของช่วงเดือนตั้งต้น ฝังมาเพื่อให้รอบแรกวาดได้ทันทีโดยไม่ต้องรอ fetch --}}
        {{-- คง JSON_HEX_* ตามค่าเริ่มต้นของ @json ไว้ เพื่อไม่ให้ชื่อประชุมหลุดออกจาก <script> ได้ --}}
        <script type="application/json" data-calendar-meetings>@json($calendarMeetings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)</script>
    @endif
</section>

<div class="notion-modal mytasks-calendar-detail" data-calendar-detail hidden>
        <section class="mytasks-calendar-detail__card" role="dialog" aria-modal="true" aria-labelledby="calendar-detail-title">
            <header><div><span>CALENDAR TASK</span><h2 id="calendar-detail-title" data-calendar-detail-title></h2><small data-calendar-detail-project></small></div><button type="button" data-calendar-detail-close aria-label="ปิดข้อมูลงาน"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
            <div class="mytasks-calendar-detail__body">
                <dl class="mytasks-calendar-detail__meta"><div><dt>สถานะ</dt><dd data-calendar-detail-status></dd></div><div><dt>ความสำคัญ</dt><dd data-calendar-detail-priority></dd></div><div><dt>วันที่เริ่ม</dt><dd data-calendar-detail-start></dd></div><div><dt>กำหนดส่ง</dt><dd data-calendar-detail-due></dd></div><div><dt>ผู้รับผิดชอบ</dt><dd data-calendar-detail-assignee></dd></div><div><dt>ผู้ร่วมงาน</dt><dd data-calendar-detail-collaborators></dd></div></dl>
                
                <section><h3>ไฟล์แนบ</h3><div data-calendar-detail-attachments></div></section>
            </div>
            <footer><button type="button" class="task-secondary" data-calendar-detail-close>ปิด</button></footer>
        </section>
    </div>

@include('calendar.quick-view-modal')
