/*
 * หน้ารายการกระดานไอเดีย - สร้าง เปลี่ยนชื่อ สลับการมองเห็น และลบกระดาน
 *
 * ตัวกล่องและคำสั่งอยู่ใน board-settings.js เพราะหน้าวาดใช้ชุดเดียวกัน
 * ไฟล์นี้เหลือแค่การต่อสายเหตุการณ์ของหน้ารายการเท่านั้น
 */

import {
    confirmDeleteBoard,
    createBoardModal,
    readJsonIsland,
} from './board-settings.js';

/**
 * ผูกหน้ารายการกระดาน
 *
 * รับ dependency ที่ไม่บริสุทธิ์ทั้งหมดเป็นตัวเลือก เพื่อให้เทสต์ใน jsdom
 * ป้อน fetch และ Swal ปลอมเข้ามาได้ โดยไม่ต้องแตะ global
 */
export const initBoardList = ({
    root,
    doc = root?.ownerDocument || globalThis.document,
    fetchImpl = globalThis.fetch,
    swal = globalThis.Swal,
    navigate = (url) => { doc.defaultView.location.href = url; },
    reload = () => { doc.defaultView.location.reload(); },
} = {}) => {
    if (! root || root.dataset.workspaceListReady === 'on') {
        return null;
    }

    root.dataset.workspaceListReady = 'on';

    const routes = readJsonIsland(doc, 'workspace-list-routes');

    const modal = createBoardModal(root, {
        doc,
        fetchImpl,
        onSaved: (mode, payload) => {
            // การสร้างพาไปที่กระดานใหม่ทันที เพราะคนกดสร้างตั้งใจจะไปวาดต่อ
            // ส่วนการแก้ไขโหลดหน้าเดิมซ้ำ เพื่อให้การ์ดทุกใบสะท้อนค่าใหม่โดยไม่
            // ต้องมีตัวเรนเดอร์การ์ดฝั่ง JavaScript อีกชุดหนึ่ง
            if (mode === 'create' && payload.redirect) {
                navigate(payload.redirect);

                return;
            }

            reload();
        },
    });

    // ใช้ event delegation ที่ระดับหน้า เพื่อให้การ์ดที่ถูกเพิ่มภายหลังทำงานได้
    // โดยไม่ต้องผูก listener ใหม่
    root.addEventListener('click', async (event) => {
        const createButton = event.target.closest('[data-workspace-create]');

        if (createButton && modal) {
            modal.open({
                mode: 'create',
                action: routes.store,
                departmentId: createButton.dataset.departmentId,
                trigger: createButton,
            });

            return;
        }

        const settingsButton = event.target.closest('[data-workspace-settings]');

        if (settingsButton && modal) {
            modal.open({
                mode: 'edit',
                action: settingsButton.dataset.updateUrl,
                title: settingsButton.dataset.boardTitle,
                visibility: settingsButton.dataset.boardVisibility,
                trigger: settingsButton,
            });

            return;
        }

        const deleteButton = event.target.closest('[data-workspace-delete]');

        if (deleteButton) {
            const deleted = await confirmDeleteBoard({
                title: deleteButton.dataset.boardTitle,
                deleteUrl: deleteButton.dataset.deleteUrl,
            }, {fetchImpl, doc, swal});

            if (deleted) {
                reload();
            }
        }
    });

    return {modal};
};

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        initBoardList({root: document.querySelector('[data-workspace-list]')});
    });
}
