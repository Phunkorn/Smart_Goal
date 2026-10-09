/*
 * กล่องพรีวิวแบบตรวจ — ตัวเดียวใช้ได้ทุกหน้า
 *
 * ปุ่มที่ไหนก็ได้ที่มี data-cc-report-open จะเปิดกล่องนี้ โดยอ่าน URL และหัวเรื่อง
 * จาก data attribute ของปุ่มเอง ทั้งไทม์ไลน์บันทึกงาน ประวัติการตรวจ และ
 * รายงานปฏิบัติงานจึงใช้ทางเดียวกัน ไม่ต้องมีกล่องของตัวเองคนละใบ
 *
 * ใช้ event delegation บน document เพื่อให้ปุ่มที่ถูกวาดใหม่หลังโหลดหน้า
 * (เช่น แถวไทม์ไลน์ที่รีเฟรชจาก AJAX) ยังใช้งานได้โดยไม่ต้อง init ซ้ำ
 *
 * กล่องนี้เป็น modal จริง จึงมอบ backdrop โฟกัส Escape และการซ้อนชั้นให้
 * modal-stack เป็นเจ้าของทั้งหมด ไม่สลับ hidden หรือ z-index เอง
 */
import {modalStack} from './modal-stack.js';

export function initInspectionReportModal({doc = document} = {}) {
    const modal = doc.querySelector('[data-cc-report-modal]');

    if (! modal || modal.dataset.ccReportReady === 'on') return null;

    modal.dataset.ccReportReady = 'on';

    const stack = modalStack(doc);
    const frame = modal.querySelector('[data-cc-report-frame]');
    const title = modal.querySelector('[data-cc-report-title]');
    const meta = modal.querySelector('[data-cc-report-meta]');
    const openTab = modal.querySelector('[data-cc-report-open-tab]');

    /*
     * พิมพ์เฉพาะเนื้อในของ iframe ไม่ใช่ทั้งหน้า
     *
     * iframe ชี้ไปโดเมนเดียวกันเสมอ (route ของแอปเอง) จึงเข้าถึง contentWindow ได้
     * ถ้าเบราว์เซอร์บล็อกด้วยเหตุผลใดก็ตาม ให้ถอยไปเปิดแท็บใหม่แทนการเงียบไปเฉย ๆ
     */
    const printFrame = () => {
        try {
            frame?.contentWindow?.focus();
            frame?.contentWindow?.print();
        } catch {
            if (openTab?.href) globalThis.open?.(openTab.href, '_blank', 'noopener');
        }
    };

    const close = () => {
        stack.close(modal);
        // ล้าง src เพื่อไม่ให้แบบตรวจใบเก่าค้างอยู่เบื้องหลังจนเปิดใบถัดไปแล้วเห็นของเดิมแวบหนึ่ง
        if (frame) frame.removeAttribute('src');
    };

    const open = (trigger) => {
        const url = trigger.dataset.reportUrl;

        if (! url) return;

        // ?embed=1 บอกให้แบบตรวจซ่อนแถบปุ่มของตัวเอง เพราะกล่องนี้มีปุ่มอยู่แล้ว
        const embedded = url.includes('?') ? `${url}&embed=1` : `${url}?embed=1`;

        if (frame) frame.src = embedded;
        if (openTab) openTab.href = url;
        if (title && trigger.dataset.reportTitle) title.textContent = trigger.dataset.reportTitle;
        if (meta) {
            meta.textContent = trigger.dataset.reportMeta || '';
            meta.hidden = ! trigger.dataset.reportMeta;
        }

        stack.open(modal, trigger);
    };

    doc.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-cc-report-open]');

        if (trigger) {
            event.preventDefault();
            open(trigger);

            return;
        }

        if (event.target.closest('[data-cc-report-close]')) close();
        if (event.target.closest('[data-cc-report-print]')) printFrame();
        // ดาวน์โหลดใช้กล่องพิมพ์ของเบราว์เซอร์เช่นกัน (เลือก "Save as PDF")
        // ระบบไม่มีไลบรารีสร้างไฟล์ PDF ฝั่งเซิร์ฟเวอร์ ดู docs/UI_DESIGN_SYSTEM.md
        if (event.target.closest('[data-cc-report-download]')) printFrame();
    });

    // Escape ถูกส่งมาจาก modal-stack เฉพาะตอนที่กล่องนี้เป็นชั้นบนสุด
    modal.addEventListener('modalstack:dismiss', close);

    // คลิกพื้นหลังนอกแผงเพื่อปิด
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });

    return {open, close};
}
