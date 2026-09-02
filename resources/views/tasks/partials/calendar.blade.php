@php
    /**
     * ปฏิทินรวมงานและการประชุม โดย Member Workspace ใช้สมาชิกที่กำลังตรวจเป็น subject
     * เพื่อไม่ให้ประชุมส่วนตัวของ Admin/หัวหน้าแผนกปนกับข้อมูลของสมาชิกคนนั้น
     */
    $calendarShowsMeetings = isset($calendarMeetings);
    $calendarMeetings = $calendarMeetings ?? collect();
    $calendarMeetingRange = $calendarMeetingRange ?? null;
    $calendarMeetingSubject = $calendarMeetingSubject ?? null;

    /**
     * ปลายทางของลิงก์ "ดูทั้งหมด" ต้องอยู่บนหน้าปัจจุบันเสมอ
     * fullUrlWithQuery จึงพา Admin ไปมุมมองตาราง/ประชุมของ Member Workspace ที่กำลังดูอยู่
     * ไม่ใช่ /my-tasks ของตัวเอง
     */
    $calendarTableUrl = request()->fullUrlWithQuery(['view' => 'table']);

    // ลำดับและป้ายกำกับของโทนสี ต้องตรงกับ CALENDAR_PRIORITY_ORDER ใน calendar-model.js
    $calendarPriorityLegend = [
        'urgent' => 'สำคัญด่วน',
        'quick' => 'ด่วนไม่ค่อยสำคัญ',
        'important' => 'สำคัญไม่ด่วน',
        'flexible' => 'ไม่รีบ ไม่มีกำหนด',
        'routine' => 'routine',
    ];
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
        @if($calendarMeetingSubject)
            data-meetings-subject-user-id="{{ $calendarMeetingSubject->id }}"
        @endif
        @if($calendarMeetingRange)
            data-meetings-loaded-start="{{ $calendarMeetingRange['start'] }}"
            data-meetings-loaded-end="{{ $calendarMeetingRange['end'] }}"
        @endif
    @endif
