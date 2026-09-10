import {modalStack} from './modal-stack.js';

/*
 * กล่องรายชื่องานย่อยของตารางรายงาน
 *
 * ตารางเดิมพิมพ์ชื่องานย่อยทุกใบลงในช่อง งานที่มีงานย่อย 7 ใบจึงดันความสูงของแถว
 * จนกวาดสายตาตามคอลัมน์ไม่ได้ ช่องนั้นเหลือเป็นปุ่มบอกจำนวน ส่วนรายชื่อเต็มมาอยู่ที่นี่
 *
 * ลำดับชั้น backdrop การล็อก body การจับโฟกัส และ Escape เป็นหน้าที่ของ modal-stack
 * ซึ่งเป็นเจ้าของสถานะ overlay เพียงเจ้าเดียวของระบบ ไฟล์นี้จึงไม่แตะ z-index
 * ไม่เพิ่ม class ที่ body และไม่ผูก Escape เอง
 *
 * ใช้ event delegation ที่ document เพราะแถวในตารางถูกซ่อน/แสดงใหม่ตลอดเวลาจากการแบ่งหน้า
 */

/** อ่านรายชื่องานย่อยจากปุ่ม — คืนอาร์เรย์ว่างเมื่อข้อมูลเสียหาย แทนที่จะโยน error ใส่หน้า */
export function readSubtasks(button) {
    try {
        const parsed = JSON.parse(button.dataset.subtaskNames || '[]');

        return Array.isArray(parsed) ? parsed.filter((name) => typeof name === 'string') : [];
    } catch {
        return [];
    }
}

/** เติมเนื้อในกล่องจากปุ่มที่กด — แยกออกมาให้ทดสอบได้โดยไม่ต้องเปิดกล่องจริง */
export function fillModal(modal, button) {
    const names = readSubtasks(button);

    modal.querySelector('[data-subtask-modal-project]').textContent = button.dataset.subtaskProject || '';
    modal.querySelector('[data-subtask-modal-task]').textContent = button.dataset.subtaskTask || '';
    modal.querySelector('[data-subtask-modal-count]').textContent = `งานย่อยทั้งหมด ${names.length} รายการ`;

    const list = modal.querySelector('[data-subtask-modal-list]');
    list.textContent = '';
    names.forEach((name) => {
        const item = document.createElement('li');
        item.textContent = name;
        list.append(item);
    });

    return names;
}

export function initSubtaskModal(root = document, stack = modalStack(root)) {
    const modal = root.querySelector('[data-subtask-modal]');

    if (! modal || modal.dataset.subtaskModalReady === 'true') {
        return null;
    }

    modal.dataset.subtaskModalReady = 'true';

    const close = () => stack.close(modal);

    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-subtask-open]');

        if (opener) {
            fillModal(modal, opener);
            stack.open(modal, opener);

            return;
        }

        // คลิกบนฉากหลัง (ตัวกล่องเอง ไม่ใช่แผงข้างใน) ถือเป็นการปิด
        if (event.target.closest('[data-subtask-modal-close]') || event.target === modal) {
            close();
        }
    });

    // modal-stack เป็นผู้ตัดสินว่า Escape ตกที่ชั้นไหน แล้วส่งสัญญาณมาให้ปิด
    modal.addEventListener('modalstack:dismiss', close);

    return {modal, close};
}

if (typeof document !== 'undefined') {
    initSubtaskModal(document);
}
