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
 */

import {boundsFromPoints} from '../geometry.js';

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

    onPointerMove({point, draft}) {
        if (! draft) {
            return undefined;
        }

        return {preview: shapeOf(type, draft, point)};
    },

    onPointerUp({point, draft, camera, scene, idFactory, addElement}) {
        if (! draft) {
            return {draft: null};
        }

        const shape = shapeOf(type, draft, point);
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
