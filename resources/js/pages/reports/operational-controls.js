import {initAutoSubmitFilters} from '../../components/auto-submit-filter.js';

/*
 * ตัวควบคุมของรายงานปฏิบัติงานประจำเดือน
 *
 * 1. ตัวกรองเดือน/พนักงาน — เป็นฟอร์ม GET จริง เปลี่ยนค่าแล้วส่งฟอร์มทันที
 *    ปุ่ม "แสดงผล" ยังอยู่ให้ใช้เมื่อ JavaScript ไม่ทำงาน
 * 2. เมนู Export CSV — popover ที่อยู่ติดปุ่ม ไม่ใช่ modal
 *    ไม่ล็อกการเลื่อนหน้า ปิดเมื่อคลิกข้างนอกหรือกด Escape แล้วคืนโฟกัสให้ปุ่ม
 *
 * ทั้งสองตัวผูกได้ครั้งเดียวต่อ element (ตรวจด้วย data-*-ready) เรียกซ้ำจึงไม่ซ้อน listener
 */
export function initReportFilter(root) {
    // พฤติกรรมเดียวกับตัวกรองของรายงานอื่น — แหล่งเดียวอยู่ที่ components/auto-submit-filter.js
    initAutoSubmitFilters(root, '[data-operational-filter]');
}

export function initCsvMenu(root) {
    const documentRef = root.ownerDocument ?? root;

    root.querySelectorAll('[data-csv-menu]').forEach((menu) => {
        if (menu.dataset.csvMenuReady === '1') return;
        menu.dataset.csvMenuReady = '1';

        const trigger = menu.querySelector('[data-csv-menu-trigger]');
        const panel = menu.querySelector('[data-csv-menu-panel]');
        if (!trigger || !panel) return;

        // listener ของเอกสารมีอยู่เฉพาะตอนเมนูเปิด เจ้าของสถานะเปิด/ปิดมีที่เดียวคือ close()
        const onOutsideClick = (event) => {
            if (!menu.contains(event.target)) close();
        };
        const onKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close({restoreFocus: true});
            }
        };

        function close({restoreFocus = false} = {}) {
            if (panel.hidden) return;
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            documentRef.removeEventListener('click', onOutsideClick, true);
            documentRef.removeEventListener('keydown', onKeydown);
            if (restoreFocus) trigger.focus();
        }

        function open() {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            documentRef.addEventListener('click', onOutsideClick, true);
            documentRef.addEventListener('keydown', onKeydown);
            panel.querySelector('a')?.focus();
        }

        trigger.addEventListener('click', () => {
            if (panel.hidden) open();
            else close({restoreFocus: true});
        });
    });
}
