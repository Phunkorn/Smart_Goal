{{--
    กล่องรายละเอียดของพนักงานหนึ่งคน

    การ์ดบนกระดานตอบได้แค่ "วันนี้ไปถึงไหน" เพราะต้องเตี้ยพอให้เทียบกันทั้งกริด
    ส่วนคำถามถัดไป — งานทั้งหมดของวันนี้มีอะไรบ้าง และเวลาทั้งช่วงหมดไปกับงานไหน
    กี่ครั้ง กี่ชั่วโมง — ต้องการพื้นที่มากกว่านั้น จึงมาอยู่ในกล่องนี้แทนการกางในการ์ด
    ซึ่งเคยดันการ์ดใบอื่นในแถวเดียวกันให้ขยับตาม

    เนื้อในถูก render มาจาก Blade พร้อมการ์ดแต่ละใบ (template ในการ์ด) แล้ว JavaScript
    ย้ายเข้ามาวางที่นี่ ตัวเลข สี และการจัดรูปแบบจึงมาจากที่เดียวกับการ์ด ไม่ใช่โค้ด
    สร้าง DOM ชุดที่สองที่ต้องคอยแก้ให้ตรงกันทีหลัง

    z-index, backdrop, การล็อก body, การจับโฟกัส และ Escape เป็นหน้าที่ของ modal-stack
    ทั้งหมด ไฟล์นี้กำหนดแค่โครงสร้าง
--}}
<div class="subtask-modal report-people-modal" data-people-modal hidden role="dialog" aria-modal="true"
    aria-labelledby="report-people-modal-title">
    <div class="subtask-modal__panel">
        <header class="subtask-modal__header">
            <div>
                <p class="subtask-modal__eyebrow" data-people-modal-department></p>
                <h2 class="subtask-modal__title" id="report-people-modal-title" data-people-modal-name></h2>
            </div>
            <button type="button" class="subtask-modal__close" data-people-modal-close aria-label="ปิด">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="subtask-modal__body" data-people-modal-body></div>

        <footer class="report-people-modal__footer">
            <a href="#" data-people-modal-link>
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                ดูงานประจำรายวันของคนนี้
            </a>
        </footer>
    </div>
</div>
