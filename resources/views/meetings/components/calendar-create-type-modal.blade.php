{{--
    เลือกประเภทก่อนสร้าง — กด "+ เพิ่ม" แล้วต้องเลือกว่าจะสร้างประชุมหรือนัดหมาย
    ไม่เปิดฟอร์มประชุมทันที เพราะสองอย่างนี้เป็นคนละ domain

    การ์ดสองใบใช้รูปทรงเดียวกับตัวเลือกประเภทงานของบันทึกประจำวัน (log-modal__type-grid)
    แต่ไม่ import CSS ชุดนั้นมาทั้งก้อน เพราะ log-modal มีระบบ overlay/backdrop ของตัวเอง
    (modal-stack) ส่วนหน้าประชุมใช้ modal ของ Bootstrap การนำมาซ้อนกันจะทำให้มีเจ้าของ
    สถานะเปิด/ปิดสองคนในหน้าเดียว ซึ่ง CLAUDE.md ห้ามไว้ — จึงยืมเฉพาะรูปทรงการ์ด

    ปุ่ม "ประชุม" สลับไปเปิด createMeetingModal ของเดิมทั้งใบ ไม่สร้างฟอร์มประชุมใหม่
--}}
<div class="modal fade meeting-form-modal meeting-calendar-type-modal" id="calendarCreateTypeModal" tabindex="-1"
    aria-labelledby="calendarCreateTypeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">CALENDAR</span>
                    <h2 class="modal-title" id="calendarCreateTypeModalTitle">เลือกประเภทที่ต้องการเพิ่ม</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="meeting-calendar__type-grid">
                    <button type="button" data-calendar-create-kind="meeting"
                        data-calendar-create-target="createMeetingModal">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        <strong>ประชุม</strong>
                        <small>สร้างประชุมและเชิญผู้เข้าร่วม</small>
                    </button>
                    <button type="button" data-calendar-create-kind="appointment"
                        data-calendar-create-target="createAppointmentModal">
                        <i class="bi bi-bookmark-star" aria-hidden="true"></i>
                        <strong>นัดหมาย</strong>
                        <small>บันทึกวัน เวลา และรายละเอียดนัดหมาย</small>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
