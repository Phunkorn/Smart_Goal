/*
 * ยางลบ - ลบทั้งชิ้น ไม่ใช่ลบทีละพิกเซล
 *
 * เนื้อหาบนกระดานเก็บเป็นเวกเตอร์ การลบบางส่วนของเส้นต้องตัดเส้นออกเป็นหลายชิ้น
 * ซึ่งทำให้ประวัติ undo และการบันทึกซับซ้อนขึ้นมากโดยได้ประโยชน์น้อย ทางเลือกนี้
 * ถูกบันทึกไว้ใน WorkspaceDesign ด้วย เพื่อไม่ให้มีคนเข้าใจว่าเป็นของที่ทำไม่เสร็จ
 *
 * การลากผ่านหลายชิ้นนับเป็นก้าว undo เดียว ไม่ใช่ก้าวละชิ้น เพราะผู้ใช้มองว่า
 * "ลากลบทีเดียว" เป็นการกระทำเดียว
 */

import {pickAll} from '../geometry.js';

/** รัศมีหัวยางลบ หน่วยพิกเซลบนหน้าจอ (หารด้วยระดับซูมก่อนใช้) */
const ERASER_RADIUS_PX = 6;

export const eraserTool = {
    name: 'eraser',
    cursor: 'cell',

    onPointerDown(context) {
        return erase(context, {erased: []});
    },

    onPointerMove(context) {
        if (! context.draft) {
            return undefined;
        }

        return erase(context, context.draft);
    },

    onPointerUp({draft}) {
        // บันทึกลงประวัติเฉพาะเมื่อได้ลบอะไรไปจริง การลากผ่านที่ว่างไม่ควรกิน
        // ก้าว undo ไปหนึ่งก้าวโดยไม่มีอะไรให้ย้อน
        return {draft: null, commit: Boolean(draft?.erased?.length)};
    },
};

const erase = ({point, scene, camera, removeElements}, draft) => {
    const hits = pickAll(scene.elements, point, ERASER_RADIUS_PX / camera.scale);

    if (! hits.length) {
        return {draft};
    }

    const ids = hits.map((element) => element.id);

    return {
        scene: removeElements(scene, ids),
        draft: {erased: [...draft.erased, ...ids]},
        selection: [],
    };
};