>
    {{--
        ปฏิทินเป็นการ์ดของตัวเองหนึ่งใบ แยกขาดจากการ์ดสรุปด้านล่าง
        ตัว <section> ชั้นนอกทำหน้าที่เป็น "พื้น" สีเทาอ่อนให้การ์ดทุกใบลอยขึ้นมา
    --}}
    <div class="mytasks-calendar__panel">
        <header class="mytasks-calendar__toolbar">
            {{-- แถบสีอธิบายจำนวนงานในช่องวันที่ ยึด "ความสำคัญของงาน" จึงต้องแสดงทุกบริบท --}}
            <p class="mytasks-calendar__legend">
                @foreach($calendarPriorityLegend as $tone => $label)
                    <span class="mytasks-calendar__legend-item priority-{{ $tone }}"><i aria-hidden="true"></i>{{ $label }}</span>
                @endforeach
                @if($calendarShowsMeetings)
                    <span class="mytasks-calendar__legend-item mytasks-calendar__legend-item--meeting"><i aria-hidden="true"></i>การประชุม</span>
                @endif
            </p>

            <div class="mytasks-calendar__controls">
                <label class="mytasks-calendar__search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span class="visually-hidden">ค้นหางานหรือการประชุมในปฏิทิน</span>
                    <input type="search" data-calendar-search placeholder="ค้นหา" autocomplete="off">
                </label>

                <div class="mytasks-calendar__navigation" aria-label="เปลี่ยนเดือน">
                    <button type="button" class="mytasks-calendar__reset" data-calendar-reset>คืนค่า</button>
                    <button type="button" data-calendar-today>วันนี้</button>
                    <button type="button" data-calendar-previous aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <button type="button" data-calendar-next aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                    <label><span class="visually-hidden">เลือกเดือน</span><select data-calendar-month aria-label="เลือกเดือน">@foreach(['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'] as $index => $month)<option value="{{ $index }}">{{ $month }}</option>@endforeach</select></label>
                    <label><span class="visually-hidden">เลือกปี</span><select data-calendar-year aria-label="เลือกปี"></select></label>
                </div>
            </div>
        </header>

        <div class="mytasks-calendar__heading">
            <h2 id="mytasks-calendar-title" data-calendar-title aria-live="polite"></h2>
            @if($calendarShowsMeetings)
                <span class="mytasks-calendar__loading" data-calendar-loading role="status" aria-live="polite" hidden><i class="bi bi-arrow-repeat" aria-hidden="true"></i> กำลังโหลดการประชุม</span>
            @endif
            <span class="mytasks-calendar__filtered" data-calendar-filtered role="status" aria-live="polite" hidden></span>
        </div>

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
    </div>

    {{--
        การ์ดสรุปใต้ปฏิทินรวมงานและประชุม: วันนี้ | กำหนดส่งและนัดหมายในเดือนที่เลือก
        desktop กว้างเป็นสองคอลัมน์ และยุบเหลือคอลัมน์เดียวก่อนตารางเริ่มเบียด
        หัวตารางเขียนไว้ใน Blade ส่วนแถวถูกวาดโดย calendar.js จากข้อมูลจริงของหน้า
    --}}
    <div class="mytasks-calendar-agenda" data-calendar-agenda>
        <section class="mytasks-calendar-agenda__section" aria-labelledby="mytasks-calendar-today-title">
            <header class="mytasks-calendar-agenda__header">
                <i class="bi bi-list-check" aria-hidden="true"></i>
                <h3 id="mytasks-calendar-today-title">งานและการประชุมวันนี้</h3>
                <b data-calendar-today-count>0 รายการ</b>
            </header>
            <div class="calendar-table calendar-table--today" role="table" aria-labelledby="mytasks-calendar-today-title">
                <div class="calendar-table__head" role="row">
                    <span role="columnheader">งาน / การประชุม</span>
                    <span role="columnheader">โปรเจกต์ / สถานที่</span>
                    <span role="columnheader">ผู้รับผิดชอบ / ผู้จัด</span>
                    <span role="columnheader">ผู้ร่วมงาน / ผู้เข้าร่วม</span>
                    <span role="columnheader">ความสำคัญ</span>
                    <span role="columnheader">เวลา</span>
                </div>
                <div class="calendar-table__body" role="rowgroup" data-calendar-today-list></div>
            </div>
            <p class="mytasks-calendar-agenda__empty" data-calendar-today-empty>
                <i class="bi bi-calendar2-check" aria-hidden="true"></i>
                <span>วันนี้ไม่มีงานที่ครบกำหนดหรือการประชุม</span>
            </p>
            <footer class="mytasks-calendar-agenda__footer">
                <a href="{{ $calendarTableUrl }}">ดูทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </footer>
        </section>

        <section class="mytasks-calendar-agenda__section" aria-labelledby="mytasks-calendar-month-agenda-title">
            <header class="mytasks-calendar-agenda__header">
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                <h3 id="mytasks-calendar-month-agenda-title" data-calendar-month-agenda-title aria-live="polite">กำหนดส่งและนัดหมายในเดือนนี้</h3>
                <b data-calendar-month-count>0 รายการ</b>
            </header>
            <div class="calendar-table calendar-table--due" role="table" aria-labelledby="mytasks-calendar-month-agenda-title">
                <div class="calendar-table__head" role="row">
                    <span role="columnheader">งาน / การประชุม</span>
                    <span role="columnheader">โปรเจกต์ / สถานที่</span>
                    <span role="columnheader">ผู้รับผิดชอบ / ผู้จัด</span>
                    <span role="columnheader">ผู้ร่วมงาน / ผู้เข้าร่วม</span>
                    <span role="columnheader">ความสำคัญ</span>
                    <span role="columnheader">เวลา</span>
                </div>
                <div class="calendar-table__body" role="rowgroup" data-calendar-month-list></div>
            </div>
            <p class="mytasks-calendar-agenda__empty" data-calendar-month-empty>
                <i class="bi bi-calendar2" aria-hidden="true"></i>
                <span>เดือนนี้ยังไม่มีงานที่ครบกำหนดหรือการประชุม</span>
            </p>
            <footer class="mytasks-calendar-agenda__footer">
                <a href="{{ $calendarTableUrl }}">ดูทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </footer>
        </section>

    </div>

    @if($calendarShowsMeetings)
        {{-- ประชุมของช่วงเดือนตั้งต้น ฝังมาเพื่อให้รอบแรกวาดได้ทันทีโดยไม่ต้องรอ fetch --}}
        {{-- คง JSON_HEX_* ตามค่าเริ่มต้นของ @json ไว้ เพื่อไม่ให้ชื่อประชุมหลุดออกจาก <script> ได้ --}}
        <script type="application/json" data-calendar-meetings>@json($calendarMeetings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)</script>
    @endif
