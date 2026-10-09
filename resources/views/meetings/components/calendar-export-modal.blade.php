{{--
    Export ปฏิทิน — เลือกช่วงวันที่ ดูรายการที่มีสิทธิ์เห็น ติ๊กเฉพาะที่ต้องการ แล้วเปิด
    เอกสารสำหรับสั่งพิมพ์/บันทึกเป็น PDF ในแท็บใหม่
    (ดูเหตุผลที่ไม่เรนเดอร์ไฟล์ PDF ฝั่งเซิร์ฟเวอร์ใน CalendarPdfExportService)

    เป็น GET เพราะผลลัพธ์คือเอกสารสำหรับอ่าน ไม่ได้เปลี่ยนข้อมูลอะไร
    checkbox ชื่อ selected[] จึงถูกต่อเป็น query string ของเอกสารได้ตรง ๆ

    รายการที่โหลดมาแสดงมาจาก endpoint เดียวกับที่ปฏิทินใช้วาดตาราง ซึ่งจำกัดสิทธิ์
    ที่ SQL มาแล้ว และฝั่งเอกสารก็กรองจากชุดที่จำกัดสิทธิ์แล้วอีกชั้น การติ๊กเลือก
    จึงเป็นการ "ตัดออก" ไม่ใช่การขอข้อมูลเพิ่ม — ไม่ใช่การกรองสิทธิ์ที่ UI

    ช่วงวันที่ถูกตรวจทั้งฝั่งฟอร์มและที่ MeetingCalendarController::exportCalendarPdf()
--}}
<div class="modal fade meeting-form-modal" id="calendarExportModal" tabindex="-1"
    aria-labelledby="calendarExportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">CALENDAR</span>
                    <h2 class="modal-title" id="calendarExportModalTitle">Export ประชุมและนัดหมาย</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="GET" action="{{ route('meeting-calendar.export') }}" target="_blank"
                data-calendar-export-form
                data-calendar-export-endpoint="{{ route('meeting-calendar.events', $calendarScopeQuery ?? []) }}">
                <div class="modal-body">
                    {{--
                        ขอบเขตของเอกสารต้องเท่ากับขอบเขตของปฏิทินที่กด Export มา

                        ต้องเป็น input ไม่ใช่ query string ใน action= เพราะฟอร์ม GET
                        ของเบราว์เซอร์ทิ้ง query string ของ action ทั้งก้อนตอน submit
                        แล้วเอกสารจะเงียบ ๆ กลายเป็นปฏิทินของผู้กดเอง

                        ค่านี้ไม่ใช่การกรองสิทธิ์ที่ฟอร์ม — MeetingCalendarController
                        ตรวจสิทธิ์ของแผนกที่ขอมาทุกครั้ง (MeetingCalendarScope::forDepartment)
                    --}}
                    @isset($calendarScopeQuery['department'])
                        <input type="hidden" name="department" value="{{ $calendarScopeQuery['department'] }}">
                    @endisset
                    <fieldset class="meeting-calendar__export-types">
                        <legend class="form-label">ประเภท</legend>
                        @foreach ([\App\Services\MeetingAppointmentCalendarQuery::TYPE_ALL => 'ทั้งหมด', \App\Services\MeetingAppointmentCalendarQuery::TYPE_MEETING => 'ประชุม', \App\Services\MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT => 'นัดหมาย'] as $exportType => $exportLabel)
                            <label class="meeting-calendar__export-type">
                                <input type="radio" name="type" value="{{ $exportType }}"
                                    @checked(($calendarType ?? 'all') === $exportType)>
                                <span>{{ $exportLabel }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label" for="calendarExportFromDate">จากวันที่
                                <span aria-hidden="true">*</span></label>
                            {{-- data-date-picker = ปฏิทินของระบบ ภาษาไทยและปี พ.ศ.
                                 (resources/js/components/date-picker.js) ไม่ใช่ของเบราว์เซอร์ --}}
                            <input class="form-control" type="date" data-date-picker id="calendarExportFromDate"
                                name="from_date" required data-calendar-export-from>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="calendarExportToDate">ถึงวันที่
                                <span aria-hidden="true">*</span></label>
                            <input class="form-control" type="date" data-date-picker id="calendarExportToDate"
                                name="to_date" required data-calendar-export-to>
                        </div>
                    </div>

                    <div class="meeting-calendar__export-list" data-calendar-export-list-box>
                        <div class="meeting-calendar__export-list-head">
                            <strong>รายการที่จะ Export</strong>
                            <span data-calendar-export-count>เลือกวันที่เริ่มต้นและวันที่สิ้นสุด แล้วระบบจะค้นหารายการให้</span>
                        </div>

                        {{--
                            ไม่มีปุ่มสั่งค้นหาโดยตั้งใจ — รายการถูกค้นหาเองทันทีที่ช่วงวันที่ครบ
                            และค้นหาใหม่เมื่อเปลี่ยนวันที่หรือประเภท (ดู scheduleLoad ใน
                            pages/meeting-calendar/index.js) เหลือเฉพาะปุ่มที่ทำสิ่งที่ระบบเดาแทนไม่ได้
                        --}}
                        <div class="meeting-calendar__export-actions">
                            <button type="button" class="meetings-page__button" data-calendar-export-select-all disabled>
                                เลือกทั้งหมด
                            </button>
                            <button type="button" class="meetings-page__button" data-calendar-export-clear disabled>
                                ยกเลิกการเลือก
                            </button>
                        </div>

                        {{-- JavaScript เติมรายการลงในนี้ ข้อความนี้คือสิ่งที่เห็นก่อนเลือกวันที่ --}}
                        <ul class="meeting-calendar__export-items" data-calendar-export-items>
                            <li class="meeting-calendar__export-empty">เลือกวันที่เริ่มต้นและวันที่สิ้นสุดเพื่อค้นหารายการ</li>
                        </ul>
                    </div>

                    <p class="meeting-rsvp__error" data-calendar-export-error hidden></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="meetings-page__button" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="meetings-page__button meetings-page__button--primary">
                        <i class="bi bi-filetype-pdf" aria-hidden="true"></i> Export PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
