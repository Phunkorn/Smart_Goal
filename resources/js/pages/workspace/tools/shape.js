/*
 * รูปทรง - สี่เหลี่ยม วงกลม เส้นตรง และลูกศร
 *
 * ทั้งสี่ใช้ท่าทางเดียวกันคือลากจากมุมหนึ่งไปอีกมุมหนึ่ง จึงใช้ตัวสร้างเดียวกัน
 * ต่างกันแค่ชนิดที่บันทึกลงไป การแยกเป็นสี่ไฟล์จะกลายเป็นโค้ดชุดเดียวกันสี่ชุด
 * ที่ต้องแก้พร้อมกันทุกครั้ง
 *
 * ข้อต่างที่แท้จริงมีข้อเดียว เส้นตรงกับลูกศรจำทิศทางจริง (w และ h ติดลบได้)
 * เพราะหัวลูกศรต้องรู้ว่าปลายอยู่ข้างไหน ส่วนสี่เหลี่ยมกับวงกลมทำให้กรอบเป็นบวก
 * ได้เพราะรูปร่างเหมือนกันทุกทิศ
 *
 * กด Shift ค้างระหว่างลากสี่เหลี่ยมหรือวงรี กรอบจะถูกล็อกให้เป็นจัตุรัสพอดี
 * ได้สี่เหลี่ยมจัตุรัสกับวงกลมกลม เหมือน Canva ส่วนการลากเส้นตรงหรือลูกศร ปลายเส้นจะถูกล็อกไว้ที่แนวนอน แนวตั้ง
 * หรือแนวทแยง 45 องศา ค่า constrain มาจาก pointer.js ทุกครั้งที่ขยับหรือกด/ปล่อย
 * Shift จึงล็อกและปลดได้กลางท่าลากโดยไม่ต้องเริ่มใหม่
 */

import {boundsFromPoints, constrainToAngle, constrainToSquare} from '../geometry.js';

/** ขนาดต่ำสุดที่นับว่าผู้ใช้ตั้งใจสร้างรูปทรง ไม่ใช่แค่คลิกพลาด (พิกเซลบนหน้าจอ) */
const MIN_DRAG_PX = 4;

const isDirectional = (type) => type === 'line' || type === 'arrow';

export const shapeTool = (type) => ({
    name: type,
    cursor: 'crosshair',

    onPointerDown({point, style}) {
        return {
            draft: {origin: point, stroke: style.stroke, strokeWidth: style.strokeWidth, fill: 'none'},
            selection: [],
        };
    },

    onPointerMove({point, draft, constrain}) {
        if (! draft) {
            return undefined;
        }

        return {preview: shapeOf(type, draft, endPointFor(type, draft, point, constrain))};
    },

    onPointerUp({point, draft, constrain, camera, scene, idFactory, addElement}) {
        if (! draft) {
            return {draft: null};
        }

        const shape = shapeOf(type, draft, endPointFor(type, draft, point, constrain));
        const threshold = MIN_DRAG_PX / camera.scale;

        // คลิกเปล่าโดยไม่ลากต้องไม่ทิ้งรูปทรงขนาดศูนย์ไว้บนกระดาน ซึ่งมองไม่เห็น
        // แต่ยังกินที่และคลิกโดนได้
        if (Math.abs(shape.w) < threshold && Math.abs(shape.h) < threshold) {
            return {draft: null, preview: null};
        }

        const element = {...shape, id: idFactory()};

        return {
            scene: addElement(scene, element),
            selection: [element.id],
            draft: null,
            preview: null,
            commit: true,
        };
    },
});

/*
 * Shift ระหว่างลาก — ความหมายต่างกันตามชนิดของรูปทรง
 *
 * เส้นกับลูกศรคือการล็อก "มุม" ไว้ที่ละ 45 องศา ส่วนสี่เหลี่ยมกับวงรีคือการล็อก
 * "สัดส่วน" ให้เป็นจัตุรัสกับวงกลม เป็นคนละเรื่องกัน ถ้าใช้ constrainToAngle กับสี่เหลี่ยม
 * ด้วย การลากที่ไม่ได้เฉียงมุม 45 องศาจะยุบรูปให้แบนหรือหายไปเลย แทนที่จะกลายเป็นสี่เหลี่ยมจัตุรัส
 */
const endPointFor = (type, draft, point, constrain) => {
    if (! constrain) {
        return point;
    }

    return isDirectional(type)
        ? constrainToAngle(draft.origin, point)
        : constrainToSquare(draft.origin, point);
};

const shapeOf = (type, draft, point) => {
    const box = isDirectional(type)
        ? {
            x: draft.origin.x,
            y: draft.origin.y,
            w: point.x - draft.origin.x,
            h: point.y - draft.origin.y,
        }
        : boundsFromPoints(draft.origin, point);

    return {
        id: 'preview',
        type,
        z: 0,
        ...box,
        stroke: draft.stroke,
        strokeWidth: draft.strokeWidth,
        fill: draft.fill,
    };
};
