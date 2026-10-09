{{--
    กล่องพรีวิวแบบตรวจ — ชุดเดียวใช้ได้ทุกหน้า

    ถูก include ที่สามที่: หน้าตรวจเช็ก (ประวัติการตรวจ), หน้าบันทึกงานประจำวัน
    (ปุ่ม "ดูแบบตรวจ" บนการ์ด) และหน้ารายงานปฏิบัติงาน ปุ่มที่ไหนก็ได้ที่มี
    data-cc-report-open จะเปิดกล่องนี้ ไม่ต้องมีกล่องของตัวเอง

    เนื้อในเป็น <iframe> ที่ชี้ไป route แบบตรวจ ซึ่งเรนเดอร์จากข้อมูลที่บันทึกไว้
    จริง ไม่ใช่ภาพหน้าจอ และผ่าน Gate::authorize('view') ของ session นั้นเสมอ
    src ถูกใส่ตอนเปิดเท่านั้น เพื่อไม่ให้ทุกหน้าที่ include ไฟล์นี้ยิง request
    โหลดแบบตรวจทิ้งไว้ตั้งแต่เปิดหน้า

    การเปิด/ปิด backdrop โฟกัส Escape และการซ้อนชั้น เป็นหน้าที่ของ modal-stack
--}}
<div class="cc-report-modal" id="inspectionReportModal" role="dialog" aria-modal="true"
    aria-labelledby="inspectionReportTitle" data-cc-report-modal hidden>
    <div class="cc-report-modal__panel">
        <header class="cc-report-modal__head">
            <div>
                <h2 class="cc-report-modal__title" id="inspectionReportTitle" data-cc-report-title>
                    แบบตรวจเช็กคอมพิวเตอร์ประจำวัน
                </h2>
                <p class="cc-report-modal__meta" data-cc-report-meta></p>
            </div>
            <button type="button" class="cc-report-modal__close" aria-label="ปิด" data-cc-report-close>
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="cc-report-modal__viewer">
            <iframe class="cc-report-modal__frame" title="แบบตรวจเช็กคอมพิวเตอร์ประจำวัน"
                data-cc-report-frame loading="lazy"></iframe>
        </div>

        <footer class="cc-report-modal__foot">
            <a class="btn btn-outline-secondary" target="_blank" rel="noopener" data-cc-report-open-tab>
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> เปิด PDF
            </a>
            {{--
                ดาวน์โหลดและพิมพ์ใช้ทางเดียวกันคือกล่องพิมพ์ของเบราว์เซอร์
                (เลือก "Save as PDF" เพื่อดาวน์โหลด) ระบบไม่ได้ติดตั้งไลบรารี
                สร้างไฟล์ PDF ฝั่งเซิร์ฟเวอร์ และ docs/UI_DESIGN_SYSTEM.md ระบุไว้
                ว่า PDF ของโปรเจกต์นี้ออกผ่าน print stylesheet
            --}}
            <button type="button" class="btn btn-outline-primary" data-cc-report-download>
                <i class="bi bi-download" aria-hidden="true"></i> ดาวน์โหลด PDF
            </button>
            <button type="button" class="btn btn-primary" data-cc-report-print>
                <i class="bi bi-printer" aria-hidden="true"></i> พิมพ์
            </button>
        </footer>
    </div>
</div>
