import {modalStack} from './modal-stack.js';

/*
 * กล่องรายละเอียดหลักฐานผลงานของงานหนึ่งใบ — หน้า "ดูรายละเอียดทั้งหมด" ของรายงานโปรเจกต์
 *
 * แถวในตารางเหลือเพียงป้ายจำนวนงานย่อยและไอคอนนับไฟล์ ส่วนรายชื่องานย่อยและไฟล์เต็ม
 * ถูก render ฝั่ง server ไว้ใน <template data-evidence-template="{job_id}"> ของแต่ละแถว
 * (inert ไม่แสดง ไม่โหลดอะไร และค่าผ่านการ escape ของ Blade แล้ว) ไฟล์นี้แค่คัดลอก
 * template ของแถวที่กดใส่กล่อง จึงไม่มีการประกอบ HTML จากข้อความใน JavaScript
 *
 * backdrop การล็อก body การจับโฟกัส Escape และการคืนโฟกัสเป็นหน้าที่ของ modal-stack
 */

/** เติมเนื้อหาของงานที่กดลงในกล่อง คืน false เมื่อไม่พบ template ของงานนั้น */
export function fillEvidenceModal(modal, opener, root = document) {
    const id = opener.dataset.evidenceOpen || '';
    const template = [...root.querySelectorAll('template[data-evidence-template]')]
        .find((candidate) => candidate.dataset.evidenceTemplate === id);
    const content = modal.querySelector('[data-evidence-modal-content]');

    if (!template || !content) return false;

    content.replaceChildren(template.content.cloneNode(true));

    return true;
}

export function initEvidenceModal(root = document, stack = modalStack(root)) {
    const modal = root.querySelector('[data-evidence-modal]');

    if (!modal || modal.dataset.evidenceModalReady === 'true') {
        return null;
    }

    modal.dataset.evidenceModalReady = 'true';

    const close = () => stack.close(modal);

    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-evidence-open]');

        if (opener) {
            if (fillEvidenceModal(modal, opener, root)) stack.open(modal, opener);

            return;
        }

        // คลิกบนฉากหลัง (ตัวกล่องเอง ไม่ใช่แผงข้างใน) ถือเป็นการปิด
        if (event.target.closest('[data-evidence-modal-close]') || event.target === modal) {
            close();
        }
    });

    modal.addEventListener('modalstack:dismiss', close);

    return {modal, close};
}
