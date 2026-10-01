/*
 * เมนูคลิกขวาบนผืนผ้าใบ
 *
 * ไฟล์นี้ตอบสองคำถามเท่านั้น "คลิกขวาโดนอะไร" และ "รายการไหนใช้ได้ตอนนี้"
 * ส่วนการเปิด/ปิดแผงเป็นของ menu.js และการทำงานของคำสั่งเป็นของ runCommand
 * ใน index.js ชุดเดียวกับแถบเครื่องมือและปุ่มลัด ที่นี่ไม่มีตรรกะของคำสั่งเลย
 *
 * ป้ายทุกคำมาจาก Blade ตาม WorkspaceDesign ไม่มีข้อความไทยในไฟล์นี้
 *
 * pointer.js ไม่เคยเริ่มท่าลากจากปุ่มขวาอยู่แล้ว (ตรวจ event.button) การคลิกขวา
 * จึงไม่ชนกับการวาด แต่มันก็ไม่เปลี่ยนสิ่งที่เลือกด้วย เมนูนี้จึงต้องเลือกชิ้นงาน
 * ใต้เคอร์เซอร์เองก่อนเปิด ไม่งั้นคำสั่งที่ทำกับ "สิ่งที่เลือก" จะไปทำกับของที่
 * ผู้ใช้เลือกไว้ก่อนหน้า ซึ่งอาจอยู่คนละมุมกระดาน
 */

import {isEditableNode} from './keyboard.js';

/**
 * คำสั่งจัดลำดับชั้น แยกตามทิศที่มันขยับ
 *
 * ใช้ปิดปุ่มที่กดแล้วไม่เกิดอะไร เพราะชิ้นงานที่ไม่ได้ทับกับใครจะไม่มีอะไรเปลี่ยน
 * บนจอเลยเมื่อสลับลำดับชั้น ผู้ใช้ที่กดแล้วไม่เห็นอะไรจะคิดว่าปุ่มพัง
 */
const REORDER_DIRECTION = {
    'bring-to-front': 'forward',
    'bring-forward': 'forward',
    'send-backward': 'backward',
    'send-to-back': 'backward',
};

export const initContextMenu = (stage, menuRoot, {
    menus,
    hitAt,
    getSelection,
    onSelect,
    onCommand,
    canEdit = false,
    hasClipboard = () => false,
    canReorder = () => ({forward: false, backward: false}),
    isGestureActive = () => false,
} = {}) => {
    if (! stage || ! menuRoot || ! menus) {
        return null;
    }

    const items = Array.from(menuRoot.querySelectorAll('[data-needs]'));

    /**
     * เปิด/ปิดรายการตามสถานะปัจจุบัน
     *
     * แยกสองเรื่องออกจากกันโดยตั้งใจ
     *   "ไม่เกี่ยวข้อง" (คลิกขวาที่ว่าง แล้วรายการนั้นทำกับสิ่งที่เลือก) -> ซ่อน
     *     เพราะเมนูที่มีแต่รายการสีเทาหกรายการอ่านยากกว่าเมนูที่มีรายการเดียวที่ใช้ได้
     *   "เกี่ยวข้องแต่ยังใช้ไม่ได้" (วางแต่คลิปบอร์ดว่าง, ผู้ดูอย่างเดียว) -> ปิด
     *     เพราะการหายไปทั้งรายการทำให้ผู้ใช้ไม่รู้ว่าคำสั่งนั้นมีอยู่
     */
    const syncItems = () => {
        const hasSelection = getSelection().length > 0;
        const reorder = hasSelection ? canReorder() : {forward: false, backward: false};

        items.forEach((item) => {
            const needs = item.dataset.needs;
            const relevant = needs !== 'selection' || hasSelection;
            const direction = REORDER_DIRECTION[item.dataset.command];

            item.hidden = ! relevant;
            item.disabled = ! relevant
                || (item.hasAttribute('data-requires-edit') && ! canEdit)
                || (needs === 'clipboard' && ! hasClipboard())
                || (direction !== undefined && ! reorder[direction]);
        });
    };

    const handleContextMenu = (event) => {
        /*
         * ปล่อยให้เมนูของเบราว์เซอร์ขึ้นตามปกติเมื่อคลิกขวาในกล่องข้อความที่กำลัง
         * พิมพ์อยู่ เพราะตรงนั้นผู้ใช้ต้องการตรวจคำสะกด คัดลอก และวางข้อความ
         * ซึ่งเมนูของเราไม่มีให้
         */
        if (isEditableNode(event.target)) {
            return;
        }

        event.preventDefault();

        // กดค้างบนจอสัมผัสระหว่างลากยิง contextmenu ออกมาด้วย ถ้าเปิดเมนูตอนนั้น
        // ท่าลากจะค้างครึ่งทางโดยที่ผู้ใช้ไม่รู้ว่าเกิดอะไรขึ้น
        if (isGestureActive()) {
            return;
        }

        const hit = hitAt(event.clientX, event.clientY);

        // คลิกขวาบนชิ้นที่อยู่ในกลุ่มที่เลือกไว้แล้ว ต้องคงกลุ่มเดิมไว้ ไม่ใช่ยุบ
        // เหลือชิ้นเดียว ไม่งั้นการจัดลำดับชั้นทั้งกลุ่มทำไม่ได้เลย
        if (hit && ! getSelection().includes(hit.id)) {
            onSelect([hit.id]);
        }

        if (! hit && getSelection().length) {
            onSelect([]);
        }

        syncItems();
        menus.openAt(menuRoot, {x: event.clientX, y: event.clientY});
    };

    const handleClick = (event) => {
        const button = event.target.closest('[data-command]');

        if (button && ! button.disabled) {
            onCommand?.(button.dataset.command);
        }
    };

    stage.addEventListener('contextmenu', handleContextMenu);
    menuRoot.addEventListener('click', handleClick);

    return {
        close: () => menus.close(),

        destroy() {
            stage.removeEventListener('contextmenu', handleContextMenu);
            menuRoot.removeEventListener('click', handleClick);
        },
    };
};
