import {modalStack} from '../../components/modal-stack.js';

/*
 * กล่อง "ดูงาน" ของภาพรวมทีม — รายการงานของวันนี้ของพนักงานหนึ่งคน
 *
 * เนื้อหาถูก render มาพร้อมหน้าใน <template data-team-work-detail="{id}"> ของแต่ละแถว
 * ไฟล์นี้แค่คัดลอกเข้ากล่องเดียวที่ใช้ร่วมกันทั้งตาราง
 *
 * backdrop การล็อกการเลื่อน การจับโฟกัส และ Escape เป็นหน้าที่ของ modal-stack ซึ่งเป็น
 * เจ้าของสถานะ overlay เพียงเจ้าเดียวของระบบ ไฟล์นี้จึงไม่แตะ z-index และไม่ผูก Escape เอง
 */

/** เติมเนื้อในกล่องจากปุ่มที่กด — แยกออกมาให้ทดสอบได้โดยไม่ต้องเปิดกล่องจริง */
export function fillTeamWorkModal(modal, opener, root = opener.ownerDocument) {
    const template = root.querySelector(`template[data-team-work-detail="${opener.dataset.teamWorkOpen}"]`);
    const list = modal.querySelector('[data-team-work-modal-list]');

    modal.querySelector('[data-team-work-modal-name]').textContent = opener.dataset.teamWorkName || '';
    modal.querySelector('[data-team-work-modal-department]').textContent = opener.dataset.teamWorkDepartment || '';
    modal.querySelector('[data-team-work-modal-count]').textContent = `งานของวันนี้ ${Number(opener.dataset.teamWorkCount) || 0} รายการ`;

    list.textContent = '';
    if (template) list.append(template.content.cloneNode(true));

    return list;
}

export function initTeamWorkModal(root = document, stack = modalStack(root.ownerDocument ?? root)) {
    const documentRef = root.ownerDocument ?? root;
    const modal = root.querySelector('[data-team-work-modal]');

    if (!modal || modal.dataset.teamWorkModalReady === 'true') {
        return null;
    }

    modal.dataset.teamWorkModalReady = 'true';

    const close = () => stack.close(modal);

    // delegation ที่ root เพราะแถวในตารางมีได้หลายร้อยแถว ไม่ผูกทีละปุ่ม
    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-team-work-open]');

        if (opener) {
            fillTeamWorkModal(modal, opener, documentRef);
            stack.open(modal, opener);

            return;
        }

        // คลิกบนฉากหลัง (ตัวกล่องเอง ไม่ใช่แผงข้างใน) ถือเป็นการปิด
        if (event.target.closest('[data-team-work-close]') || event.target === modal) {
            close();
        }
    });

    modal.addEventListener('modalstack:dismiss', close);

    return {modal, close};
}
