{{--
    รายการประชุมและนัดหมายทั้งหมดของวันหนึ่ง — เปิดจากปุ่ม "+X รายการ" ในช่องวันที่

    เปลือกว่างโดยตั้งใจ: หัวเรื่องและรายการถูกเติมจาก JavaScript ด้วย event ชุดเดียว
    กับที่วาดตาราง (ดู fillDayModal ใน pages/meeting-calendar/index.js) จึงไม่มีทาง
    ที่ modal กับช่องวันที่จะแสดงไม่ตรงกัน และการกดดูไม่ยิงคิวรีเพิ่ม

    ไม่มีข้อมูลอะไรถูกฝังไว้ที่นี่ รายการที่เห็นจึงถูกจำกัดสิทธิ์ที่ SQL มาแล้ว
    (MeetingQueryService::calendarScopedQuery และ Appointment::scopeVisibleTo)
--}}
<div class="modal fade meeting-form-modal" id="calendarDayEventsModal" tabindex="-1"
    aria-labelledby="calendarDayEventsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="meetings-page__modal-kicker">CALENDAR</span>
                    <h2 class="modal-title" id="calendarDayEventsModalTitle" data-calendar-day-modal-title></h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <ul class="meeting-calendar__day-modal-list" data-calendar-day-modal-list></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="meetings-page__button" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>
