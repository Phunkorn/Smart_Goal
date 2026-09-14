import {modalStack} from '../../components/modal-stack.js';

/*
 * กล่องรายละเอียดของการ์ดพนักงานบนกระดาน "วันนี้ใครทำอะไรไปแล้วบ้าง"
 *
 * การ์ดต้องเตี้ยพอให้เทียบกันได้ทั้งกริด จึงแสดงงานของวันนี้แค่ไม่กี่บรรทัด
 * รายละเอียดเต็มอยู่ในกล่องนี้ ซึ่งเนื้อในถูก render มาจาก Blade พร้อมการ์ดแต่ละใบ
 * (แท็ก template ในการ์ด) ไฟล์นี้จึงย้าย DOM เข้ามาวาง ไม่ได้สร้างขึ้นใหม่เอง
 * ตัวเลข สี และการจัดรูปแบบจะได้มาจากที่เดียวกับการ์ดเสมอ
 *
 * ลำดับชั้น backdrop การล็อก body การจับโฟกัส และ Escape เป็นหน้าที่ของ modal-stack
 * ซึ่งเป็นเจ้าของสถานะ overlay เพียงเจ้าเดียวของระบบ ไฟล์นี้จึงไม่แตะ z-index
 * ไม่เพิ่ม class ที่ body และไม่ผูก Escape เอง
 *
 * ใช้ event delegation ที่ root เพราะการ์ดถูกซ่อน/แสดงใหม่ตลอดเวลาจากการแบ่งหน้า
 */

/** เติมเนื้อในกล่องจากการ์ดที่กด — แยกออกมาให้ทดสอบได้โดยไม่ต้องเปิดกล่องจริง */
export function fillPeopleModal(modal, card) {
    modal.querySelector('[data-people-modal-name]').textContent = card.dataset.peopleName || '';
    modal.querySelector('[data-people-modal-department]').textContent = card.dataset.peopleDepartment || '';

    const link = modal.querySelector('[data-people-modal-link]');
    if (link) {
        // ไม่มีลิงก์ปลายทางก็ต้องไม่เหลือปุ่มที่กดแล้วไปไหนไม่ได้
        const url = card.dataset.peopleUrl || '';
        link.hidden = ! url;
        if (url) link.setAttribute('href', url);
    }

    const body = modal.querySelector('[data-people-modal-body]');
    const template = card.querySelector('[data-people-detail]');
    body.textContent = '';
    if (template) body.append(template.content.cloneNode(true));

    return body;
}

export function initPeopleModal(root = document, stack = modalStack(root.ownerDocument || root)) {
    const modal = root.querySelector('[data-people-modal]');

    if (! modal || modal.dataset.peopleModalReady === 'true') {
        return null;
    }

    modal.dataset.peopleModalReady = 'true';

    const close = () => stack.close(modal);

    root.addEventListener('click', (event) => {
        /*
         * กดที่ไหนก็ได้บนการ์ดยกเว้นตัวกดอื่นในนั้น
         *
         * ชื่อคนเป็นลิงก์ไปหน้ารายวันอยู่แล้ว ถ้าดักคลิกทั้งการ์ดโดยไม่ยกเว้น
         * การกดชื่อจะเปิดกล่องแทนที่จะพาไปหน้านั้น ซึ่งไม่ใช่สิ่งที่คนกดตั้งใจ
         */
        const opener = event.target.closest('[data-people-open]');
        const card = opener ? opener.closest('[data-people-card]') : null;
        const cardBody = ! opener && ! event.target.closest('a, button, summary, input, select, textarea')
            ? event.target.closest('[data-people-card]')
            : null;
        const source = card || cardBody;

        if (source) {
            fillPeopleModal(modal, source);
            stack.open(modal, opener || source);

            return;
        }

        // คลิกบนฉากหลัง (ตัวกล่องเอง ไม่ใช่แผงข้างใน) ถือเป็นการปิด
        if (event.target.closest('[data-people-modal-close]') || event.target === modal) {
            close();
        }
    });

    // modal-stack เป็นผู้ตัดสินว่า Escape ตกที่ชั้นไหน แล้วส่งสัญญาณมาให้ปิด
    modal.addEventListener('modalstack:dismiss', close);

    return {modal, close};
}