</section>

{{--
    รายการของวันที่ถูกคลิกบนปฏิทิน — เป็น modal เต็มรูปแบบ (backdrop, ล็อก body, จัดการ focus)
    จึงต้องเดินผ่าน modalStack เหมือนกล่องอื่นในหน้านี้ ห้ามสลับ hidden เอง
    งานกับการประชุมแยกเป็นคนละ section เพราะคอลัมน์ของสองอย่างนี้ไม่เหมือนกัน
    แถวถูกวาดโดย calendar.js ด้วยตัวสร้างแถวชุดเดียวกับการ์ดใต้ปฏิทิน
--}}
<div class="notion-modal mytasks-calendar-day" data-calendar-day-modal hidden>
    <section class="mytasks-calendar-day__card" role="dialog" aria-modal="true" aria-labelledby="calendar-day-title">
        <header>
            <div>
                <span>CALENDAR DAY</span>
                <h2 id="calendar-day-title" data-calendar-day-title></h2>
            </div>
            <button type="button" data-calendar-day-close aria-label="ปิดรายการของวันนี้"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>

        <div class="mytasks-calendar-day__body">
            <section class="mytasks-calendar-day__group" data-calendar-day-tasks hidden>
                <h3><i class="bi bi-list-check" aria-hidden="true"></i> งาน <b data-calendar-day-task-count></b></h3>
                <div class="calendar-table calendar-table--day-task" role="table">
                    <div class="calendar-table__head" role="row">
                        <span role="columnheader">งาน</span>
                        <span role="columnheader">โปรเจกต์</span>
                        <span role="columnheader">เจ้าของ</span>
                        <span role="columnheader">ผู้ร่วมงาน</span>
                        <span role="columnheader">ความสำคัญ</span>
                        <span role="columnheader">สถานะ</span>
                        <span role="columnheader">วันที่เริ่ม</span>
                        <span role="columnheader">กำหนดส่ง</span>
                    </div>
                    <div class="calendar-table__body" role="rowgroup" data-calendar-day-task-list></div>
                </div>
            </section>

            <section class="mytasks-calendar-day__group" data-calendar-day-meetings hidden>
                <h3><i class="bi bi-calendar-event" aria-hidden="true"></i> การประชุม <b data-calendar-day-meeting-count></b></h3>
                <div class="calendar-table calendar-table--day-meeting" role="table">
                    <div class="calendar-table__head" role="row">
                        <span role="columnheader">การประชุม</span>
                        <span role="columnheader">เวลา</span>
                        <span role="columnheader">ผู้จัด</span>
                        <span role="columnheader">ผู้เข้าร่วม</span>
                        <span role="columnheader">สถานที่</span>
                    </div>
                    <div class="calendar-table__body" role="rowgroup" data-calendar-day-meeting-list></div>
                </div>
            </section>
        </div>

        <footer>
            <small data-calendar-day-count></small>
            <button type="button" class="task-secondary" data-calendar-day-close>ปิด</button>
        </footer>
    </section>
</div>

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
