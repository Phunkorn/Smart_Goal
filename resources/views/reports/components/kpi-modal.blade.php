{{--
    กล่องรายละเอียดของการ์ดตัวเลข — ใช้ร่วมกันทั้งรายงานโปรเจกต์และรายงานปฏิบัติงาน มีใบเดียวต่อหน้า

    การ์ดแต่ละใบเป็นปุ่ม (data-kpi-open) ที่บอก URL ของรายการจริงที่การ์ดนับ
    resources/js/components/kpi-detail-modal.js โหลด HTML ชิ้นนั้นจาก server มาใส่ [data-kpi-modal-body]
    เนื้อหาผ่านการ escape ของ Blade แล้ว ไม่มีการประกอบ HTML จากข้อความใน JavaScript

    backdrop การล็อกการเลื่อน การจับโฟกัส Escape และการคืนโฟกัสให้การ์ดเป็นหน้าที่ของ modal-stack
    ไฟล์นี้จึงไม่กำหนด z-index เอง

    @param string $eyebrow  บรรทัดเล็กเหนือชื่อการ์ด เช่น ช่วงเวลาของรายงาน
--}}
<div class="subtask-modal report-kpi-modal" role="dialog" aria-modal="true"
    aria-labelledby="reportKpiModalTitle" data-kpi-modal data-kpi-state="idle" hidden>
    <div class="subtask-modal__panel report-kpi-modal__panel">
        <header class="subtask-modal__header report-kpi-modal__header">
            <div class="report-kpi-modal__heading">
                <p class="subtask-modal__eyebrow">{{ $eyebrow }}</p>
                <h2 class="subtask-modal__title" id="reportKpiModalTitle" data-kpi-modal-title></h2>
            </div>
            <button type="button" class="subtask-modal__close" data-kpi-modal-close aria-label="ปิด">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="subtask-modal__body report-kpi-modal__body" data-kpi-modal-body aria-live="polite" aria-busy="false"></div>
    </div>

    <template data-kpi-modal-loading>
        <div class="report-kpi-detail report-kpi-detail--loading" role="status">
            <span class="visually-hidden">กำลังโหลดรายการ…</span>
            <div class="report-kpi-skeleton" aria-hidden="true">
                <span class="report-kpi-skeleton__value"></span>
                <span class="report-kpi-skeleton__line"></span>
                @for($i = 0; $i < 4; $i++)
                    <span class="report-kpi-skeleton__row"></span>
                @endfor
            </div>
        </div>
    </template>

    <template data-kpi-modal-error>
        <div class="report-kpi-detail report-kpi-detail--error" role="alert">
            <div class="report-empty">
                <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                <strong>โหลดรายการไม่สำเร็จ</strong>
                <span>ตรวจสอบการเชื่อมต่อ หรือรีเฟรชหน้าหากออกจากระบบไปแล้ว</span>
                <button type="button" class="report-kpi-detail__retry" data-kpi-retry>
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> ลองอีกครั้ง
                </button>
            </div>
        </div>
    </template>
</div>
