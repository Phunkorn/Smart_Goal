/*
 * ดินสอ - วาดเส้นอิสระ
 *
 * ระหว่างลาก จุดถูกสะสมไว้ใน draft และแสดงผลผ่านชั้นตัวอย่าง (preview) ไม่ใช่
 * ใส่ลงฉากทีละจุด เพราะถ้าใส่ลงฉากจริงทุกครั้งที่ขยับนิ้ว ประวัติ undo จะเต็มไป
 * ด้วยก้าวย่อยหลายร้อยก้าวของเส้นเดียว และตัวจับเวลาบันทึกอัตโนมัติจะถูกปลุก
 * ตลอดเวลา
 *
 * การลดจุดเกิดตอนปล่อยนิ้วเท่านั้น ระหว่างลากผู้ใช้ต้องเห็นเส้นตามนิ้วแบบเต็ม
 * ความละเอียด ไม่งั้นเส้นจะกระตุก
 */

import {simplifyPoints} from '../simplify.js';

/** ระยะผ่อนผันของการลดจุด หน่วยเป็นพิกเซลบนหน้าจอ (หารด้วยระดับซูมก่อนใช้) */
const SIMPLIFY_TOLERANCE_PX = 0.8;

export const penTool = {
    name: 'pen',
    cursor: 'crosshair',

    onPointerDown({point, style}) {
        return {
            draft: {
                points: [[point.x, point.y]],
                stroke: style.stroke,
                strokeWidth: style.strokeWidth,
            },
            selection: [],
        };
    },

    onPointerMove({point, draft}) {
        if (! draft) {
            return undefined;
        }

        const last = draft.points[draft.points.length - 1];

        // ข้ามจุดที่ซ้ำกับจุดก่อนหน้าพอดี เพราะ pointermove ยิงได้แม้เคอร์เซอร์
        // ไม่ขยับจริง (เช่นตอนกดค้างแล้วสั่น) จุดซ้ำไม่ได้เพิ่มข้อมูลอะไร
        if (last[0] === point.x && last[1] === point.y) {
            return undefined;
        }

        const points = [...draft.points, [point.x, point.y]];

        return {draft: {...draft, points}, preview: strokeOf(draft, points)};
    },

    onPointerUp({draft, camera, scene, idFactory, addElement}) {
        if (! draft) {
            return {draft: null};
        }

        const epsilon = SIMPLIFY_TOLERANCE_PX / camera.scale;
        const points = simplifyPoints(draft.points, epsilon);

        return {
            scene: addElement(scene, {
                ...strokeOf(draft, points),
                id: idFactory(),
            }),
            draft: null,
            preview: null,
            commit: true,
        };
    },
};

/** ชิ้นงานเส้นดินสอจากสถานะร่างปัจจุบัน (ใช้ทั้งตอนแสดงตัวอย่างและตอนบันทึกจริง) */
const strokeOf = (draft, points) => ({
    id: 'preview',
    type: 'pen',
    z: 0,
    stroke: draft.stroke,
    strokeWidth: draft.strokeWidth,
    points,
});
