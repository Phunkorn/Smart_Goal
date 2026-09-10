{{--
    กล่องรายชื่องานย่อย ใช้ร่วมกันทั้งหน้ารายงานของฉันและรายงานรายบุคคล

    มีใบเดียวต่อหนึ่งหน้า เนื้อในถูกเติมจากปุ่มที่กด ไม่ได้ render กล่องไว้ทุกแถว

    เป็น modal จริงตามที่ผู้ใช้ขอ: มี backdrop ล็อกการเลื่อนหน้า และ aria-modal
    ลำดับชั้น backdrop การล็อก body การจับโฟกัส และ Escape เป็นหน้าที่ของ
    resources/js/components/modal-stack.js ทั้งหมด ไฟล์นี้จึงไม่กำหนด z-index เอง
--}}
<div class="subtask-modal" id="reportSubtaskModal" role="dialog" aria-modal="true"
    aria-labelledby="reportSubtaskModalTitle" data-subtask-modal hidden>
    <div class="subtask-modal__panel">
        <header class="subtask-modal__header">
            <div>
                <p class="subtask-modal__eyebrow" data-subtask-modal-project></p>
                <h2 class="subtask-modal__title" id="reportSubtaskModalTitle" data-subtask-modal-task></h2>
            </div>
            <button type="button" class="subtask-modal__close" data-subtask-modal-close aria-label="ปิด">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="subtask-modal__body">
            <p class="subtask-modal__count" data-subtask-modal-count></p>
            <ol class="subtask-modal__list" data-subtask-modal-list></ol>
        </div>
    </div>
</div>
